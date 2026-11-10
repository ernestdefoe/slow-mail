<?php

namespace ErnestDefoe\SlowMail\Notification;

use ErnestDefoe\SlowMail\Letter;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "A letter from Lena has arrived": to the recipient, and only once it has
 * (Mailroom creates it on delivery, never before). The subject is the sender
 * (a User has an API resource; a subject without one 500s every notification
 * list); the letter's id rides in the data, which makes the blueprint one per
 * letter. Not a word of the letter goes in the alert or the email.
 */
class LetterArrivedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Letter $letter)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->letter->sender;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->letter->sender;
    }

    public function getData(): mixed
    {
        return ['letter' => $this->letter->id];
    }

    public static function getType(): string
    {
        return 'slowMailArrived';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-slow-mail::emails.plain.arrived',
            'html' => 'ernestdefoe-slow-mail::emails.html.arrived',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-slow-mail.email.arrived.subject', [
            '{name}' => $this->letter->sender->display_name ?? '',
        ]);
    }
}
