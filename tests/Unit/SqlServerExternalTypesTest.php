<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Schema\FieldType;
use App\ExternalData\CheckConstraintInfo;
use App\ExternalData\CheckTranslation;
use App\ExternalData\ConnectionDriver;
use App\ExternalData\ExternalTypeMapper;
use App\ExternalData\SqlServerCheckTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Typen und CHECK-Regeln externer SQL-Server-Datenbanken (Formen wie in information_schema). */
class SqlServerExternalTypesTest extends TestCase
{
    /** @var array<string, string> */
    private const array TYPES = ['typ' => 'string', 'status' => 'string', 'jahr' => 'integer', 'ende' => 'date', 'beginn' => 'date', 'budget' => 'decimal', 'webseite' => 'string'];

    /**
     * Typen, wie Laravel sie für SQL Server meldet (nchar/nvarchar mit Länge in Bytes).
     *
     * @return array<string, array{string, string, ?FieldType, array<string, mixed>, bool}>
     */
    public static function columnTypes(): array
    {
        return [
            'nvarchar' => ['nvarchar', 'nvarchar(200)', FieldType::String, ['length' => 100], false],
            'nchar' => ['nchar', 'nchar(4)', FieldType::String, ['length' => 2], false],
            'varchar' => ['varchar', 'varchar(50)', FieldType::String, ['length' => 50], false],
            'nvarchar(max)' => ['nvarchar', 'nvarchar(max)', FieldType::Text, [], false],
            'ntext' => ['ntext', 'ntext', FieldType::Text, [], false],
            'sysname' => ['sysname', 'sysname', FieldType::String, ['length' => 128], false],
            'bit' => ['bit', 'bit', FieldType::Boolean, [], false],
            'tinyint' => ['tinyint', 'tinyint', FieldType::Integer, [], false],
            'bigint' => ['bigint', 'bigint', FieldType::Integer, [], false],
            'decimal' => ['decimal', 'decimal(12,2)', FieldType::Decimal, ['precision' => 12, 'scale' => 2], false],
            'numeric' => ['numeric', 'numeric(5,0)', FieldType::Decimal, ['precision' => 5, 'scale' => 0], false],
            'money' => ['money', 'money', FieldType::Decimal, ['precision' => 19, 'scale' => 4], false],
            'date' => ['date', 'date', FieldType::Date, [], false],
            'datetime2' => ['datetime2', 'datetime2(7)', FieldType::DateTime, [], false],
            'smalldatetime' => ['smalldatetime', 'smalldatetime', FieldType::DateTime, [], false],
            'uniqueidentifier' => ['uniqueidentifier', 'uniqueidentifier', FieldType::String, ['length' => 36], false],
            'datetimeoffset' => ['datetimeoffset', 'datetimeoffset(7)', FieldType::String, ['length' => 255], true],
            'float' => ['float', 'float(53)', FieldType::String, ['length' => 255], true],
            'xml' => ['xml', 'xml', FieldType::Text, [], true],
            'varbinary' => ['varbinary', 'varbinary(max)', null, [], false],
            'rowversion' => ['timestamp', 'timestamp', null, [], false],
            'geography' => ['geography', 'geography', null, [], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('columnTypes')]
    public function test_maps_sql_server_column_types(string $typeName, string $type, ?FieldType $expected, array $options, bool $textOnly): void
    {
        $mapping = (new ExternalTypeMapper)->map(ConnectionDriver::Sqlsrv, $typeName, $type);

        $this->assertSame($expected, $mapping['type']);
        $this->assertSame($options, $mapping['options']);
        $this->assertSame($textOnly, $mapping['textOnly']);
    }

    public function test_or_chain_becomes_value_list_in_original_order(): void
    {
        $translation = $this->translate("([typ]='behoerde' OR [typ]='unternehmen' OR [typ]='forschungsinstitut' OR [typ]='hochschule')");
        $this->assertSame(['typ', ['hochschule', 'forschungsinstitut', 'unternehmen', 'behoerde']], [$translation?->column, $translation?->values]);

        // Unicode-Zeichenketten (N'…') wie bei Laravels enum; Hochkomma im Wert.
        $translation = $this->translate("([status]=N'abgebrochen' OR [status]=N'lauf''end' OR [status]=N'geplant')");
        $this->assertSame(['geplant', "lauf'end", 'abgebrochen'], $translation?->values);
    }

    public function test_mixed_columns_in_an_or_chain_are_not_a_value_list(): void
    {
        $this->assertNull($this->translate("([typ]='a' OR [status]='b')")?->values);
    }

    public function test_bounds_with_parenthesised_numbers(): void
    {
        $translation = $this->translate('([jahr]>=(1900) AND [jahr]<=(2100))');
        $this->assertSame(['jahr', 1900, 2100], [$translation?->column, $translation?->min, $translation?->max]);
        $this->assertSame(0, $this->translate('([budget]>=(0))')?->min);
    }

    public function test_column_comparison(): void
    {
        $this->assertSame('>=', $this->translate('([ende] IS NULL OR [ende]>=[beginn])')?->rule?->operator);
    }

    public function test_leaves_patterns_to_the_database(): void
    {
        $this->assertNull($this->translate("([webseite] like 'http%://%')"));
    }

    private function translate(string $clause): ?CheckTranslation
    {
        return (new SqlServerCheckTranslator)->translate(new CheckConstraintInfo('regel', $clause, []), self::TYPES);
    }
}
