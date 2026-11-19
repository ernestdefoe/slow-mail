<?php

namespace ErnestDefoe\SlowMail;

use ErnestDefoe\MemberMap\MemberQuery;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Places from ernestdefoe/member-map, and no further than it lets one member
 * see another.
 *
 * A place is only ever what Member Map stores (the centre of the city, region
 * or country the member chose) and only when the viewer could see that member
 * on the map right now: the viewer may view the map, and the member is listed
 * on it (Member Map's own query: permission, confirmed, not suspended,
 * visible). A member who isn't on the map has no place here, so nothing Slow
 * Mail shows can say more than the map already does.
 */
class MemberMapPlaces implements PlaceSource
{
    public function __construct(private readonly MemberQuery $members, private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function enabled(): bool
    {
        return true;
    }

    public function seenBy(User $viewer, array $ids): array
    {
        if (! $ids || ! $viewer->can('member-map.view')) {
            return [];
        }

        $rows = $this->members->listable($viewer)
            ->whereIn('users.id', array_values(array_unique($ids)))
            ->toBase()
            ->get(['users.id as uid', 'member_map_locations.lat', 'member_map_locations.lng', 'member_map_locations.precision', 'member_map_locations.label', 'member_map_locations.country_code']);

        $locale = (string) $this->settings->get('default_locale');
        $places = [];
        foreach ($rows as $row) {
            $postmark = Places::postmark((string) $row->label, (string) $row->precision, $row->country_code ? (string) $row->country_code : null, $locale);
            if ($postmark === null) {
                continue;
            }
            $places[(int) $row->uid] = [
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'precision' => (string) $row->precision,
                'country' => strtolower((string) $row->country_code),
                'postmark' => $postmark,
                'region' => $row->precision === 'region',
            ];
        }

        return $places;
    }
}
