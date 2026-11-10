<?php

namespace ErnestDefoe\SlowMail;

use ErnestDefoe\MemberMap\MemberQuery;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
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
    public function __construct(private readonly ExtensionManager $extensions, private readonly SettingsRepositoryInterface $settings)
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

        $locale = (string) ($this->settings->get('default_locale') ?: 'en');
        $places = [];
        foreach ($rows as $row) {
            $postmark = self::postmark((string) $row->label, (string) $row->precision, $row->country_code ? (string) $row->country_code : null, $locale);
            if ($postmark === null) {
                continue;
            }
            $places[(int) $row->uid] = [
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'precision' => (string) $row->precision,
                'country' => $row->country_code ? strtolower((string) $row->country_code) : null,
                'postmark' => $postmark,
                'region' => $row->precision === 'region',
            ];
        }

        return $places;
    }

    /**
     * What a postmark may say: the country, by its name from the country code
     * (in the forum's language); at region precision, the region the member
     * chose before it ("Bavaria, Germany"). Never a city, and never the label
     * on its own: with no country code there is no postmark (and no place).
     */
    public static function postmark(string $label, string $precision, ?string $countryCode, string $locale = 'en'): ?string
    {
        $code = strtoupper(trim((string) $countryCode));
        if (strlen($code) !== 2) {
            return null;
        }

        $country = class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('-'.$code, $locale) : '';
        if ($country === '' || $country === $code) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $label)), fn ($part) => $part !== ''));
            $country = count($parts) > 1 ? (string) end($parts) : $code;
        }

        if ($precision === 'region') {
            $region = trim(explode(',', $label)[0]);
            if ($region !== '' && $region !== $country) {
                return mb_substr($region.', '.$country, 0, 100);
            }
        }

        return mb_substr($country, 0, 100);
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
