<?php

declare(strict_types=1);

namespace Saft\LagerTafel;

/**
 * Artikelnummern der Lager-Demo: ein Grossbuchstabe, Bindestrich, vier Ziffern („A-1001“). Ohne Bezug zu SAFT –
 * die Prüfregel und der Listener verwenden dieselben zwei Methoden, Tests brauchen keine Datenbank.
 */
final class ArticleNumber
{
    private const string PATTERN = '/^[A-Z]-\d{4}$/';

    /** Schreibweise vereinheitlichen: Leerzeichen weg, Grossbuchstaben („ a-1001 “ → „A-1001“). */
    public static function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? $value);
    }

    /** Gültig, sobald die Schreibweise vereinheitlicht ist – „a-1001“ besteht also, der Listener speichert „A-1001“. */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, self::normalize($value)) === 1;
    }
}
