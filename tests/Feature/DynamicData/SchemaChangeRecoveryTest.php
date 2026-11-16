<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\SchemaVerifier;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FailingUniqueIndexDialect;

/**
 * Scheitert eine Schemaänderung mittendrin, bleiben Metadaten und Tabelle wie vorher – bei
 * PostgreSQL durch die Transaktion, bei MySQL durch das Angleichen nach dem Fehler.
 */
class SchemaChangeRecoveryTest extends DynamicDataTestCase
{
    private function mysql(): bool
    {
        return in_array(DB::connection('app_data')->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function expectSimulatedFailure(callable $change): void
    {
        FailingUniqueIndexDialect::install();

        try {
            $change();
            $this->fail('Simulierter DDL-Fehler erwartet');
        } catch (RuntimeException $e) {
            $this->assertSame(FailingUniqueIndexDialect::MESSAGE, $e->getMessage());
        }
    }

    /** @return list<string> */
    private function constraintNames(string $table): array
    {
        $qualified = $this->qualified($table);

        return [
            ...array_column($this->appSchema()->getIndexes($qualified), 'name'),
            ...array_column($this->appSchema()->getForeignKeys($qualified), 'name'),
        ];
    }

    public function test_failed_add_field_leaves_table_unchanged(): void
    {
        $table = $this->projects();

        $this->expectSimulatedFailure(fn () => $this->service()->addField($table, ['name' => 'code', 'type' => 'string', 'is_unique' => true]));

        $this->assertFalse($this->appSchema()->hasColumn($this->qualified('projekte'), 'code'));
        $this->assertFalse($table->fields()->where('name', 'code')->exists());
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_failed_field_change_restores_name_index_and_data(): void
    {
        $table = $this->service()->createTable(['name' => 'kunden'], [
            ['name' => 'nummer', 'type' => 'string', 'options' => ['length' => 100], 'is_sortable' => true],
        ]);
        DynamicRecord::forTable($table)->create(['nummer' => 'K-1']);
        $field = $table->fields()->first();

        $this->expectSimulatedFailure(fn () => $this->service()->updateField($field, [
            'name' => 'code', 'is_unique' => true, 'options' => ['length' => 200],
        ]));

        $this->assertTrue($this->appSchema()->hasColumn($this->qualified('kunden'), 'nummer'));
        $this->assertFalse($this->appSchema()->hasColumn($this->qualified('kunden'), 'code'));
        $this->assertSame('K-1', DynamicRecord::forTableName('kunden')->newQuery()->value('nummer'));
        $this->assertContains($field->indexName('idx'), $this->constraintNames('kunden'));
        $this->assertNotContains($field->indexName('uq'), $this->constraintNames('kunden'));
        $this->assertSame('nummer', $field->fresh()?->name);
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_failed_relation_change_restores_foreign_key(): void
    {
        $this->service()->createTable(['name' => 'kunden'], [['name' => 'name', 'type' => 'string']]);
        $orders = $this->service()->createTable(['name' => 'auftraege'], [
            ['name' => 'kunde_id', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
        ]);
        $field = $orders->fields()->first();

        $this->expectSimulatedFailure(fn () => $this->service()->updateField($field, ['name' => 'kunde', 'is_unique' => true]));

        $foreignKeys = $this->appSchema()->getForeignKeys($this->qualified('auftraege'));
        $this->assertSame([$field->indexName('fk')], array_column($foreignKeys, 'name'));
        $this->assertSame(['kunde_id'], $foreignKeys[0]['columns']);
        $this->assertContains($field->indexName('idx'), $this->constraintNames('auftraege'));
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_long_sortable_text_field_respects_index_limit(): void
    {
        $create = fn () => $this->service()->createTable(['name' => 'notizen'], [
            ['name' => 'titel', 'type' => 'string', 'options' => ['length' => 1000], 'is_sortable' => true],
        ]);

        if (! $this->mysql()) {
            $this->assertInstanceOf(CustomTable::class, $create());

            return;
        }

        try {
            $create();
            $this->fail('Indexgrenze erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('768', $e->getMessage());
        }

        $this->assertFalse(CustomTable::where('name', 'notizen')->exists());
        $this->assertFalse($this->appSchema()->hasTable($this->qualified('notizen')));
    }

    public function test_too_wide_table_is_reported_without_leftovers(): void
    {
        if (! $this->mysql()) {
            $this->markTestSkipped('Nur MySQL hat eine feste Zeilengrenze.');
        }

        $fields = array_map(static fn (int $i): array => ['name' => "text_{$i}", 'type' => 'string', 'options' => ['length' => 2000]], range(1, 10));

        try {
            $this->service()->createTable(['name' => 'breit'], $fields);
            $this->fail('Zeilengrenze erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertSame(__('tables.errors.row_too_large'), $e->getMessage());
        }

        $this->assertFalse(CustomTable::where('name', 'breit')->exists());
        $this->assertFalse($this->appSchema()->hasTable($this->qualified('breit')));
    }
}
