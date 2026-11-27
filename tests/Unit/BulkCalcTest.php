<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Bulk\BulkCalc;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Sammeländerung „rechnen“ (B-133 S2, BULK_EDIT.md §12 Fragen 1, 2, 4): exakt dezimal, kaufmännisch, ohne Überlauf. */
class BulkCalcTest extends TestCase
{
    public function test_number_operators_and_rounding_to_the_field(): void
    {
        $this->assertSame('15.00', BulkCalc::calculate('10', 'add', '5', 2));
        $this->assertSame('7.50', BulkCalc::calculate('10', 'sub', '2.5', 2));
        $this->assertSame('0.30', BulkCalc::calculate('0.1', 'mul', '3', 2));
        $this->assertSame('3.33', BulkCalc::calculate('10', 'div', '3', 2));
        $this->assertSame('3', BulkCalc::calculate('10', 'div', '3', 0), 'Ganzzahl: 0 Stellen');
        $this->assertSame('10.50', BulkCalc::calculate('10', 'percent', '5', 2));
        $this->assertSame('9.00', BulkCalc::calculate('10', 'percent', '-10', 2));
        $this->assertSame('1.01', BulkCalc::calculate('1.005', 'mul', '1', 2), 'kaufmännisch, ab 5 weg von 0');
        $this->assertSame('-1.01', BulkCalc::calculate('-1.005', 'mul', '1', 2));
    }

    public function test_rounding_to_digits_or_a_multiple(): void
    {
        // Tarif 12.34 um 5 % = 12.957 → auf 0.05 = 12.95; auf 1 Stelle = 13.00.
        $this->assertSame('12.95', BulkCalc::calculate('12.34', 'percent', '5', 2, BulkCalc::ROUND_MULTIPLE, '0.05'));
        $this->assertSame('13.00', BulkCalc::calculate('12.34', 'percent', '5', 2, BulkCalc::ROUND_DIGITS, '1'));
        $this->assertSame('12.98', BulkCalc::calculate('12.375', 'add', '0.6', 2, BulkCalc::ROUND_MULTIPLE, '0.02'), '12.975 / 0.02 = 648.75 → 649');
        $this->assertSame('130', BulkCalc::calculate('123', 'add', '2', 0, BulkCalc::ROUND_MULTIPLE, '10'), '125 → 130 (ab 5 weg von 0)');
    }

    public function test_empty_stays_empty(): void
    {
        $this->assertNull(BulkCalc::calculate(null, 'add', '5', 2));
        $this->assertNull(BulkCalc::calculate('', 'percent', '5', 2));
        $this->assertNull(BulkCalc::shift(null, 'add_days', 30, false));
    }

    public function test_dates_shift_by_days_and_months_without_overflow(): void
    {
        $this->assertSame('2026-12-27', BulkCalc::shift('2026-11-27', 'add_days', 30, false));
        $this->assertSame('2026-10-28', BulkCalc::shift(CarbonImmutable::parse('2026-11-27'), 'sub_days', 30, false));
        $this->assertSame('2026-02-28', BulkCalc::shift('2026-01-31', 'add_months', 1, false));
        $this->assertSame('2028-02-29', BulkCalc::shift('2028-03-31', 'sub_months', 1, false));
        $this->assertSame('2027-01-31', BulkCalc::shift('2026-01-31', 'add_months', 12, false));
        $this->assertSame('2026-02-28 14:30:00', BulkCalc::shift('2026-01-31 14:30:00', 'add_months', 1, true), 'Uhrzeit bleibt');
    }

    public function test_number_input_and_multiples(): void
    {
        $this->assertSame('1.05', BulkCalc::number('1,05'));
        $this->assertSame('1000', BulkCalc::number("1'000"));
        $this->assertNull(BulkCalc::number('abc'));
        $this->assertTrue(BulkCalc::validMultiple('0.05', 2));
        $this->assertFalse(BulkCalc::validMultiple('0.005', 2));
        $this->assertFalse(BulkCalc::validMultiple('0', 2));
        $this->assertFalse(BulkCalc::validMultiple('-5', 0));
    }
}
