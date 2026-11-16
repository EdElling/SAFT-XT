<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\SchemaVerifier;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Eindeutige Werte unter MariaDB über unsichtbare Hilfsspalten (MariaDbDialect). Läuft nur mit
 * SAFT auf MariaDB (composer test:mariadb).
 */
class MariaDbDialectTest extends DynamicDataTestCase
{
    private CustomTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        if (app(DynamicDataNamespace::class)->connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Nur mit SAFT auf MariaDB (phpunit.mariadb.xml).');
        }

        $this->table = $this->service()->createTable(['name' => 'geraete', 'label' => 'Geräte'], [
            ['name' => 'inventarnr', 'label' => 'Inventarnummer', 'type' => 'string', 'options' => ['length' => 20], 'is_unique' => true],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
        ]);
    }

    /** @return list<string> */
    private function physicalColumns(): array
    {
        $namespace = app(DynamicDataNamespace::class);

        return $namespace->connection()->table('information_schema.columns')
            ->where('table_schema', $namespace->name())
            ->where('table_name', 'geraete')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->map(fn (mixed $c): string => (string) $c)
            ->all();
    }

    private function helper(string $field): string
    {
        return $this->table->fresh('fields')->fields->firstWhere('name', $field)->indexName('uq').'_v';
    }

    public function test_unique_only_among_active_records_with_invisible_helper(): void
    {
        $records = DynamicRecord::forTable($this->table->fresh());
        $first = $records->create(['inventarnr' => 'G-1', 'bezeichnung' => 'Beamer']);

        try {
            $records->create(['inventarnr' => 'G-1', 'bezeichnung' => 'Zweiter Beamer']);
            $this->fail('Doppelte Inventarnummer angenommen.');
        } catch (UniqueConstraintViolationException) {
        }

        // Nach dem Löschen ist die Nummer wieder frei.
        $first->delete();
        $records->create(['inventarnr' => 'G-1', 'bezeichnung' => 'Neuer Beamer']);

        $this->assertContains($this->helper('inventarnr'), $this->physicalColumns());
        $this->assertArrayNotHasKey($this->helper('inventarnr'), (array) $records->newQuery()->toBase()->first(), 'SELECT * liefert die Hilfsspalte nicht.');
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_rename_type_change_and_dropping_keep_helper_in_step(): void
    {
        $field = $this->table->fresh('fields')->fields->firstWhere('name', 'inventarnr');
        DynamicRecord::forTable($this->table->fresh())->create(['inventarnr' => 'G-7']);

        $this->service()->updateField($field, ['name' => 'nummer', 'options' => ['length' => 40]]);
        $this->assertContains($this->helper('nummer'), $this->physicalColumns());
        $this->assertSame([], app(SchemaVerifier::class)->issues());

        $this->expectDuplicateRejected();

        // Eindeutigkeit aufheben: Hilfsspalte verschwindet; dann das Feld löschen.
        $this->service()->updateField($field->fresh(), ['is_unique' => false]);
        $this->assertNotContains($this->helper('nummer'), $this->physicalColumns());

        $this->service()->updateField($field->fresh(), ['is_unique' => true]);
        $this->service()->updateField($field->fresh(), ['is_active' => false]);
        $this->service()->dropField($field->fresh(), 'nummer');
        $this->assertNotContains('nummer', $this->physicalColumns());
        $this->assertSame([], array_filter($this->physicalColumns(), fn (string $c): bool => str_ends_with($c, '_uq_v')));
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_verify_reports_a_missing_helper(): void
    {
        $namespace = app(DynamicDataNamespace::class);
        $namespace->connection()->statement('ALTER TABLE `'.$namespace->name().'`.`geraete` DROP INDEX `'.str_replace('_v', '', $this->helper('inventarnr')).'`, DROP COLUMN `'.$this->helper('inventarnr').'`');

        $this->assertNotSame([], app(SchemaVerifier::class)->issues());
    }

    private function expectDuplicateRejected(): void
    {
        try {
            DynamicRecord::forTable($this->table->fresh())->create(['nummer' => 'G-7']);
            $this->fail('Doppelte Nummer nach Umbenennen angenommen.');
        } catch (UniqueConstraintViolationException|BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
    }
}
