<?php

namespace ErnestDefoe\SlowMail\Integration;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Another extension's say in who can write to whom. Each one is registered
 * (tagged `slow-mail.integrations`) only while its extension is enabled, from
 * an Extend\Conditional block in extend.php, so nothing here ever checks
 * what's installed or touches a table that isn't there.
 */
interface Integration
{
    public const TAG = 'slow-mail.integrations';

    /** Whether $recipient takes letters from $sender, as far as this extension is concerned. */
    public function allows(User $sender, User $recipient): bool;

    /** Whether the two have blocked each other, either way. */
    public function blockedEitherWay(int $a, int $b): bool;

    /**
     * Everyone blocked either way with $userId.
     *
     * @return list<int>
     */
    public function blockedWith(int $userId): array;

    /** Whether a member may still send (not suspended, …). */
    public function inGoodStanding(User $user): bool;

    /**
     * Narrows a members query to those who can take letters.
     *
     * @param Builder<User> $users
     */
    public function scopeReachable(Builder $users): void;
}
