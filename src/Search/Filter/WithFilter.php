<?php

namespace ErnestDefoe\SlowMail\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * `filter[with]=<user id>`: the letters between the actor and one member, either way.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class WithFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'with';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $id = $this->asInt($value);

        $between = fn ($q) => $q->where('slow_mail_letters.sender_id', $id)->orWhere('slow_mail_letters.recipient_id', $id);

        $negate ? $state->getQuery()->whereNot($between) : $state->getQuery()->where($between);
    }
}
