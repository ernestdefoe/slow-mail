<?php

namespace ErnestDefoe\SlowMail\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The letters a member may see, for every query built with whereVisibleTo():
 * what they sent (unless they put it away), and what has ARRIVED for them
 * (unless they put it away). A letter still on its way doesn't exist for its
 * recipient, and nobody else sees any letter, admins included.
 */
class ScopeLetterVisibility
{
    /** @param Builder<\ErnestDefoe\SlowMail\Letter> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->isGuest()) {
            // Guests have no letters: an always-false condition, as core's own visibility scopes do.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('slow_mail_letters.sender_id', $actor->id)->where('slow_mail_letters.sender_hidden', false))
            ->orWhere(fn ($q) => $q->where('slow_mail_letters.recipient_id', $actor->id)
                ->whereNotNull('slow_mail_letters.delivered_at')
                ->where('slow_mail_letters.recipient_hidden', false)));
    }
}
