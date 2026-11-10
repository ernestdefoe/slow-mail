<?php

namespace ErnestDefoe\SlowMail;

/** 🚨 extend.php uses these as string literals: reading a constant there autoloads during boot. */
final class Settings
{
    public const SEND = 'slow-mail.send';
    public const MODERATE = 'slow-mail.moderate';

    public const NAV = 'slow-mail.nav';
    public const KM_PER_DAY = 'slow-mail.km_per_day';
    public const MIN_HOURS = 'slow-mail.min_hours';
    public const MAX_HOURS = 'slow-mail.max_hours';
    public const DEFAULT_HOURS = 'slow-mail.default_hours';
    public const DAILY = 'slow-mail.daily_limit';
    public const NEW_PALS = 'slow-mail.new_pals_daily';

    public const DEFAULT_KM_PER_DAY = 2000;
    public const DEFAULT_MIN_HOURS = 12;
    public const DEFAULT_MAX_HOURS = 168;
    public const DEFAULT_DEFAULT_HOURS = 24;
    public const DEFAULT_DAILY = 10;
    public const DEFAULT_NEW_PALS = 3;

    /** Hard limits, whatever the settings say. */
    public const MAX_LENGTH = 6000;
    public const IN_TRANSIT = 20;
    public const IN_TRANSIT_TO_ONE = 3;
    public const LONGEST_HOURS = 24 * 30;

    /** Who a member takes letters from (a user preference). */
    public const ACCEPT = 'slowMailAccept';
    public const ACCEPT_EVERYONE = 'everyone';
    public const ACCEPT_PALS = 'pals';
    public const ACCEPT_NOBODY = 'nobody';

    public static function int(mixed $value, int $default, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $value));
    }
}
