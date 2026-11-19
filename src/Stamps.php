<?php

namespace ErnestDefoe\SlowMail;

/**
 * The stamps a sender can choose: an original set drawn in the browser
 * (js/src/forum/stamps.tsx), plus "home", which prints the sender's own
 * country when they have a postmark.
 */
final class Stamps
{
    public const HOME = 'home';

    public const THEMES = ['lighthouse', 'mountain', 'wave', 'balloon', 'fox', 'cactus', 'blossom', 'aurora', 'owl', 'teapot', 'moon', 'whale'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge([self::HOME], self::THEMES);
    }

    public static function valid(?string $stamp): bool
    {
        return in_array($stamp, self::all(), true);
    }
}
