<?php

declare(strict_types=1);

namespace App\ExternalData;

/** Tabelle oder View einer externen Datenbank, wie sie das Schema meldet. */
final readonly class TableInfo
{
    public const string KIND_TABLE = 'table';

    public const string KIND_VIEW = 'view';

    /**
     * @param  list<string>  $keyColumns  Primärschlüssel; leer bei Views und Tabellen ohne Schlüssel
     */
    public function __construct(
        public string $schema,
        public string $name,
        public string $kind,
        public ?string $comment = null,
        public array $keyColumns = [],
    ) {}

    public function isView(): bool
    {
        return $this->kind === self::KIND_VIEW;
    }
}
