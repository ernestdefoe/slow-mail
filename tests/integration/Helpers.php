<?php

namespace ErnestDefoe\SlowMail\Tests\integration;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;

/** Shared set-up: members, Member Map places, sending and reading through the real API. */
trait Helpers
{
    protected const MARKER = 'OTTER-5521-between-us';

    /** Members 2 (Lena, Lyon), 3 (Theo, Nashville), 4 (Mira, no place), 5 (unconfirmed). */
    protected function members(bool $map = true): void
    {
        $this->prepareDatabase(array_filter([
            User::class => [
                ['id' => 2, 'username' => 'lena', 'email' => 'lena@machine.local', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'theo', 'email' => 'theo@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'mira', 'email' => 'mira@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'unconfirmed', 'email' => 'unconfirmed@machine.local', 'is_email_confirmed' => 0],
            ],
            'group_permission' => $map ? [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'member-map.view'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'member-map.list'],
            ] : null,
            'member_map_locations' => $map ? [
                ['user_id' => 2, 'lat' => 45.76, 'lng' => 4.84, 'precision' => 'city', 'label' => 'Lyon, France', 'country_code' => 'fr', 'show_on_profile' => 1, 'updated_at' => Carbon::now()],
                ['user_id' => 3, 'lat' => 36.16, 'lng' => -86.77, 'precision' => 'city', 'label' => 'Nashville, United States', 'country_code' => 'us', 'show_on_profile' => 1, 'updated_at' => Carbon::now()],
            ] : null,
        ]));
    }

    /** @return array{int, array<string, mixed>|null, string} */
    protected function call(string $method, string $path, ?int $as = 2, array $json = []): array
    {
        $request = $this->request($method, $path, array_filter(['authenticatedAs' => $as, 'json' => $json ?: null]));
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $response = $this->send($query ? $request->withQueryParams($query) : $request);
        $body = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($body, true), $body];
    }

    protected function write(int $from, int $to, array $extra = []): array
    {
        return $this->call('POST', '/api/slow-mail/letters', $from, $extra + [
            'to' => $to,
            'body' => "Dear friend,\n".self::MARKER."\nWrite soon.",
            'stamp' => 'lighthouse',
        ]);
    }

    /** Moves time past the letter's arrival and runs the scheduler's delivery. */
    protected function arrive(int $id): void
    {
        $this->database()->table('slow_mail_letters')->where('id', $id)->update(['arrives_at' => Carbon::now()->subMinute()]);
        $this->app()->getContainer()->make(\ErnestDefoe\SlowMail\Mailroom::class)->run();
    }
}
