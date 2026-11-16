<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

/** Unterstützte Dateiformate des Tabellen-Imports. */
enum ImportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public static function fromFileName(string $fileName): ?self
    {
        return match (mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'csv', 'txt', 'tsv' => self::Csv,
            'xlsx' => self::Xlsx,
            default => null,
        };
    }
}
