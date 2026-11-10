<?php

namespace ErnestDefoe\SlowMail;

use Illuminate\Database\ConnectionInterface;

/**
 * Everything Slow Mail holds about a member: on erasure (Gdpr\SlowMailData)
 * and when the account is deleted (the foreign keys do it too, where the
 * database enforces them).
 */
final class Forget
{
    public static function user(int $id): void
    {
        Report::query()->where('reporter_id', $id)->orWhere('sender_id', $id)->delete();
        Letter::query()->where('sender_id', $id)->orWhere('recipient_id', $id)->delete();
        Seeker::query()->where('user_id', $id)->delete();
        resolve(ConnectionInterface::class)->table('slow_mail_blocks')->where('user_id', $id)->orWhere('blocked_id', $id)->delete();
    }
}
