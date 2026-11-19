<?php

namespace ErnestDefoe\SlowMail\Integration;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** flarum/suspend: suspended members are off the lists, and letters they sent before are returned. */
class Suspend extends NoOpinion
{
    public function inGoodStanding(User $user): bool
    {
        $until = $user->getAttribute('suspended_until');

        return ! $until || ! Carbon::parse($until)->isFuture();
    }

    public function scopeReachable(Builder $users): void
    {
        $users->where(fn ($q) => $q->whereNull('users.suspended_until')->orWhere('users.suspended_until', '<', Carbon::now()));
    }
}
