<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Letters are private between two members: ciphertext in the database, and
 * until it arrives a letter is in no answer the forum gives its recipient,
 * an admin, anyone. After, only its sender and its recipient.
 */
class PrivacyTest extends TestCase
{
    use Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-member-map', 'ernestdefoe-slow-mail');
        $this->members();
    }

    /** Every place a letter could surface, for these viewers. */
    private function assertNowhere(int $id, array $viewers): void
    {
        $paths = ['/api/slow-mail', '/api/slow-mail/letters/'.$id, '/api/slow-mail/pals/2', '/api/slow-mail/pals/3', '/api/users/2', '/api/users/3', '/api/users?include=groups', '/api/forum', '/api/notifications', '/api/discussions', '/api/slow-mail/matches'];

        foreach ($viewers as $as) {
            foreach ($paths as $path) {
                [, , $raw] = $this->call('GET', $path, $as);
                $this->assertStringNotContainsString(self::MARKER, $raw, "$path as ".($as ?? 'guest'));
                $this->assertStringNotContainsString('sm1:', $raw, "ciphertext in $path");
                $this->assertStringNotContainsString('"stamp":"lighthouse"', $raw, "the envelope in $path as ".($as ?? 'guest'));
            }
        }
    }

    #[Test]
    public function the_database_holds_ciphertext_only()
    {
        [$status, $body, $raw] = $this->write(2, 3);

        $this->assertSame(201, $status, $raw);
        $this->assertArrayNotHasKey('body', $body['letter']);
        $row = $this->database()->table('slow_mail_letters')->first();
        $this->assertStringStartsWith('sm1:', $row->body);
        $this->assertStringNotContainsString(self::MARKER, $row->body);
        $this->assertStringNotContainsString(self::MARKER, base64_decode(substr($row->body, 4)));
    }

    #[Test]
    public function a_letter_on_its_way_exists_only_in_its_senders_outbox()
    {
        [, $body] = $this->write(2, 3);
        $id = $body['letter']['id'];

        // The recipient, an admin, another member and a guest: nothing at all, not even the envelope.
        $this->assertNowhere($id, [3, 1, 4, null]);
        foreach ([3, 1, 4] as $as) {
            $this->assertSame(404, $this->call('GET', '/api/slow-mail/letters/'.$id, $as)[0]);
        }
        $this->assertSame(0, $this->database()->table('notifications')->count());

        // The sender sees the envelope with its journey, and may reread their own words.
        [, $page] = $this->call('GET', '/api/slow-mail', 2);
        $this->assertSame('transit', $page['outbox'][0]['status']);
        [$status, $own] = $this->call('GET', '/api/slow-mail/letters/'.$id, 2);
        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::MARKER, $own['letter']['body']);
    }

    #[Test]
    public function once_it_arrives_only_the_recipient_and_sender_can_read_it()
    {
        [, $body] = $this->write(2, 3);
        $id = $body['letter']['id'];
        $this->arrive($id);

        [, $page] = $this->call('GET', '/api/slow-mail', 3);
        $this->assertSame($id, $page['inbox'][0]['id']);
        $this->assertArrayNotHasKey('body', $page['inbox'][0], 'the inbox holds envelopes, not letters');
        $this->assertFalse($page['inbox'][0]['read']);

        [$status, $letter] = $this->call('GET', '/api/slow-mail/letters/'.$id, 3);
        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::MARKER, $letter['letter']['body']);
        $this->assertTrue($letter['letter']['firstRead']);
        $this->assertFalse($this->call('GET', '/api/slow-mail/letters/'.$id, 3)[1]['letter']['firstRead']);

        // Admin, another member, guest: still nothing.
        $this->assertNowhere($id, [1, 4, null]);
        foreach ([1, 4, null] as $as) {
            $this->assertContains($this->call('GET', '/api/slow-mail/letters/'.$id, $as)[0], [401, 404]);
        }

        // The words never ride in a notification, even the recipient's own.
        foreach ([2, 3] as $as) {
            [, , $raw] = $this->call('GET', '/api/notifications', $as);
            $this->assertStringNotContainsString(self::MARKER, $raw);
        }
    }

    #[Test]
    public function the_arrival_notification_comes_on_delivery_and_never_before()
    {
        [, $body] = $this->write(2, 3);
        $id = $body['letter']['id'];

        $this->app()->getContainer()->make(\ErnestDefoe\SlowMail\Mailroom::class)->run();
        $this->assertSame(0, $this->database()->table('notifications')->count(), 'not due yet');

        $this->arrive($id);
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'slowMailArrived')->where('user_id', 3)->where('from_user_id', 2)->count());
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'slowMailDelivered')->where('user_id', 2)->count());
        $this->assertSame(2, $this->database()->table('notifications')->count());

        [, $notes] = $this->call('GET', '/api/notifications', 3);
        $this->assertSame('slowMailArrived', $notes['data'][0]['attributes']['contentType']);
    }

    #[Test]
    public function putting_a_letter_away_hides_it_for_you_only()
    {
        [, $body] = $this->write(2, 3);
        $id = $body['letter']['id'];

        $this->assertSame(422, $this->call('DELETE', '/api/slow-mail/letters/'.$id, 2)[0], 'not while it travels');
        $this->arrive($id);

        $this->assertSame(204, $this->call('DELETE', '/api/slow-mail/letters/'.$id, 3)[0]);
        $this->assertSame(404, $this->call('GET', '/api/slow-mail/letters/'.$id, 3)[0]);
        $this->assertSame(200, $this->call('GET', '/api/slow-mail/letters/'.$id, 2)[0]);

        $this->assertSame(204, $this->call('DELETE', '/api/slow-mail/letters/'.$id, 2)[0]);
        $this->assertSame(0, $this->database()->table('slow_mail_letters')->count(), 'gone once both put it away');
    }
}
