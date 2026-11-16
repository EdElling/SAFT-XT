<?php

declare(strict_types=1);

namespace Saft\ChPruefungen;

use RoundingMode;

/** Prüfziffern und Formate Schweizer Nummern; Rundung auf 5 Rappen. */
final class SwissChecks
{
    /** AHV-Nummer (AHVN13): 756.xxxx.xxxx.xx, Prüfziffer nach EAN-13; Punkte und Leerzeichen sind erlaubt. */
    public static function ahv(string $value): bool
    {
        $digits = preg_replace('/[\s.]/', '', $value) ?? '';

        if (preg_match('/^756\d{10}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $digits[12];
    }

    /** IBAN (alle Länder): Prüfsumme modulo 97; Schweiz und Liechtenstein mit 21 Zeichen. */
    public static function iban(string $value): bool
    {
        $iban = strtoupper(preg_replace('/\s/', '', $value) ?? '');

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1
            || (in_array(substr($iban, 0, 2), ['CH', 'LI'], true) && strlen($iban) !== 21)) {
            return false;
        }

        $remainder = 0;

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            foreach (str_split(ctype_digit($char) ? $char : (string) (ord($char) - 55)) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    /** Unternehmens-Identifikationsnummer: CHE-123.456.789 (Punkte und Bindestrich optional), Prüfziffer modulo 11. */
    public static function uid(string $value): bool
    {
        if (preg_match('/^CHE-?(\d{3})\.?(\d{3})\.?(\d{3})$/', strtoupper(trim($value)), $m) !== 1) {
            return false;
        }

        $digits = $m[1].$m[2].$m[3];
        $sum = 0;

        foreach ([5, 4, 3, 2, 7, 6, 5, 4] as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }

        $check = 11 - $sum % 11;

        return $check !== 10 && ($check === 11 ? 0 : $check) === (int) $digits[8];
    }

    /** Postleitzahl Schweiz: vier Ziffern, 1000–9999. */
    public static function postalCode(string $value): bool
    {
        return preg_match('/^[1-9]\d{3}$/', trim($value)) === 1;
    }

    /** Kaufmännisch auf 5 Rappen runden: 12.32 → 12.30, 12.33 → 12.35, 12.375 → 12.40. */
    public static function roundToFiveRappen(string $amount): string
    {
        return bcdiv(bcround(bcmul($amount, '20', 12), 0, RoundingMode::HalfAwayFromZero), '20', 2);
    }
}
