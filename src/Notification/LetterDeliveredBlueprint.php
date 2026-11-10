<?php

namespace ErnestDefoe\SlowMail\Notification;

use ErnestDefoe\SlowMail\Letter;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "Your letter to Lena was delivered": to the sender, on delivery. Whether it was opened is never said. */
class LetterDeliveredBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Letter $letter)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->letter->recipient;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->letter->recipient;
    }

    public function getData(): mixed
    {
        return ['letter' => $this->letter->id];
    }

    public static function getType(): string
    {
        return 'slowMailDelivered';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
