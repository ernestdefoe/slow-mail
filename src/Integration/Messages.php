<?php

namespace ErnestDefoe\SlowMail\Integration;

use Flarum\User\User;

/** flarum/messages: whoever it rules out as someone to message (anonymised accounts, …) can't be written to either. */
class Messages extends NoOpinion
{
    public function allows(User $sender, User $recipient): bool
    {
        return $sender->can('message', $recipient);
    }
}
