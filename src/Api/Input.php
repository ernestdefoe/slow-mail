<?php

namespace ErnestDefoe\SlowMail\Api;

use Psr\Http\Message\ServerRequestInterface;

final class Input
{
    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();

        return is_array($body['data']['attributes'] ?? null) ? $body['data']['attributes'] : $body;
    }

    /** Trimmed text, line breaks kept (\r\n → \n), control characters dropped. */
    public static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        if (! is_string($value)) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $value));
    }

    public static function id(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
