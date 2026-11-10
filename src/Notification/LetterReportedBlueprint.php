<?php

namespace ErnestDefoe\SlowMail\Notification;

use ErnestDefoe\SlowMail\Report;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "Lena reported a Slow Mail letter": to moderators. The words are only on the reports page. */
class LetterReportedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Report $report)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->report->reporter;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->report->reporter;
    }

    public function getData(): mixed
    {
        return ['report' => $this->report->id];
    }

    public static function getType(): string
    {
        return 'slowMailReported';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
