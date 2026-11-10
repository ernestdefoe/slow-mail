<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** Who can write to whom, and how much. */
class RulesTest extends TestCase
{
    use Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-slow-mail');
        $this->setting('slow-mail.new_pals_daily', 1);
        $this->setting('slow-mail.daily_limit', 4);
        $this->members(false);
    }

    private function refused(array $response): void
    {
        $this->assertSame(422, $response[0], $response[2]);
        $this->assertSame('slow_mail_not_accepting', $response[1]['errors'][0]['code']);
    }

    #[Test]
    public function a_block_either_way_stops_letters_with_the_same_answer_as_anything_else()
    {
        $this->call('POST', '/api/slow-mail/blocks', 3, ['user' => 2, 'blocked' => true]);
        $this->refused($this->write(2, 3));
        $this->refused($this->write(3, 2));

        $this->call('POST', '/api/slow-mail/blocks', 3, ['user' => 2, 'blocked' => false]);
        $this->assertSame(201, $this->write(2, 3)[0]);
    }

    #[Test]
    public function letters_turned_off_or_pen_pals_only_are_respected()
    {
        $this->app();
        $theo = User::find(3);
        $theo->setPreference('slowMailAccept', 'nobody');
        $theo->save();
        $this->refused($this->write(2, 3));
        $this->assertFalse($this->call('GET', '/api/slow-mail/quote?to=3', 2)[1]['ok']);

        $theo->setPreference('slowMailAccept', 'pals');
        $theo->save();
        $this->refused($this->write(2, 3));

        // Theo writes to Lena; until it arrives, Lena still can't write (it must not be given away).
        [, $body] = $this->write(3, 2);
        $this->refused($this->write(2, 3));
        $this->arrive($body['letter']['id']);
        $this->assertSame(201, $this->write(2, 3)[0]);
    }

    #[Test]
    public function nobody_writes_to_themselves_the_unconfirmed_or_as_a_guest()
    {
        $this->refused($this->write(2, 2));
        $this->refused($this->write(2, 5));
        $this->refused($this->write(2, 999));
        $this->assertContains($this->write(5, 2)[0], [401, 403]);
        $this->assertContains($this->call('POST', '/api/slow-mail/letters', null, ['to' => 2, 'body' => 'x', 'stamp' => 'owl'])[0], [400, 401, 403]);
        $this->assertSame(0, $this->database()->table('slow_mail_letters')->count());
    }

    #[Test]
    public function bad_letters_are_refused()
    {
        $this->assertSame(422, $this->write(2, 3, ['body' => '   '])[0]);
        $this->assertSame(422, $this->write(2, 3, ['body' => str_repeat('a', 6001)])[0]);
        $this->assertSame(422, $this->write(2, 3, ['stamp' => 'nope'])[0]);
        // A reply must answer a letter that reached you from them.
        $this->assertSame(422, $this->write(2, 3, ['replyTo' => 1234])[0]);
    }

    #[Test]
    public function a_reply_answers_a_letter_that_arrived()
    {
        [, $body] = $this->write(3, 2);
        $id = $body['letter']['id'];
        $this->assertSame(422, $this->write(2, 3, ['replyTo' => $id])[0], 'not before it arrived');
        $this->arrive($id);

        [$status, $reply] = $this->write(2, 3, ['replyTo' => $id]);
        $this->assertSame(201, $status);
        $this->assertSame($id, $reply['letter']['replyTo']);
        $this->assertSame('transit', $reply['letter']['status'], 'a reply takes its own journey');
    }

    #[Test]
    public function an_old_pen_pal_who_never_wrote_back_is_not_a_new_person_today()
    {
        // Lena wrote to Theo two days ago; he never answered. Writing to him again today isn't a "new person".
        [, $old] = $this->write(2, 3);
        $this->database()->table('slow_mail_letters')->where('id', $old['letter']['id'])->update(['sent_at' => Carbon::now()->subDays(2), 'delivered_at' => Carbon::now()->subDay()]);

        $this->assertSame(201, $this->write(2, 3)[0], 'Theo is not new');
        $this->assertSame(201, $this->write(2, 4)[0], 'so Mira can be today\'s one new person');
        $this->assertSame('slow_mail_new_pals_limit', $this->write(2, 1)[1]['errors'][0]['code'] ?? null, 'a second new person is');
    }

    #[Test]
    public function rate_limits_hold()
    {
        $this->assertSame(201, $this->write(2, 3)[0]);
        $over = $this->write(2, 4);
        $this->assertSame(429, $over[0]);
        $this->assertSame('slow_mail_new_pals_limit', $over[1]['errors'][0]['code']);

        // Three to one person at a time, then wait.
        $this->assertSame(201, $this->write(2, 3)[0]);
        $this->assertSame(201, $this->write(2, 3)[0]);
        $this->assertSame('slow_mail_wait_for_delivery', $this->write(2, 3)[1]['errors'][0]['code']);

        // The daily cap (4 here).
        $this->database()->table('slow_mail_letters')->update(['delivered_at' => Carbon::now()]);
        $this->assertSame(201, $this->write(2, 3)[0]);
        $this->assertSame('slow_mail_daily_limit', $this->write(2, 3)[1]['errors'][0]['code']);
    }
}
