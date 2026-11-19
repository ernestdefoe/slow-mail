<?php

namespace ErnestDefoe\SlowMail\Search;

use ErnestDefoe\SlowMail\Letter;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /api/slow-mail-letters`: only the letters the actor may see (the
 * ScopeLetterVisibility scope), with both members loaded in one query each.
 * Filters: box (in|out), with (a member's id).
 */
class LetterSearcher extends AbstractSearcher
{
    /** @return Builder<Letter> */
    public function getQuery(User $actor): Builder
    {
        return Letter::whereVisibleTo($actor)->select('slow_mail_letters.*')->with(['sender', 'recipient']);
    }
}
