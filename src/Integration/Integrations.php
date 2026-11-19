<?php

namespace ErnestDefoe\SlowMail\Integration;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** Every enabled integration, asked together. With none enabled, every answer is the neutral one. */
class Integrations
{
    /** @param iterable<Integration> $all */
    public function __construct(private readonly iterable $all)
    {
    }

    public function allow(User $sender, User $recipient): bool
    {
        foreach ($this->all as $integration) {
            if (! $integration->allows($sender, $recipient)) {
                return false;
            }
        }

        return true;
    }

    public function blockedEitherWay(int $a, int $b): bool
    {
        foreach ($this->all as $integration) {
            if ($integration->blockedEitherWay($a, $b)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, true> */
    public function blockedWith(int $userId): array
    {
        $ids = [];
        foreach ($this->all as $integration) {
            foreach ($integration->blockedWith($userId) as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    public function inGoodStanding(User $user): bool
    {
        foreach ($this->all as $integration) {
            if (! $integration->inGoodStanding($user)) {
                return false;
            }
        }

        return true;
    }

    /** @param Builder<User> $users */
    public function scopeReachable(Builder $users): void
    {
        foreach ($this->all as $integration) {
            $integration->scopeReachable($users);
        }
    }
}
