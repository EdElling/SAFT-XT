<?php

declare(strict_types=1);

namespace App\DynamicData\Export;

enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Xlsx => 'Excel (.xlsx)',
            self::Csv => 'CSV (.csv)',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv; charset=UTF-8',
        };
    }
}
