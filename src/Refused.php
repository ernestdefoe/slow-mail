<?php

namespace ErnestDefoe\SlowMail;

use Flarum\Foundation\KnownError;
use RuntimeException;

/**
 * A letter that can't be sent, reported, or put away, with the reason as its
 * error code (`slow_mail_daily_limit`, …). extend.php maps each code to its
 * HTTP status; the forum turns the code into a sentence.
 */
class Refused extends RuntimeException implements KnownError
{
    public const NOT_ACCEPTING = 'slow_mail_not_accepting';
    public const BAD_LETTER = 'slow_mail_bad_letter';
    public const TOO_MANY_IN_TRANSIT = 'slow_mail_too_many_in_transit';
    public const WAIT_FOR_DELIVERY = 'slow_mail_wait_for_delivery';
    public const DAILY_LIMIT = 'slow_mail_daily_limit';
    public const NEW_PALS_LIMIT = 'slow_mail_new_pals_limit';
    public const SLOW_DOWN = 'slow_mail_slow_down';
    public const IN_TRANSIT = 'slow_mail_in_transit';
    public const ALREADY_REPORTED = 'slow_mail_already_reported';
    public const ALREADY_LISTED = 'slow_mail_already_listed';

    public function __construct(private readonly string $type)
    {
        parent::__construct($type);
    }

    public function getType(): string
    {
        return $this->type;
    }
}
