<?php

declare(strict_types=1);

namespace App\ExternalData;

/** Übersetzt CHECK-Regeln eines Datenbanktyps in Prüfungen von SAFT; null = nur die Datenbank prüft. */
interface CheckTranslator
{
    /**
     * @param  array<string, string>  $columnTypes  Spalte => SAFT-Grundtyp (FieldType-Wert)
     */
    public function translate(CheckConstraintInfo $check, array $columnTypes): ?CheckTranslation;
}
