<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\DynamicData\Schema\FieldType;

/** Spalte einer externen Tabelle mit ihrer Abbildung auf einen SAFT-Feldtyp. */
final readonly class ColumnInfo
{
    public const string UNSUPPORTED_TYPE = 'type';

    public const string UNSUPPORTED_NAME = 'name';

    /**
     * @param  FieldType|null  $type  null = nicht unterstützt (siehe $unsupported)
     * @param  array<string, mixed>  $options  Länge bzw. Stellen wie beim Table-Designer
     * @param  bool  $textOnly  nur als Text anzeigen, nie bearbeitbar (uuid, json, time …)
     */
    public function __construct(
        public string $name,
        public string $externalType,
        public ?FieldType $type,
        public array $options = [],
        public bool $textOnly = false,
        public bool $nullable = true,
        public ?string $default = null,
        public bool $primary = false,
        public bool $unique = false,
        public bool $generated = false,
        public ?string $comment = null,
        public ?string $unsupported = null,
    ) {}

    public function isSupported(): bool
    {
        return $this->type !== null;
    }

    /** Vorgabewert erzeugt eine UUID (MySQL uuid(), PostgreSQL gen_random_uuid() bzw. uuid_generate_v4(), SQL Server newid() bzw. newsequentialid()). */
    public function hasUuidDefault(): bool
    {
        return $this->default !== null && preg_match('/^\(*(uuid|gen_random_uuid|uuid_generate_v4|newid|newsequentialid)\(\)\)*$/i', trim($this->default)) === 1;
    }

    /** NOT NULL ohne Vorgabe und nicht von der Datenbank gefüllt: beim Anlegen später Pflicht. */
    public function isRequired(): bool
    {
        return ! $this->nullable && $this->default === null && ! $this->generated;
    }
}
