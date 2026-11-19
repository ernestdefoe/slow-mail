<?php

namespace ErnestDefoe\SlowMail\Integration;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** fof/byobu: a member who blocks private discussions takes no letters either. */
class Byobu extends NoOpinion
{
    public function allows(User $sender, User $recipient): bool
    {
        return ! $recipient->getAttribute('blocks_byobu_pd');
    }

    public function scopeReachable(Builder $users): void
    {
        $users->where(fn ($q) => $q->whereNull('users.blocks_byobu_pd')->orWhere('users.blocks_byobu_pd', false));
    }
}
