<?php

namespace ErnestDefoe\SlowMail\Integration;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** The neutral answers: each integration overrides only what its extension decides. */
abstract class NoOpinion implements Integration
{
    public function allows(User $sender, User $recipient): bool
    {
        return true;
    }

    public function blockedEitherWay(int $a, int $b): bool
    {
        return false;
    }

    public function blockedWith(int $userId): array
    {
        return [];
    }

    public function inGoodStanding(User $user): bool
    {
        return true;
    }

    public function scopeReachable(Builder $users): void
    {
    }
}
