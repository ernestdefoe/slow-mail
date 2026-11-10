<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reporting is the one way a letter's words reach anyone else: its recipient
 * chooses to show that one letter to the moderators.
 */
class ReportTest extends TestCase
{
    use Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-slow-mail');
        $this->members(false);
        // Mira (4) is a moderator here.
        $this->prepareDatabase(['group_user' => [['user_id' => 4, 'group_id' => Group::MODERATOR_ID]]]);
    }

    private function arrived(): int
    {
        [, $body] = $this->write(2, 3);
        $this->arrive($body['letter']['id']);

        return $body['letter']['id'];
    }

    #[Test]
    public function only_the_recipient_can_report_an_arrived_letter()
    {
        [, $transit] = $this->write(2, 3);
        $this->assertSame(404, $this->call('POST', '/api/slow-mail/letters/'.$transit['letter']['id'].'/report', 3)[0], 'not before it arrives');

        $id = $this->arrived();
        foreach ([2, 4, 1] as $as) {
            $this->assertSame(404, $this->call('POST', "/api/slow-mail/letters/$id/report", $as)[0], "user $as");
        }
        $this->assertSame(0, $this->database()->table('slow_mail_reports')->count());
    }

    #[Test]
    public function a_report_shows_that_letter_to_moderators_and_nobody_else()
    {
        $id = $this->arrived();
        [, $other] = $this->write(2, 3, ['body' => 'Not reported: PRIVATE-9911']);
        $this->arrive($other['letter']['id']);

        [$status] = $this->call('POST', "/api/slow-mail/letters/$id/report", 3, ['reason' => 'Unkind', 'block' => true]);
        $this->assertSame(201, $status);
        $this->assertSame(422, $this->call('POST', "/api/slow-mail/letters/$id/report", 3)[0], 'once per letter');

        // Stored encrypted.
        $this->assertStringStartsWith('sm1:', $this->database()->table('slow_mail_reports')->value('body'));

        // Moderators (and admins) see the words of that one letter; nobody else sees the list.
        [$s, $list, $raw] = $this->call('GET', '/api/slow-mail/reports', 4);
        $this->assertSame(200, $s);
        $this->assertStringContainsString(self::MARKER, $list['reports'][0]['body']);
        $this->assertSame('Unkind', $list['reports'][0]['reason']);
        $this->assertStringNotContainsString('PRIVATE-9911', $raw);
        foreach ([2, 3] as $as) {
            $this->assertSame(403, $this->call('GET', '/api/slow-mail/reports', $as)[0]);
        }

        // Moderators get a notification that says nothing of the letter.
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'slowMailReported')->where('user_id', 4)->count());
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'slowMailReported')->where('user_id', 2)->count(), 'never the sender');

        // Blocked as asked: Lena can't write to Theo any more.
        $this->assertSame('slow_mail_not_accepting', $this->write(2, 3)[1]['errors'][0]['code']);

        // Resolved: off the list.
        $this->assertSame(204, $this->call('POST', '/api/slow-mail/reports/'.$list['reports'][0]['id'].'/resolve', 4)[0]);
        $this->assertSame([], $this->call('GET', '/api/slow-mail/reports', 4)[1]['reports']);
        $this->assertSame(403, $this->call('POST', '/api/slow-mail/reports/'.$list['reports'][0]['id'].'/resolve', 2)[0]);
    }
}
