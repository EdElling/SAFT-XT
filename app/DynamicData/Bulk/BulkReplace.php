<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

/**
 * Art „ersetzen“ einer Sammeländerung (BULK_EDIT.md §12 Fragen 9, 10): Suchtext → Ersatz innerhalb eines Textwerts, alle
 * Treffer, wahlweise mit Gross-/Kleinschreibung und nur als ganzes Wort. Die Person gibt nie einen regulären Ausdruck
 * ein; intern ist es ein Muster aus preg_quote(). „Ganzes Wort“ heisst: davor und danach kein Buchstabe, keine Ziffer
 * und kein „_“ – so passt „Str.“ in „Str. des 1. Mai“, aber nicht in „Hauptstr. 5“ (ein \b versagte am Punkt).
 * „Schreibweise übernehmen“ passt den Ersatz dem Treffer an: Treffer ganz gross (ab zwei Buchstaben) → Ersatz ganz gross,
 * Treffer mit grossem Anfang → Ersatz mit grossem Anfang, sonst kleiner Anfang. So erledigt „str.“ → „strasse“ ohne
 * Gross-/Kleinschreibung „Bahnhofstr.“ und „Str. des 1. Mai“ in einem Lauf.
 */
final class BulkReplace
{
    /** Kein Treffer: der Wert bleibt unverändert. */
    public const string NO_MATCH = "\0no-match";

    /**
     * Neuer Wert, NO_MATCH ohne Treffer bzw. bei leerem Wert; null, wenn nach dem Ersetzen nur Leerzeichen bleiben.
     */
    public static function apply(mixed $current, string $search, string $replace, bool $caseSensitive, bool $wholeWord, bool $keepCase = false): ?string
    {
        if ($current === null || $current === '' || $search === '' || ! is_scalar($current)) {
            return self::NO_MATCH;
        }

        $pattern = '/'.($wholeWord ? '(?<![\p{L}\p{N}_])' : '').preg_quote($search, '/').($wholeWord ? '(?![\p{L}\p{N}_])' : '').'/u'.($caseSensitive ? '' : 'i');
        $result = preg_replace_callback($pattern, static fn (array $match): string => $keepCase ? self::likeMatch($match[0], $replace) : $replace, (string) $current, -1, $count);

        if ($result === null || $count === 0) {
            return self::NO_MATCH;
        }

        return trim($result) === '' ? null : $result;
    }

    /** Ersatz in der Schreibweise des Treffers (ganz gross, grosser oder kleiner Anfang). */
    private static function likeMatch(string $match, string $replace): string
    {
        $letters = preg_replace('/\P{L}/u', '', $match) ?? '';

        if ($letters === '' || $replace === '') {
            return $replace;
        }

        if (mb_strlen($letters) > 1 && $letters === mb_strtoupper($letters)) {
            return mb_strtoupper($replace);
        }

        $first = mb_substr($letters, 0, 1);
        $head = mb_substr($replace, 0, 1);

        return ($first === mb_strtoupper($first) ? mb_strtoupper($head) : mb_strtolower($head)).mb_substr($replace, 1);
    }
}
