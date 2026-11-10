<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * How long a letter takes, and what its envelope may say about the way.
 *
 * Privacy, in three layers:
 *
 *  1. The distance is only used when EACH member could see the other on
 *     Member Map (Places::seenBy, both ways). Otherwise the letter takes the
 *     admin's set time. So a travel time can never tell anyone more than the
 *     map already shows them.
 *  2. The distance is rounded to a band as wide as the coarser of the two
 *     members' chosen precisions (city 500 km, region 1,000, country 2,000),
 *     and never below one band.
 *  3. A jitter of up to a whole band either way is added, from a secret
 *     (derived from Vault's key) over the two members and their places. It is
 *     the same every time for the same two places, so sending many letters
 *     can't average it out, and without the key it can't be undone: a given
 *     travel time fits at least two neighbouring bands (the tests sweep it).
 *
 * Arrival is then rounded up to the next whole hour.
 */
class Journey
{
    public const BANDS = ['city' => 500, 'region' => 1000, 'country' => 2000];
    public const JITTER = 1.0;

    public function __construct(
        private readonly Places $places,
        private readonly Vault $vault,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @return array{hours: int, arrives: Carbon, postmark: string|null, origin: string|null, destination: string|null, mapped: bool}
     */
    public function plan(User $sender, User $recipient, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        // What the recipient may know of the sender, and the sender of the recipient.
        $from = $this->places->seenBy($recipient, [$sender->id])[$sender->id] ?? null;
        $to = $this->places->seenBy($sender, [$recipient->id])[$recipient->id] ?? null;

        $speed = $this->speed();
        if ($from && $to) {
            $unit = $this->unit($sender->id, $recipient->id, $from, $to);
            $hours = self::hours(Places::km($from['lat'], $from['lng'], $to['lat'], $to['lng']), $from['precision'], $to['precision'], $unit, $speed);
        } else {
            $unit = $this->unit($sender->id, $recipient->id, null, null);
            $hours = self::fallback($unit, $speed);
        }

        return [
            'hours' => $hours,
            'arrives' => self::arrival($now, $hours),
            'postmark' => $from['postmark'] ?? null,
            'origin' => $from['country'] ?? null,
            'destination' => $to['postmark'] ?? null,
            'mapped' => $from && $to,
        ];
    }

    /**
     * The travel time for a distance, given the jitter unit (-1..1). Pure, so
     * the privacy tests can sweep it.
     *
     * @param array{km_per_day: int, min: int, max: int, default: int} $speed
     */
    public static function hours(float $km, string $precisionA, string $precisionB, float $unit, array $speed): int
    {
        $band = max(self::BANDS[$precisionA] ?? 2000, self::BANDS[$precisionB] ?? 2000);
        $kmPerHour = $speed['km_per_day'] / 24;

        // Never band 0: near neighbours still wait a band's journey, so "very close" can't be read off it.
        $hours = (max(1, round($km / $band)) * $band + $unit * self::JITTER * $band) / $kmPerHour;

        return max($speed['min'], min($speed['max'], (int) ceil($hours)));
    }

    /** @param array{km_per_day: int, min: int, max: int, default: int} $speed */
    public static function fallback(float $unit, array $speed): int
    {
        $hours = $speed['default'] + $unit * max(1, $speed['default'] * 0.25);

        return max($speed['min'], min($speed['max'], (int) ceil($hours)));
    }

    public static function arrival(Carbon $now, int $hours): Carbon
    {
        $at = $now->copy()->addHours($hours);

        return $at->minute || $at->second ? $at->startOfHour()->addHour() : $at->startOfSecond();
    }

    /** @return array{km_per_day: int, min: int, max: int, default: int} */
    public function speed(): array
    {
        $min = Settings::int($this->settings->get(Settings::MIN_HOURS), Settings::DEFAULT_MIN_HOURS, 1, Settings::LONGEST_HOURS);
        $max = Settings::int($this->settings->get(Settings::MAX_HOURS), Settings::DEFAULT_MAX_HOURS, $min, Settings::LONGEST_HOURS);

        return [
            'km_per_day' => Settings::int($this->settings->get(Settings::KM_PER_DAY), Settings::DEFAULT_KM_PER_DAY, 50, 40000),
            'min' => $min,
            'max' => $max,
            'default' => Settings::int($this->settings->get(Settings::DEFAULT_HOURS), Settings::DEFAULT_DEFAULT_HOURS, $min, $max),
        ];
    }

    /**
     * -1..1, from the key, the two members (in either order) and their places.
     *
     * @param array{lat: float, lng: float}|null $a
     * @param array{lat: float, lng: float}|null $b
     */
    private function unit(int $sender, int $recipient, ?array $a, ?array $b): float
    {
        $points = [$sender => $a, $recipient => $b];
        ksort($points);
        $seed = implode('|', array_map(fn ($id, $p) => $id.':'.($p ? $p['lat'].','.$p['lng'] : '-'), array_keys($points), $points));

        $value = unpack('N', substr(hash_hmac('sha256', $seed, $this->vault->derive('journey'), true), 0, 4))[1];

        return $value / 0xFFFFFFFF * 2 - 1;
    }
}
