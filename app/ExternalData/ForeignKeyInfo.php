<?php

declare(strict_types=1);

namespace App\ExternalData;

/** Einspaltiger Fremdschlüssel einer externen Tabelle (Grundlage für Verknüpfungen). */
final readonly class ForeignKeyInfo
{
    public function __construct(
        public string $column,
        public string $foreignSchema,
        public string $foreignTable,
        public string $foreignColumn,
    ) {}
}
