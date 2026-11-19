<?php

namespace ErnestDefoe\SlowMail\Job;

use ErnestDefoe\SlowMail\Mailroom;
use Flarum\Queue\AbstractJob;

/** Delivers what's due, queued by a page visit on forums whose scheduler isn't running (DeliverOnVisit). */
class Deliver extends AbstractJob
{
    private const BATCH = 25;

    public function handle(Mailroom $mailroom): void
    {
        $mailroom->run(self::BATCH);
    }
}
