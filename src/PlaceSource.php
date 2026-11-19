<?php

namespace ErnestDefoe\SlowMail;

use Flarum\User\User;

/**
 * Where members are, as far as one member may see another. Bound to NoPlaces,
 * or to MemberMapPlaces while ernestdefoe/member-map is enabled (extend.php,
 * Extend\Conditional).
 */
interface PlaceSource
{
    /** Whether places exist at all (Member Map is on). */
    public function enabled(): bool;

    /**
     * The places of $ids that $viewer may see, keyed by user id.
     *
     * @param list<int> $ids
     * @return array<int, array{lat: float, lng: float, precision: string, country: string|null, postmark: string, region: bool}>
     */
    public function seenBy(User $viewer, array $ids): array;
}
