<?php

namespace ErnestDefoe\SlowMail;

use ErnestDefoe\MemberMap\MemberQuery;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;

/**
 * Where members are, as far as Member Map lets one member see another, and
 * no further.
 *
 * A place is only ever what Member Map stores (the centre of the city,
 * region or country the member chose) and only when the viewer could see
 * that member on the map right now: the viewer may view the map, and the
 * member is listed on it (Member Map's own query: permission, confirmed,
 * not suspended, visible). A member who isn't on the map has no place here,
 * so nothing Slow Mail shows can say more than the map already does.
 */
class Places
{
    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function enabled(): bool
    {
        return $this->extensions->isEnabled('ernestdefoe-member-map') && class_exists(MemberQuery::class);
    }

    /**
     * The places of $ids that $viewer may see, keyed by user id. One query.
     *
     * @param list<int> $ids
     * @return array<int, array{lat: float, lng: float, precision: string, country: string|null, postmark: string, region: bool}>
     */
    public function seenBy(User $viewer, array $ids): array
    {
        if (! $ids || ! $this->enabled() || ! $viewer->can('member-map.view')) {
            return [];
        }

        $rows = resolve(MemberQuery::class)->listable($viewer)
            ->whereIn('users.id', array_values(array_unique($ids)))
            ->toBase()
            ->get(['users.id as uid', 'member_map_locations.lat', 'member_map_locations.lng', 'member_map_locations.precision', 'member_map_locations.label', 'member_map_locations.country_code']);

        $places = [];
        foreach ($rows as $row) {
            $places[(int) $row->uid] = [
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'precision' => (string) $row->precision,
                'country' => $row->country_code ? strtolower((string) $row->country_code) : null,
                'postmark' => self::postmark((string) $row->label, (string) $row->precision),
                'region' => $row->precision === 'region',
            ];
        }

        return $places;
    }

    /**
     * What a postmark may say: a region ("Bavaria, Germany") when that is what
     * the member chose, else just the country. Never a city.
     */
    public static function postmark(string $label, string $precision): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $label)), fn ($part) => $part !== ''));
        if (! $parts) {
            return '';
        }

        $text = $precision === 'region' && count($parts) > 1 ? $parts[0].', '.end($parts) : end($parts);

        return mb_substr($text, 0, 100);
    }

    /** Great-circle distance in km. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
