<?php

namespace ErnestDefoe\SlowMail;

use Flarum\Foundation\KnownError;
use RuntimeException;

/** The key is gone, or not the one the letters were written with (Vault). Answered with 503 (extend.php). */
class KeyMissing extends RuntimeException implements KnownError
{
    public function getType(): string
    {
        return 'slow_mail_key_missing';
    }
}
