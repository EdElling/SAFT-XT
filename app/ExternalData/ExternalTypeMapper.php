<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;

/**
 * Bildet Spaltentypen einer externen Datenbank auf SAFT-Feldtypen ab (PostgreSQL, MySQL).
 *
 * @phpstan-type Mapping array{type: ?FieldType, options: array<string, mixed>, textOnly: bool}
 */
final class ExternalTypeMapper
{
    /** Nur als Text anzeigen: lesbar, aber ohne passende Eingabe in SAFT. */
    private const array TEXT_ONLY = ['uuid', 'time', 'timetz', 'interval', 'inet', 'cidr', 'macaddr', 'macaddr8', 'money', 'float4', 'float8', 'oid'];

    private const array LONG_TEXT_ONLY = ['json', 'jsonb', 'xml'];

    /**
     * @param  string  $typeName  Basistyp, z. B. "varchar", "int4", "_int4" (Array)
     * @param  string  $type  vollständiger Typ, z. B. "character varying(200)", "numeric(12,2)"
     * @return Mapping
     */
    public function map(ConnectionDriver $driver, string $typeName, string $type): array
    {
        return match ($driver) {
            ConnectionDriver::Pgsql => $this->postgres(strtolower($typeName), strtolower($type)),
            ConnectionDriver::Mysql => $this->mysql(strtolower($typeName), strtolower($type)),
            ConnectionDriver::Mariadb => $this->mariaDb(strtolower($typeName), strtolower($type)),
            ConnectionDriver::Sqlsrv => $this->sqlServer(strtolower($typeName), strtolower($type)),
        };
    }

    /** @return Mapping */
    private function postgres(string $typeName, string $type): array
    {
        $arguments = preg_match('/\((\d+)(?:\s*,\s*(\d+))?\)/', $type, $m) === 1 ? [(int) $m[1], isset($m[2]) ? (int) $m[2] : 0] : null;

        return match (true) {
            str_starts_with($typeName, '_') => self::none(),
            in_array($typeName, ['varchar', 'bpchar', 'char'], true) && $arguments !== null => self::of(FieldType::String, ['length' => $arguments[0]]),
            in_array($typeName, ['varchar', 'text', 'citext', 'name'], true) => self::of(FieldType::Text),
            in_array($typeName, ['int2', 'int4', 'int8'], true) => self::of(FieldType::Integer),
            $typeName === 'numeric' && $arguments !== null => self::of(FieldType::Decimal, ['precision' => $arguments[0], 'scale' => $arguments[1]]),
            $typeName === 'numeric' => self::of(FieldType::String, ['length' => 255], textOnly: true),
            $typeName === 'bool' => self::of(FieldType::Boolean),
            $typeName === 'date' => self::of(FieldType::Date),
            in_array($typeName, ['timestamp', 'timestamptz'], true) => self::of(FieldType::DateTime),
            in_array($typeName, self::LONG_TEXT_ONLY, true) => self::of(FieldType::Text, textOnly: true),
            in_array($typeName, self::TEXT_ONLY, true) => self::of(FieldType::String, ['length' => 255], textOnly: true),
            default => self::none(),
        };
    }

    /**
     * MySQL: $typeName ist DATA_TYPE (z. B. "int", "enum"), $type ist COLUMN_TYPE (z. B. "int unsigned",
     * "tinyint(1)", "enum('a','b')"). Die Werte eines enum kommen als Werteliste über die CHECK-Regeln
     * (siehe SchemaInspector::checkConstraints).
     *
     * @return Mapping
     */
    private function mysql(string $typeName, string $type): array
    {
        $arguments = preg_match('/^[a-z]+\((\d+)(?:\s*,\s*(\d+))?\)/', $type, $m) === 1 ? [(int) $m[1], isset($m[2]) ? (int) $m[2] : 0] : null;

        return match (true) {
            in_array($typeName, ['varchar', 'char'], true) && $arguments !== null => self::of(FieldType::String, ['length' => $arguments[0]]),
            in_array($typeName, ['tinytext', 'text', 'mediumtext', 'longtext'], true) => self::of(FieldType::Text),
            // BOOLEAN ist in MySQL ein tinyint(1).
            $typeName === 'tinyint' && $type === 'tinyint(1)' => self::of(FieldType::Boolean),
            $typeName === 'year' => self::of(FieldType::Integer, [CustomField::GROUP_DIGITS => false]),
            in_array($typeName, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) => self::of(FieldType::Integer),
            $typeName === 'decimal' && $arguments !== null => self::of(FieldType::Decimal, ['precision' => $arguments[0], 'scale' => $arguments[1]]),
            $typeName === 'date' => self::of(FieldType::Date),
            in_array($typeName, ['datetime', 'timestamp'], true) => self::of(FieldType::DateTime),
            $typeName === 'enum' => self::of(FieldType::String, ['length' => self::longestEnumValue($type)]),
            $typeName === 'json' => self::of(FieldType::Text, textOnly: true),
            in_array($typeName, ['float', 'double', 'time', 'set'], true) => self::of(FieldType::String, ['length' => 255], textOnly: true),
            default => self::none(),
        };
    }

    /**
     * MariaDB wie MySQL, dazu eigene Typen: uuid (ab 10.7), inet4/inet6. JSON ist in MariaDB ein longtext
     * mit Regel json_valid(); als JSON erkennt ihn SchemaInspector (Typ "json").
     *
     * @return Mapping
     */
    private function mariaDb(string $typeName, string $type): array
    {
        return match ($typeName) {
            'uuid' => self::of(FieldType::String, ['length' => 36]),
            'inet4', 'inet6' => self::of(FieldType::String, ['length' => 255], textOnly: true),
            default => $this->mysql($typeName, $type),
        };
    }

    /**
     * SQL Server: $typeName ist der Typname (z. B. "nvarchar"), $type wie Laravel ihn meldet, z. B.
     * "nvarchar(200)", "varchar(max)", "decimal(12,2)". Laravel nennt bei nchar/nvarchar die Länge in
     * Bytes (zwei je Zeichen).
     *
     * @return Mapping
     */
    private function sqlServer(string $typeName, string $type): array
    {
        $max = str_ends_with($type, '(max)');
        $arguments = preg_match('/^[a-z0-9]+\((\d+)(?:\s*,\s*(\d+))?\)/', $type, $m) === 1 ? [(int) $m[1], isset($m[2]) ? (int) $m[2] : 0] : null;
        $characters = $arguments === null ? null : (in_array($typeName, ['nchar', 'nvarchar', 'sysname'], true) ? intdiv($arguments[0], 2) : $arguments[0]);

        return match (true) {
            in_array($typeName, ['varchar', 'nvarchar', 'char', 'nchar'], true) && $max => self::of(FieldType::Text),
            in_array($typeName, ['varchar', 'nvarchar', 'char', 'nchar'], true) && $characters !== null => self::of(FieldType::String, ['length' => max(1, $characters)]),
            $typeName === 'sysname' => self::of(FieldType::String, ['length' => 128]),
            in_array($typeName, ['text', 'ntext'], true) => self::of(FieldType::Text),
            in_array($typeName, ['tinyint', 'smallint', 'int', 'bigint'], true) => self::of(FieldType::Integer),
            $typeName === 'bit' => self::of(FieldType::Boolean),
            in_array($typeName, ['decimal', 'numeric'], true) && $arguments !== null => self::of(FieldType::Decimal, ['precision' => $arguments[0], 'scale' => $arguments[1]]),
            $typeName === 'money' => self::of(FieldType::Decimal, ['precision' => 19, 'scale' => 4]),
            $typeName === 'smallmoney' => self::of(FieldType::Decimal, ['precision' => 10, 'scale' => 4]),
            $typeName === 'date' => self::of(FieldType::Date),
            in_array($typeName, ['datetime', 'datetime2', 'smalldatetime'], true) => self::of(FieldType::DateTime),
            // Wie char(36) bei MySQL: als Text mit fester Länge, Schlüssel per newid() vergibt SAFT.
            $typeName === 'uniqueidentifier' => self::of(FieldType::String, ['length' => 36]),
            $typeName === 'xml' => self::of(FieldType::Text, textOnly: true),
            in_array($typeName, ['float', 'real', 'time', 'datetimeoffset'], true) => self::of(FieldType::String, ['length' => 255], textOnly: true),
            default => self::none(),
        };
    }

    /**
     * Werte eines MySQL-enum, z. B. "enum('geplant','laufend')" → ['geplant', 'laufend'].
     *
     * @return list<string>
     */
    public static function enumValues(string $type): array
    {
        if (preg_match('/^enum\((.*)\)$/is', $type, $m) !== 1 || preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/s", $m[1], $values) === 0) {
            return [];
        }

        return array_map(static fn (string $v): string => stripcslashes(str_replace("''", "'", $v)), $values[1]);
    }

    private static function longestEnumValue(string $type): int
    {
        return max(1, ...array_map('mb_strlen', self::enumValues($type) ?: ['']));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Mapping
     */
    private static function of(FieldType $type, array $options = [], bool $textOnly = false): array
    {
        return ['type' => $type, 'options' => $options, 'textOnly' => $textOnly];
    }

    /** @return Mapping */
    private static function none(): array
    {
        return ['type' => null, 'options' => [], 'textOnly' => false];
    }
}
