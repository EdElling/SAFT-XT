<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Schema\FieldType;
use App\ExternalData\CheckConstraintInfo;
use App\ExternalData\CheckTranslation;
use App\ExternalData\ConnectionDriver;
use App\ExternalData\ExternalTypeMapper;
use App\ExternalData\MySqlCheckTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Typen und CHECK-Regeln externer MySQL- und MariaDB-Datenbanken (Formen wie in information_schema). */
class MySqlExternalTypesTest extends TestCase
{
    /** @var array<string, string> */
    private const array TYPES = ['typ' => 'string', 'jahr' => 'integer', 'ende' => 'date', 'beginn' => 'date', 'budget' => 'decimal', 'webseite' => 'string', 'status' => 'string'];

    /**
     * @return array<string, array{string, string, ?FieldType, array<string, mixed>, bool}>
     */
    public static function columnTypes(): array
    {
        return [
            'varchar' => ['varchar', 'varchar(200)', FieldType::String, ['length' => 200], false],
            'char' => ['char', 'char(36)', FieldType::String, ['length' => 36], false],
            'text' => ['mediumtext', 'mediumtext', FieldType::Text, [], false],
            'boolean' => ['tinyint', 'tinyint(1)', FieldType::Boolean, [], false],
            'tinyint' => ['tinyint', 'tinyint', FieldType::Integer, [], false],
            'int unsigned' => ['int', 'int unsigned', FieldType::Integer, [], false],
            'bigint' => ['bigint', 'bigint', FieldType::Integer, [], false],
            'year' => ['year', 'year', FieldType::Integer, ['group_digits' => false], false],
            'decimal' => ['decimal', 'decimal(12,2)', FieldType::Decimal, ['precision' => 12, 'scale' => 2], false],
            'date' => ['date', 'date', FieldType::Date, [], false],
            'datetime' => ['datetime', 'datetime', FieldType::DateTime, [], false],
            'timestamp' => ['timestamp', 'timestamp', FieldType::DateTime, [], false],
            'enum' => ['enum', "enum('geplant','abgeschlossen')", FieldType::String, ['length' => 13], false],
            'json' => ['json', 'json', FieldType::Text, [], true],
            'double' => ['double', 'double', FieldType::String, ['length' => 255], true],
            'blob' => ['blob', 'blob', null, [], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('columnTypes')]
    public function test_maps_mysql_column_types(string $typeName, string $type, ?FieldType $expected, array $options, bool $textOnly): void
    {
        $mapping = (new ExternalTypeMapper)->map(ConnectionDriver::Mysql, $typeName, $type);

        $this->assertSame($expected, $mapping['type']);
        $this->assertSame($options, $mapping['options']);
        $this->assertSame($textOnly, $mapping['textOnly']);
    }

    public function test_maps_mariadb_types_and_falls_back_to_mysql(): void
    {
        $mapper = new ExternalTypeMapper;
        $map = static fn (string $name, string $type): array => $mapper->map(ConnectionDriver::Mariadb, $name, $type);

        $this->assertSame([FieldType::String, ['length' => 36], false], array_values($map('uuid', 'uuid')));
        $this->assertSame([FieldType::String, ['length' => 255], true], array_values($map('inet6', 'inet6')));
        // Anzeigebreite int(11) ändert nichts; tinyint(1) bleibt Ja/Nein wie bei MySQL.
        $this->assertSame(FieldType::Integer, $map('int', 'int(11)')['type']);
        $this->assertSame(FieldType::Boolean, $map('tinyint', 'tinyint(1)')['type']);
        $this->assertSame(['precision' => 12, 'scale' => 2], $map('decimal', 'decimal(12,2)')['options']);
    }

    public function test_reads_enum_values_with_quotes_and_commas(): void
    {
        $this->assertSame(['geplant', "lauf'end", 'a,b'], ExternalTypeMapper::enumValues("enum('geplant','lauf''end','a,b')"));
    }

    public function test_translates_value_list_from_information_schema(): void
    {
        $translation = $this->translate("(`typ` in (_utf8mb4\\'hochschule\\',_utf8mb4\\'forschungsinstitut\\',_utf8mb4\\'behoerde\\'))");

        $this->assertSame('typ', $translation?->column);
        $this->assertSame(['hochschule', 'forschungsinstitut', 'behoerde'], $translation?->values);
    }

    public function test_translates_between_into_min_and_max(): void
    {
        $translation = $this->translate('(`jahr` between 1900 and 2100)');

        $this->assertSame(['jahr', 1900, 2100], [$translation?->column, $translation?->min, $translation?->max]);
    }

    public function test_translates_column_comparison_and_lower_bound(): void
    {
        $this->assertSame('>=', $this->translate('((`ende` is null) or (`ende` >= `beginn`))')?->rule?->operator);
        $this->assertSame(0, $this->translate('(`budget` >= 0)')?->min);
    }

    public function test_leaves_patterns_to_the_database(): void
    {
        $this->assertNull($this->translate("regexp_like(`webseite`,_utf8mb4\\'^https?://\\')"));
    }

    private function translate(string $clause): ?CheckTranslation
    {
        return (new MySqlCheckTranslator)->translate(new CheckConstraintInfo('regel', $clause, []), self::TYPES);
    }
}
