<?php

declare(strict_types=1);

namespace App\DynamicData\Files;

/**
 * Erlaubte Arten von Dateien je Feld (Option „kinds“). Geprüft wird am Inhalt (MIME-Typ), nicht an der Endung.
 * Bewusst ohne SVG und HTML: Sie könnten Skripte enthalten, die beim Öffnen im Browser laufen.
 */
final class FileKinds
{
    public const string ANY = 'any';

    /** @var array<string, list<string>> Art => MIME-Typen */
    public const array MIMES = [
        'images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'pdf' => ['application/pdf'],
        'office' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            // Manche Systeme erkennen Office-Dateien (ZIP-Container) nur allgemein.
            'application/zip',
        ],
        'text' => ['text/plain', 'text/csv'],
    ];

    /** @return array<string, string> Art => Bezeichnung (für die Auswahl im Table-Designer) */
    public static function options(): array
    {
        $options = [];

        foreach ([...array_keys(self::MIMES), self::ANY] as $kind) {
            $options[$kind] = __("tables.file_kinds.{$kind}");
        }

        return $options;
    }

    /**
     * Gültige Arten; bestimmte Arten gehen „Beliebig“ vor (beides angehakt = nur die bestimmten), keine = beliebig.
     *
     * @param  array<array-key, mixed>  $kinds
     * @return list<string>
     */
    public static function normalize(array $kinds): array
    {
        $kinds = array_map('strval', $kinds);
        $specific = array_values(array_filter(array_keys(self::MIMES), static fn (string $kind): bool => in_array($kind, $kinds, true)));

        return $specific === [] ? [self::ANY] : $specific;
    }

    /**
     * @param  list<string>  $kinds
     * @return list<string> MIME-Typen; leer = beliebig
     */
    public static function mimes(array $kinds): array
    {
        $kinds = self::normalize($kinds);

        if ($kinds === [self::ANY]) {
            return [];
        }

        return array_values(array_unique(array_merge(...array_map(static fn (string $kind): array => self::MIMES[$kind] ?? [], $kinds))));
    }

    /** Höchstgrösse für Uploads laut PHP (upload_max_filesize und post_max_size) in MB, falls kleiner als gewünscht. */
    public static function phpLimitMb(): ?int
    {
        $bytes = array_filter([self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size'))]);

        return $bytes === [] ? null : (int) floor(min($bytes) / 1024 / 1024);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
