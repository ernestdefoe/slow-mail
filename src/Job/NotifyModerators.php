<?php

namespace ErnestDefoe\SlowMail\Job;

use ErnestDefoe\SlowMail\Members;
use ErnestDefoe\SlowMail\Notification\LetterReportedBlueprint;
use ErnestDefoe\SlowMail\Report;
use ErnestDefoe\SlowMail\Settings;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;

/** "Lena reported a Slow Mail letter", to the moderators (up to 50), off the request. Never the sender. */
class NotifyModerators extends AbstractJob
{
    private const MAX = 50;

    public function __construct(private readonly int $reportId)
    {
        parent::__construct();
    }

    public function handle(NotificationSyncer $notifications, Members $members): void
    {
        $report = Report::query()->with('reporter')->find($this->reportId);
        if (! $report) {
            return;
        }

        $moderators = $members->withPermission(Settings::MODERATE)->where('users.id', '<>', $report->sender_id)->limit(self::MAX)->get();
        $notifications->sync(new LetterReportedBlueprint($report), $moderators->all());
    }
}
