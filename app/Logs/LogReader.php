<?php

declare(strict_types=1);

namespace App\Logs;

use Illuminate\Support\Facades\File;

/**
 * Liest das Ende der Logdateien in storage/logs (laravel.log bzw. laravel-JJJJ-MM-TT.log) – nie die ganze Datei – und
 * zerlegt es in Einträge, neueste zuerst. Nur lesen.
 */
final class LogReader
{
    /** So viel vom Ende einer Datei wird gelesen. */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** Höchstens so viele Einträge (die neuesten). */
    public const int MAX_ENTRIES = 500;

    /** Stufen von Monolog, schwerste zuerst. */
    public const array LEVELS = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];

    private const string HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T][0-9:.]+(?:[+-]\d{2}:?\d{2}|Z)?)\] ([\w-]+)\.([A-Z]+): (.*)$/';

    public function directory(): string
    {
        return storage_path('logs');
    }

    /** @return list<string> Dateinamen der Logdateien, neueste zuerst */
    public function files(): array
    {
        if (! File::isDirectory($this->directory())) {
            return [];
        }

        return collect(File::files($this->directory()))
            ->filter(static fn (\SplFileInfo $file): bool => preg_match('/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/', $file->getFilename()) === 1)
            ->sortByDesc(static fn (\SplFileInfo $file): int => $file->getMTime())
            ->map(static fn (\SplFileInfo $file): string => $file->getFilename())
            ->values()
            ->all();
    }

    /** Nur bekannte Dateinamen aus dem Log-Ordner – nie ein Pfad aus der Anfrage. */
    public function path(?string $file): ?string
    {
        $files = $this->files();
        $name = in_array($file, $files, true) ? $file : ($files[0] ?? null);

        return $name === null ? null : $this->directory().DIRECTORY_SEPARATOR.$name;
    }

    /** Wird nur das Ende der Datei gezeigt? */
    public function truncated(?string $file): bool
    {
        $path = $this->path($file);

        return $path !== null && File::size($path) > self::MAX_BYTES;
    }

    /** Der gelesene Ausschnitt (Ende der Datei) als Text, z. B. für den Download. */
    public function tail(?string $file): string
    {
        $path = $this->path($file);

        if ($path === null) {
            return '';
        }

        $size = File::size($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $start = max(0, $size - self::MAX_BYTES);
            fseek($handle, $start);
            $text = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        // Mitten in einem Eintrag begonnen: bis zum nächsten Eintrag überspringen.
        if ($start > 0 && preg_match('/\n(?=\[\d{4}-\d{2}-\d{2}[ T])/', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $text = substr($text, $match[0][1] + 1);
        }

        return $text;
    }

    public function find(?string $file, string $id): ?LogEntry
    {
        foreach ($this->entries($file) as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<LogEntry> neueste zuerst, höchstens MAX_ENTRIES */
    public function entries(?string $file): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $this->tail($file)) ?: [] as $line) {
            if (preg_match(self::HEADER, $line, $m) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = ['time' => $m[1], 'environment' => $m[2], 'level' => $m[3], 'message' => $m[4], 'lines' => [$line]];
            } elseif ($current !== null) {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        $entries = array_slice(array_reverse($entries), 0, self::MAX_ENTRIES);

        return array_map(static function (array $entry): LogEntry {
            $text = rtrim(implode("\n", $entry['lines']));
            preg_match('/"error_reference":"([A-Z0-9]{6,16})"/', $text, $reference);

            return new LogEntry(
                // Stabil, auch wenn neue Einträge dazukommen (die Position verschiebt sich dann).
                id: substr(md5($text), 0, 16),
                time: $entry['time'],
                environment: $entry['environment'],
                level: $entry['level'],
                // Kurzfassung: Meldung ohne angehängten Kontext („{…}“) und ohne Ablauf.
                message: trim((string) preg_replace('/\s\{.*$/', '', $entry['message'])),
                text: $text,
                reference: $reference[1] ?? null,
            );
        }, $entries);
    }
}
