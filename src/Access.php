<?php

namespace ErnestDefoe\SlowMail;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see a letter. The ONE gate in front of Present: the sender, and the
 * recipient once it has arrived. Nobody else, admins included: there is no
 * moderation view of letters, and a letter still on its way doesn't exist for
 * its recipient at all.
 */
final class Access
{
    public static function canSee(Letter $letter, User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        if ($actor->id === $letter->sender_id) {
            return ! $letter->sender_hidden;
        }

        return $actor->id === $letter->recipient_id && $letter->isDelivered() && ! $letter->recipient_hidden;
    }

    /**
     * The letters a member may see, as a query: what they sent, and what has arrived for them.
     *
     * @return Builder<Letter>
     */
    public static function scope(User $actor): Builder
    {
        return Letter::query()->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('sender_id', $actor->id)->where('sender_hidden', false))
            ->orWhere(fn ($q) => $q->where('recipient_id', $actor->id)->whereNotNull('delivered_at')->where('recipient_hidden', false)));
    }
}
