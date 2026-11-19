<?php

namespace ErnestDefoe\SlowMail;

use Flarum\User\User;

/** Without Member Map nobody has a place: every letter takes the set time. */
class NoPlaces implements PlaceSource
{
    public function enabled(): bool
    {
        return false;
    }

    public function seenBy(User $viewer, array $ids): array
    {
        return [];
    }
}
