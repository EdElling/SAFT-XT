<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use DateTimeInterface;

/** Verfügbare Sprachen der Oberfläche und ihre Schreibweisen (config/locales.php). */
final class AppLocale
{
    /** Cookie mit der zuletzt gewählten Sprache; überlebt das Abmelden. */
    public const string COOKIE = 'saft_locale';

    /**
     * @return list<string>
     */
    public static function available(): array
    {
        return array_keys((array) config('locales.available'));
    }

    public static function isAvailable(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, (array) config('locales.available'));
    }

    /**
     * @return array<string, string> Sprachcode => Name in der eigenen Sprache
     */
    public static function labels(): array
    {
        return array_map(static fn (array $locale): string => $locale['label'], (array) config('locales.available'));
    }

    public static function setting(string $key): string
    {
        $available = (array) config('locales.available');
        $locale = $available[app()->getLocale()] ?? $available[array_key_first($available)];
        $overrides = (array) config('locales.regions.'.(self::region() ?? '-'), []);

        return (string) ($overrides[$key] ?? $locale[$key]);
    }

    /** Zahlenformat „wie die Sprache“ (gespeichert am Benutzer); leer = automatisch, sonst eine Region (z. B. „CH“). */
    public const string NUMBERS_BY_LANGUAGE = 'lang';

    /**
     * Region des Zahlenformats der angemeldeten Person (z. B. „CH“), sonst null = wie die Sprache. „Automatisch“ nimmt
     * die Region der bevorzugten Browsersprache (de-CH, fr-CH, it-CH …), wenn es dafür ein Zahlenformat gibt.
     */
    public static function region(): ?string
    {
        $user = auth()->user();
        $choice = $user instanceof User ? $user->number_format : null;
        $regions = (array) config('locales.regions');

        if ($choice === self::NUMBERS_BY_LANGUAGE) {
            return null;
        }

        if (is_string($choice) && array_key_exists($choice, $regions)) {
            return $choice;
        }

        $request = app()->bound('request') ? request() : null;

        foreach ($request?->getLanguages() ?? [] as $language) {
            $region = strtoupper((string) (preg_split('/[-_]/', $language)[1] ?? ''));

            // Nur die bevorzugte Browsersprache zählt.
            return array_key_exists($region, $regions) ? $region : null;
        }

        return null;
    }

    /** Locale für Zahlen: Sprache, ggf. mit Region (de_CH: 1'234.50). */
    public static function numberLocale(): string
    {
        $region = self::region();

        return app()->getLocale().($region === null ? '' : '_'.$region);
    }

    public static function date(?DateTimeInterface $value): ?string
    {
        return $value?->format(self::setting('date'));
    }

    public static function dateTime(?DateTimeInterface $value): ?string
    {
        return $value?->format(self::setting('datetime'));
    }
}
