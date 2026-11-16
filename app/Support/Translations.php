<?php

declare(strict_types=1);

namespace App\Support;

/** Texte, die als einfacher Text oder als Array je Sprache vorliegen (z. B. Auswahlwerte). */
final class Translations
{
    /**
     * Text in der aktuellen Sprache, sonst in der Vorgabesprache, sonst irgendein vorhandener.
     *
     * @param  array<string, mixed>|string|null  $value
     */
    public static function pick(array|string|null $value, string $fallback = ''): string
    {
        if (! is_array($value)) {
            return filled($value) ? (string) $value : $fallback;
        }

        foreach ([app()->getLocale(), (string) config('locales.default')] as $locale) {
            if (filled($value[$locale] ?? null)) {
                return (string) $value[$locale];
            }
        }

        $any = array_filter($value, filled(...));

        return $any === [] ? $fallback : (string) reset($any);
    }

    /**
     * Für Formulare: ein Text gilt als Text der Vorgabesprache.
     *
     * @param  array<string, mixed>|string|null  $value
     * @return array<string, string>
     */
    public static function perLocale(array|string|null $value): array
    {
        if (is_array($value)) {
            return array_map('strval', array_filter($value, filled(...)));
        }

        return filled($value) ? [(string) config('locales.default') => (string) $value] : [];
    }

    /**
     * Leere Sprachen entfernen, Texte kürzen; ein einfacher Text bleibt ein Text.
     *
     * @param  array<string, mixed>|string|null  $value
     * @return array<string, string>|string
     */
    public static function clean(array|string|null $value, int $maxLength = 255): array|string
    {
        if (! is_array($value)) {
            return mb_substr(trim((string) $value), 0, $maxLength);
        }

        $clean = [];

        foreach ($value as $locale => $text) {
            $text = mb_substr(trim((string) $text), 0, $maxLength);

            if ($text !== '' && AppLocale::isAvailable((string) $locale)) {
                $clean[(string) $locale] = $text;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, string>|string  $value
     */
    public static function isEmpty(array|string $value): bool
    {
        return is_array($value) ? $value === [] : $value === '';
    }
}
