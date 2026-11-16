<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Import\ColumnProfile;
use App\DynamicData\Import\ImportColumn;
use App\DynamicData\Import\ImportValueParser as P;
use App\DynamicData\Schema\FieldType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Typerkennung des Tabellen-Imports: Zahlenformate, Datum, Ja/Nein, Text. */
class ImportTypeDetectionTest extends TestCase
{
    public function test_numbers_in_german_and_english_format(): void
    {
        $this->assertSame('-1234.56', P::number('-1.234,56', P::FORMAT_DE)['value']);
        $this->assertSame('1234.56', P::number('1,234.56', P::FORMAT_EN)['value']);
        $this->assertSame('1234', P::number('1.234', P::FORMAT_DE)['value']);
        $this->assertSame('1.234', P::number('1.234', P::FORMAT_EN)['value']);
        $this->assertSame(['value' => '0.5', 'digits' => 1, 'scale' => 1, 'leading_zero' => false], P::number('0,5', P::FORMAT_DE));
        $this->assertTrue(P::number('01067', P::FORMAT_DE)['leading_zero']);
        $this->assertNull(P::number('1.23.4', P::FORMAT_DE), 'Tausendergruppen müssen dreistellig sein.');
        $this->assertNull(P::number('01.234', P::FORMAT_DE), 'Gruppen nie mit führender Null.');
        $this->assertNull(P::number('12 kg', P::FORMAT_DE));
        $this->assertNull(P::number('1,5', P::FORMAT_EN));
        $this->assertSame('0.3', P::float(0.1 + 0.2)['value']);
        $this->assertSame('-2.5', P::float(-2.5)['value']);
    }

    public function test_dates_booleans_and_text(): void
    {
        $this->assertSame(['value' => '2026-03-01', 'time' => false], P::date('1.3.2026'));
        $this->assertSame(['value' => '2026-03-01 08:05:00', 'time' => true], P::date('01.03.2026 8:05'));
        $this->assertSame(['value' => '2026-03-01 08:05:09', 'time' => true], P::date('2026-03-01T08:05:09'));
        $this->assertNull(P::date('31.02.2026'));
        $this->assertNull(P::date('03/01/2026'), 'Amerikanische Schreibweise ist mehrdeutig.');
        $this->assertNull(P::date('1.3.26'), 'Zweistellige Jahre sind mehrdeutig.');
        $this->assertTrue(P::boolean(' Ja '));
        $this->assertFalse(P::boolean('FALSE'));
        $this->assertNull(P::boolean('1'), '1/0 bleibt eine Zahl.');
    }

    /**
     * @param  list<mixed>  $values
     * @param  array<string, int>  $options
     */
    #[DataProvider('columns')]
    public function test_column_type(array $values, FieldType $type, array $options = [], string $preferred = P::FORMAT_DE, ?string $note = null): void
    {
        $profile = new ColumnProfile;
        array_map($profile->add(...), $values);
        $guess = $profile->guess($preferred);

        $this->assertSame($type, $guess['type']);
        $this->assertSame($options, $guess['options']);

        if ($note !== null) {
            $this->assertContains($note, $guess['notes']);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public static function columns(): array
    {
        return [
            'Ganzzahlen mit Leerzellen' => [['1', '', '42', null, '-7'], FieldType::Integer],
            'Dezimal deutsch' => [['1.234,5', '0,25', '7'], FieldType::Decimal, ['precision' => 12, 'scale' => 2]],
            'Dezimal englisch' => [['1,234.5', '0.25'], FieldType::Decimal, ['precision' => 12, 'scale' => 2], P::FORMAT_EN],
            'mehrdeutig: deutsch bevorzugt' => [['1.234', '2.500'], FieldType::Integer, [], P::FORMAT_DE, 'number_format_de'],
            'mehrdeutig: englisch bevorzugt' => [['1.234', '2.500'], FieldType::Decimal, ['precision' => 12, 'scale' => 3], P::FORMAT_EN, 'number_format_en'],
            'PLZ mit führender Null' => [['01067', '80331'], FieldType::String, ['length' => 50], P::FORMAT_DE, 'leading_zeros'],
            'zu viele Stellen' => [['1234567890123456789'], FieldType::String, ['length' => 50], P::FORMAT_DE, 'number_too_long'],
            'Zahl und Text gemischt' => [['5', 'n/a'], FieldType::String, ['length' => 50]],
            'Datum' => [['01.03.2026', '2026-12-31'], FieldType::Date],
            'Datum und Zeit' => [['01.03.2026', '02.03.2026 14:00'], FieldType::DateTime],
            'Ja/Nein' => [['ja', 'Nein', ''], FieldType::Boolean],
            'Text bis 100' => [[str_repeat('x', 60)], FieldType::String, ['length' => 100]],
            'langer Text' => [[str_repeat('x', 300)], FieldType::Text],
            'mehrzeilig' => [["Zeile 1\nZeile 2"], FieldType::Text],
            'leer' => [['', null], FieldType::String, ['length' => 50], P::FORMAT_DE, 'empty_column'],
            'Excel-Zahlen' => [[3, 4.5, 1200], FieldType::Decimal, ['precision' => 12, 'scale' => 1]],
            'Excel-Ganzzahlen' => [[1, 0, 1], FieldType::Integer],
            'Excel-Wahrheitswerte' => [[true, false], FieldType::Boolean],
            'Excel-Datum' => [[new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-02')], FieldType::Date],
            'Excel-Datum mit Zeit' => [[new DateTimeImmutable('2026-03-01 10:30')], FieldType::DateTime],
            'Excel-Zahl neben Text' => [[5, 'fünf'], FieldType::String, ['length' => 50]],
        ];
    }

    public function test_values_are_converted_like_they_were_detected(): void
    {
        $decimal = new ImportColumn(0, 'Preis', 'preis', FieldType::Decimal, ['precision' => 12, 'scale' => 2], P::FORMAT_DE);
        $this->assertSame('1234.5', $decimal->convert(' 1.234,5 '));
        $this->assertSame('4.5', $decimal->convert(4.5));
        $this->assertNull($decimal->convert(''));

        $integer = new ImportColumn(1, 'Menge', 'menge', FieldType::Integer, numberFormat: P::FORMAT_EN);
        $this->assertSame(1234, $integer->convert('1,234'));

        $date = new ImportColumn(2, 'Zeit', 'zeit', FieldType::DateTime);
        $this->assertSame('2026-03-01 00:00:00', $date->convert('01.03.2026'));
        $this->assertSame('2026-03-01 10:30:00', $date->convert(new DateTimeImmutable('2026-03-01 10:30')));

        $this->assertTrue((new ImportColumn(3, 'Aktiv', 'aktiv', FieldType::Boolean))->convert('Ja'));
        $this->assertSame('01067', (new ImportColumn(4, 'PLZ', 'plz', FieldType::String, ['length' => 50]))->convert('01067'));
        $this->assertSame('AA', ImportColumn::letterFor(26));
        $this->assertSame('AZ', ImportColumn::letterFor(51));
    }
}
