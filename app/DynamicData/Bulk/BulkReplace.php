<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

/**
 * Art „ersetzen“ einer Sammeländerung (BULK_EDIT.md §12 Fragen 9, 10): Suchtext → Ersatz innerhalb eines Textwerts, alle
 * Treffer, wahlweise mit Gross-/Kleinschreibung und nur als ganzes Wort. Die Person gibt nie einen regulären Ausdruck
 * ein; intern ist es ein Muster aus preg_quote(). „Ganzes Wort“ heisst: davor und danach kein Buchstabe, keine Ziffer
 * und kein „_“ – so passt „Str.“ in „Str. des 1. Mai“, aber nicht in „Hauptstr. 5“ (ein \b versagte am Punkt).
 */
final class BulkReplace
{
    /** Kein Treffer: der Wert bleibt unverändert. */
    public const string NO_MATCH = "\0no-match";

    /**
     * Neuer Wert, NO_MATCH ohne Treffer bzw. bei leerem Wert; null, wenn nach dem Ersetzen nur Leerzeichen bleiben.
     */
    public static function apply(mixed $current, string $search, string $replace, bool $caseSensitive, bool $wholeWord): ?string
    {
        if ($current === null || $current === '' || $search === '' || ! is_scalar($current)) {
            return self::NO_MATCH;
        }

        $pattern = '/'.($wholeWord ? '(?<![\p{L}\p{N}_])' : '').preg_quote($search, '/').($wholeWord ? '(?![\p{L}\p{N}_])' : '').'/u'.($caseSensitive ? '' : 'i');
        $result = preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $replace), (string) $current, -1, $count);

        if ($result === null || $count === 0) {
            return self::NO_MATCH;
        }

        return trim($result) === '' ? null : $result;
    }
}
