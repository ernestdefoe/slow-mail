<?php

namespace ErnestDefoe\SlowMail\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Tests\integration\Helpers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** The JSON:API resources: who may write what, own listings only, and lists that cost the same at any length. */
class ResourceTest extends TestCase
{
    use Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-slow-mail');
        $this->members(false);
    }

    #[Test]
    public function guests_write_nothing()
    {
        $letter = ['data' => ['type' => 'slow-mail-letters', 'attributes' => ['body' => 'x', 'stamp' => 'owl'], 'relationships' => ['recipient' => ['data' => ['type' => 'users', 'id' => '3']]]]];
        // 400 (no CSRF token, as a guest's browser would send) or 401: either way, refused before anything is read.
        $this->assertContains($this->call('POST', '/api/slow-mail-letters', null, $letter)[0], [400, 401]);
        $this->assertContains($this->call('POST', '/api/slow-mail-seekers', null, ['data' => ['type' => 'slow-mail-seekers', 'attributes' => ['region' => 'anywhere']]])[0], [400, 401]);
        $this->assertContains($this->call('POST', '/api/slow-mail-reports', null, ['data' => ['type' => 'slow-mail-reports', 'attributes' => []]])[0], [400, 401]);
        $this->assertContains($this->call('POST', '/api/slow-mail/blocks', null, ['user' => 2])[0], [400, 401]);
        $this->assertSame(401, $this->call('GET', '/api/slow-mail-letters', null)[0]);
        $this->assertSame(0, $this->database()->table('slow_mail_letters')->count());
        $this->assertSame(0, $this->database()->table('slow_mail_seekers')->count());
    }

    #[Test]
    public function a_pen_pal_listing_is_your_own_only()
    {
        [$status, $body] = $this->seek(2, ['interests' => ['Tea', ' hiking ', 'tea'], 'region' => 'far']);
        $this->assertSame(201, $status);
        $this->assertSame('2', $body['data']['id']);
        $this->assertSame(['tea', 'hiking'], $body['data']['attributes']['interests']);
        $this->assertSame('slow_mail_already_listed', $this->seek(2, ['region' => 'anywhere'])[1]['errors'][0]['code']);
        $this->assertSame(422, $this->seek(3, ['region' => 'mars'])[0], 'regions are allow-listed');

        $this->assertSame(200, $this->call('GET', '/api/slow-mail-seekers/2', 2)[0]);
        $this->assertSame(404, $this->call('GET', '/api/slow-mail-seekers/2', 3)[0], 'nobody browses another listing');
        $this->assertSame(404, $this->patch('slow-mail-seekers', 2, 3, ['region' => 'anywhere'])[0]);
        $this->assertSame('country', $this->patch('slow-mail-seekers', 2, 2, ['region' => 'country'])[1]['data']['attributes']['region']);
        $this->assertSame(404, $this->call('DELETE', '/api/slow-mail-seekers/2', 3)[0]);
        $this->assertSame(204, $this->call('DELETE', '/api/slow-mail-seekers/2', 2)[0]);
        $this->assertSame(0, $this->database()->table('slow_mail_seekers')->count());
    }

    #[Test]
    public function the_inbox_costs_the_same_with_one_letter_or_twenty()
    {
        $rows = $users = [];
        foreach (range(10, 29) as $id) {
            $users[] = ['id' => $id, 'username' => "u$id", 'email' => "u$id@machine.local", 'is_email_confirmed' => 1, 'password' => '', 'joined_at' => Carbon::now()];
            $rows[] = ['sender_id' => $id, 'recipient_id' => 3, 'body' => 'sm1:x', 'words' => 1, 'stamp' => 'owl', 'travel_hours' => 24,
                'sent_at' => Carbon::now()->subDays(2), 'arrives_at' => Carbon::now()->subDay(), 'delivered_at' => Carbon::now()->subDay()];
        }
        $this->database()->table('users')->insert($users);

        $count = function () {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            [$status, $body] = $this->call('GET', '/api/slow-mail-letters?filter[box]=in', 3);
            $this->assertSame(200, $status);
            $db->disableQueryLog();

            return [count($body['data']), count($db->getQueryLog())];
        };

        // Each state is asked twice and the second answer counted: a first request also stamps last-seen and
        // fills the permission and arrival caches, which has nothing to do with how many letters there are.
        $this->database()->table('slow_mail_letters')->insert($rows[0]);
        $count();
        [$one, $queriesForOne] = $count();
        $this->database()->table('slow_mail_letters')->insert(array_slice($rows, 1));
        $count();
        [$twenty, $queriesForTwenty] = $count();

        $this->assertSame([1, 20], [$one, $twenty]);
        $this->assertSame($queriesForOne, $queriesForTwenty, 'no query per letter');
    }
}
