<?php

namespace ErnestDefoe\SlowMail\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * `filter[box]=in` (letters that arrived for the actor) or `out` (letters the actor sent).
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class BoxFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'box';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $column = $this->asString($value) === 'out' ? 'slow_mail_letters.sender_id' : 'slow_mail_letters.recipient_id';

        $state->getQuery()->where($column, $negate ? '<>' : '=', $state->getActor()->id);
    }
}
