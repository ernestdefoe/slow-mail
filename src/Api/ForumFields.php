<?php

namespace ErnestDefoe\SlowMail\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The viewer's own Slow Mail permissions on the forum payload: already loaded, so no query. */
class ForumFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('slowMailCanSend')
                ->get(fn (object $forum, Context $context) => $context->getActor()->can('slow-mail.send')),
            Schema\Boolean::make('slowMailCanModerate')
                ->get(fn (object $forum, Context $context) => $context->getActor()->can('slow-mail.moderate')),
        ];
    }
}
