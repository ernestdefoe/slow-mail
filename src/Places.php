<?php

namespace ErnestDefoe\SlowMail;

/** Postmarks and distances: plain functions over the places a PlaceSource hands out. */
final class Places
{
    /**
     * What a postmark may say: the country, by its name from the country code
     * (in the forum's language); at region precision, the region the member
     * chose before it ("Bavaria, Germany"). Never a city, and never the label
     * on its own: with no country code there is no postmark (and no place).
     */
    public static function postmark(string $label, string $precision, ?string $countryCode, string $locale = 'en'): ?string
    {
        $code = strtoupper(trim((string) $countryCode));
        if (strlen($code) !== 2) {
            return null;
        }

        $country = class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('-'.$code, $locale) : '';
        if ($country === '' || $country === $code) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $label)), fn ($part) => $part !== ''));
            $country = count($parts) > 1 ? (string) end($parts) : $code;
        }

        if ($precision === 'region') {
            $region = trim(explode(',', $label)[0]);
            if ($region !== '' && $region !== $country) {
                return mb_substr($region.', '.$country, 0, 100);
            }
        }

        return mb_substr($country, 0, 100);
    }

    /** Great-circle distance in km. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
