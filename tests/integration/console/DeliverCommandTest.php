<?php

namespace ErnestDefoe\SlowMail\Tests\integration\console;

use Carbon\Carbon;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** The wiring: the command exists, the scheduler lists it, and it delivers what's due exactly once. */
class DeliverCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-slow-mail');

        $letter = fn (int $id, Carbon $arrives) => ['id' => $id, 'sender_id' => 2, 'recipient_id' => 3, 'body' => 'sm1:x', 'words' => 1, 'stamp' => 'owl', 'travel_hours' => 24, 'sent_at' => Carbon::now()->subDay(), 'arrives_at' => $arrives];
        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'lena', 'email' => 'lena@machine.local', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'theo', 'email' => 'theo@machine.local', 'is_email_confirmed' => 1],
            ],
            'slow_mail_letters' => [
                $letter(1, Carbon::now()->subMinute()),
                $letter(2, Carbon::now()->addHour()),
            ],
        ]);
    }

    #[Test]
    public function it_is_on_the_schedule()
    {
        $this->assertStringContainsString('slow-mail:deliver', $this->runCommand(['command' => 'schedule:list']));
    }

    #[Test]
    public function it_delivers_what_is_due_once()
    {
        $this->assertStringContainsString('Delivered 1', $this->runCommand(['command' => 'slow-mail:deliver']));
        $this->assertSame('', $this->runCommand(['command' => 'slow-mail:deliver']));
        $this->assertNotNull($this->database()->table('slow_mail_letters')->where('id', 1)->value('delivered_at'));
        $this->assertNull($this->database()->table('slow_mail_letters')->where('id', 2)->value('delivered_at'));
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'slowMailArrived')->count());
    }
}
