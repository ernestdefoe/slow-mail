<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Mailroom;
use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;

/** Delivery is idempotent, and happens on a visit when no scheduler runs. */
class DeliveryTest extends TestCase
{
    use Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-member-map', 'ernestdefoe-slow-mail');
        $this->members();
    }

    #[Test]
    public function delivering_twice_delivers_and_notifies_once()
    {
        [, $body] = $this->write(2, 3);
        $id = $body['letter']['id'];
        $this->database()->table('slow_mail_letters')->where('id', $id)->update(['arrives_at' => Carbon::now()->subMinute()]);

        $mailroom = $this->app()->getContainer()->make(Mailroom::class);
        $this->assertSame(1, $mailroom->run());
        $this->assertSame(0, $mailroom->run());
        $this->assertSame(0, $mailroom->run());

        $this->assertNotNull($this->database()->table('slow_mail_letters')->where('id', $id)->value('notified_at'));
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'slowMailArrived')->count());
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'slowMailDelivered')->count());
    }

    #[Test]
    public function a_visit_delivers_when_no_scheduler_runs()
    {
        [, $body] = $this->write(2, 3);
        $id = $body['letter']['id'];

        // On its way: a visit leaves it alone.
        $this->call('GET', '/api/forum', null);
        $this->assertNull($this->database()->table('slow_mail_letters')->where('id', $id)->value('delivered_at'));

        // Time passes (the cache still says when the next one is due).
        $past = Carbon::now()->subMinute();
        $this->database()->table('slow_mail_letters')->where('id', $id)->update(['arrives_at' => $past]);
        $this->app()->getContainer()->make(Repository::class)->forever(Mailroom::NEXT, (string) $past->getTimestamp());

        // Any visit, a guest's included, delivers it: the recipient's inbox has it.
        [, $page] = $this->call('GET', '/api/slow-mail', 3);
        $this->assertSame($id, $page['inbox'][0]['id'] ?? null);
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'slowMailArrived')->count());
    }

    #[Test]
    public function a_block_after_sending_returns_the_letter()
    {
        [, $body] = $this->write(2, 3);
        $id = $body['letter']['id'];
        $this->call('POST', '/api/slow-mail/blocks', 3, ['user' => 2, 'blocked' => true]);

        $this->arrive($id);

        $row = $this->database()->table('slow_mail_letters')->where('id', $id)->first();
        $this->assertNull($row->delivered_at);
        $this->assertNotNull($row->returned_at);
        $this->assertSame(0, $this->database()->table('notifications')->count());
        $this->assertSame(404, $this->call('GET', '/api/slow-mail/letters/'.$id, 3)[0]);
        $this->assertSame('returned', $this->call('GET', '/api/slow-mail', 2)[1]['outbox'][0]['status']);
    }

    #[Test]
    public function a_sender_suspended_or_without_slow_mail_since_sending_has_the_letter_returned()
    {
        $this->extension('flarum-suspend', 'ernestdefoe-member-map', 'ernestdefoe-slow-mail');
        [, $a] = $this->write(2, 3);
        [, $b] = $this->write(4, 3);

        $this->database()->table('users')->where('id', 2)->update(['suspended_until' => Carbon::now()->addWeek()]);
        // Mira loses Slow Mail: members no longer have the permission, only moderators.
        $this->database()->table('group_permission')->where('permission', 'slow-mail.send')->update(['group_id' => \Flarum\Group\Group::MODERATOR_ID]);
        $this->app()->getContainer()->make(\Flarum\Group\PermissionCache::class)->flush();

        foreach ([$a, $b] as $sent) {
            $this->arrive($sent['letter']['id']);
            $row = $this->database()->table('slow_mail_letters')->where('id', $sent['letter']['id'])->first();
            $this->assertNull($row->delivered_at);
            $this->assertNotNull($row->returned_at);
        }
        $this->assertSame(0, $this->database()->table('notifications')->count());
    }
}
