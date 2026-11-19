<?php

namespace ErnestDefoe\SlowMail\Integration;

use Ernestdefoe\Parley\Relations;
use Flarum\User\User;

/** ernestdefoe/parley: its blocks count both ways, and so does "who can message me" (nobody, or people I follow). */
class Parley extends NoOpinion
{
    public function __construct(private readonly Relations $relations)
    {
    }

    public function allows(User $sender, User $recipient): bool
    {
        return match ((string) $recipient->getPreference('parleyWhoCanMessage')) {
            'nobody' => false,
            'following' => $this->relations->follows($recipient->id, $sender->id),
            default => true,
        };
    }

    public function blockedEitherWay(int $a, int $b): bool
    {
        return $this->relations->isBlockedEitherWay($a, $b);
    }

    public function blockedWith(int $userId): array
    {
        return array_map(intval(...), array_keys($this->relations->blockedEitherWay($userId)));
    }
}
