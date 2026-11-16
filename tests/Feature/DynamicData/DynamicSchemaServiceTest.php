<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\SchemaVerifier;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FailingUniqueIndexDialect;

class DynamicSchemaServiceTest extends DynamicDataTestCase
{
    public function test_creates_physical_table_in_app_data_with_system_columns(): void
    {
        $table = $this->projects();

        $this->assertTrue($this->appSchema()->hasTable($this->qualified('projekte')));
        $this->assertFalse(Schema::hasTable('projekte'), 'Darf nicht in der Hauptdatenbank landen.');

        $columns = collect($this->appSchema()->getColumns($this->qualified('projekte')))->keyBy('name');
        $this->assertEqualsCanonicalizing(
            ['id', 'titel', 'budget', 'anzahl', 'aktiv', 'start', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
            $columns->keys()->all(),
        );
        $mysql = in_array(DB::connection('app_data')->getDriverName(), ['mysql', 'mariadb'], true);
        $this->assertSame($mysql ? 'varchar(100)' : 'character varying(100)', $columns['titel']['type']);
        $this->assertSame($mysql ? 'decimal(12,2)' : 'numeric(12,2)', $columns['budget']['type']);
        $this->assertTrue($columns['titel']['nullable'], 'Pflicht prüft die Anwendung, nicht die DB.');

        $this->assertCount(6, $table->fields);
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function maliciousTableNames(): array
    {
        return [
            'Schema-Präfix public' => ['public.users'],
            'Schema-Präfix app_data' => ['app_data.x'],
            'SQL-Injection' => ['x; drop table users'],
            'Anführungszeichen' => ['x"y'],
            'Großbuchstaben' => ['Projekte'],
            'reserviertes Wort' => ['select'],
            'Ziffer am Anfang' => ['1abc'],
            'System-Präfix' => ['pg_test'],
            'zu lang' => [str_repeat('a', 64)],
            'leer' => [''],
        ];
    }

    #[DataProvider('maliciousTableNames')]
    public function test_rejects_invalid_or_malicious_table_names(string $name): void
    {
        try {
            $this->service()->createTable(['name' => $name]);
            $this->fail('BusinessRuleException erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertSame('dynamic.invalid_identifier', $e->errorCode, 'Muss bereits an der Namensprüfung scheitern.');
        }

        $this->assertTrue(Schema::hasTable('users'), 'Systemtabelle muss unangetastet sein.');
        $this->assertSame(0, CustomTable::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedColumnNames(): array
    {
        return [
            'Systemspalte' => ['created_by'],
            'Primärschlüssel' => ['id'],
            'Eloquent-Eigenschaft' => ['exists'],
            'Eloquent-Intern' => ['attributes'],
            'SQL-Wort' => ['order'],
            'Schema-Präfix' => ['public.x'],
        ];
    }

    #[DataProvider('reservedColumnNames')]
    public function test_rejects_reserved_column_names(string $name): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->service()->createTable(['name' => 'tabelle'], [['name' => $name, 'type' => 'string']]);
    }

    public function test_metadata_and_ddl_are_atomic(): void
    {
        // DDL scheitert nach dem Speichern der Metadaten -> beides muss zurückgerollt werden.
        FailingUniqueIndexDialect::install();

        try {
            $this->service()->createTable(['name' => 'atomar'], [['name' => 'code', 'type' => 'string', 'is_unique' => true]]);
            $this->fail('Exception erwartet');
        } catch (RuntimeException $e) {
            $this->assertSame(FailingUniqueIndexDialect::MESSAGE, $e->getMessage(), 'Muss genau an der DDL scheitern.');
        }

        $this->assertSame(0, CustomTable::where('name', 'atomar')->count());
        $this->assertFalse($this->appSchema()->hasTable($this->qualified('atomar')));
    }

    public function test_rename_field_keeps_data(): void
    {
        $table = $this->projects();
        DynamicRecord::forTable($table)->create(['titel' => 'Alpha']);

        $this->service()->updateField($table->fields()->where('name', 'titel')->first(), ['name' => 'bezeichnung']);

        $this->assertSame('Alpha', DynamicRecord::forTableName('projekte')->newQuery()->value('bezeichnung'));
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_safe_type_change_keeps_data(): void
    {
        $table = $this->projects();
        DynamicRecord::forTable($table)->create(['anzahl' => 42]);

        $this->service()->updateField($table->fields()->where('name', 'anzahl')->first(), ['type' => 'decimal', 'options' => ['precision' => 22, 'scale' => 2]]);

        $this->assertSame('42.00', DynamicRecord::forTableName('projekte')->newQuery()->value('anzahl'));
    }

    public function test_unsafe_type_change_is_blocked_with_data_but_allowed_when_empty(): void
    {
        $table = $this->projects();
        $titel = $table->fields()->where('name', 'titel')->first();
        DynamicRecord::forTable($table)->create(['titel' => 'Text']);

        try {
            $this->service()->updateField($titel, ['type' => 'integer']);
            $this->fail('Ablehnung erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('nur bei leerer Spalte', $e->getMessage());
        }

        $this->assertSame('Text', DynamicRecord::forTableName('projekte')->newQuery()->value('titel'));

        // Leere Spalte: Typwechsel erlaubt.
        $status = $table->fields()->where('name', 'status')->first();
        $this->service()->updateField($status, ['type' => 'integer']);
        // MariaDB nennt die Anzeigebreite mit: bigint(20).
        $this->assertMatchesRegularExpression('/^bigint(\(20\))?$/', collect($this->appSchema()->getColumns($this->qualified('projekte')))->firstWhere('name', 'status')['type']);
    }

    public function test_string_length_can_grow_but_not_shrink_with_data(): void
    {
        $table = $this->projects();
        $titel = $table->fields()->where('name', 'titel')->first();
        DynamicRecord::forTable($table)->create(['titel' => 'x']);

        $this->service()->updateField($titel, ['options' => ['length' => 200]]);

        $this->expectException(BusinessRuleException::class);
        $this->service()->updateField($titel->fresh(), ['options' => ['length' => 50]]);
    }

    public function test_unique_ignores_soft_deleted_rows_and_detects_existing_duplicates(): void
    {
        $table = $this->service()->createTable(['name' => 'kunden'], [['name' => 'nummer', 'type' => 'string', 'is_unique' => true]]);
        $model = DynamicRecord::forTable($table);

        $first = $model->create(['nummer' => 'K-1']);

        try {
            DB::connection('app_data')->transaction(fn () => $model->create(['nummer' => 'K-1']));
            $this->fail('Unique-Verletzung erwartet');
        } catch (QueryException) {
        }

        $first->delete();
        $model->create(['nummer' => 'K-1']); // gelöschte Datensätze zählen nicht

        $field = $table->fields()->first();
        $this->service()->updateField($field, ['is_unique' => false]);
        $model->create(['nummer' => 'K-1']);

        $this->expectExceptionMessage('doppelte Werte');
        $this->service()->updateField($field->fresh(), ['is_unique' => true]);
    }

    public function test_relation_creates_foreign_key_and_protects_target_table(): void
    {
        $kunden = $this->service()->createTable(['name' => 'kunden'], [['name' => 'name', 'type' => 'string']]);
        $auftraege = $this->service()->createTable(['name' => 'auftraege'], [
            ['name' => 'kunde_id', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
        ]);

        $foreignKeys = $this->appSchema()->getForeignKeys($this->qualified('auftraege'));
        $this->assertSame('kunden', $foreignKeys[0]['foreign_table']);
        $this->assertSame(config('dynamic_data.namespace'), $foreignKeys[0]['foreign_schema']);

        $kunde = DynamicRecord::forTable($kunden)->create(['name' => 'ACME']);
        DynamicRecord::forTable($auftraege)->create(['kunde_id' => $kunde->id]);

        $this->service()->updateTable($kunden, ['is_active' => false]);

        $this->expectExceptionMessage('auftraege.kunde_id');
        $this->service()->dropTable($kunden->fresh(), 'kunden');
    }

    public function test_relation_to_system_tables_is_impossible(): void
    {
        $this->expectExceptionMessage('existiert nicht im Table-Designer');

        $this->service()->createTable(['name' => 'x'], [
            ['name' => 'user_id', 'type' => 'relation', 'options' => ['table' => 'users', 'display_field' => 'email']],
        ]);
    }

    public function test_drop_field_requires_deactivation_and_confirmation(): void
    {
        $table = $this->projects();
        $field = $table->fields()->where('name', 'anzahl')->first();

        foreach ([[$field, 'anzahl'], [null, 'falsch']] as [$candidate, $confirmation]) {
            if ($candidate === null) {
                $this->service()->updateField($field, ['is_active' => false]);
                $candidate = $field->fresh();
            }

            try {
                $this->service()->dropField($candidate, $confirmation);
                $this->fail('Ablehnung erwartet');
            } catch (BusinessRuleException) {
            }
        }

        $this->service()->dropField($field->fresh(), 'anzahl');

        $this->assertFalse($this->appSchema()->hasColumn($this->qualified('projekte'), 'anzahl'));
        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_drop_table_requires_deactivation_and_confirmation(): void
    {
        $table = $this->projects();

        $this->expectException(BusinessRuleException::class);
        try {
            $this->service()->dropTable($table, 'projekte');
        } finally {
            $this->service()->updateTable($table, ['is_active' => false]);
            $this->service()->dropTable($table->fresh(), 'projekte');

            $this->assertFalse($this->appSchema()->hasTable($this->qualified('projekte')));
            $this->assertSame(0, CustomField::count());
        }
    }

    public function test_technical_table_name_is_immutable(): void
    {
        $this->expectExceptionMessage('nicht geändert werden');

        $this->service()->updateTable($this->projects(), ['name' => 'anders']);
    }

    public function test_structured_validation_rejects_free_rules(): void
    {
        $this->expectExceptionMessage('nicht erlaubte Validierung: exists');

        $this->service()->createTable(['name' => 'x'], [
            ['name' => 'mail', 'type' => 'string', 'validation_rules' => ['exists' => 'public.users,email']],
        ]);
    }

    public function test_verify_detects_orphaned_physical_table(): void
    {
        $this->appSchema()->create($this->qualified('wildwuchs'), fn ($t) => $t->id());

        $this->assertContains('Tabelle „wildwuchs“: existiert in der Datenbank ohne Metadaten.', app(SchemaVerifier::class)->issues());
        $this->artisan('app-data:verify')->assertFailed();
    }

    public function test_migrate_fresh_listener_resets_namespace(): void
    {
        $this->projects();

        event(new DatabaseRefreshed);

        $this->assertFalse($this->appSchema()->hasTable($this->qualified('projekte')));
        $this->assertTrue(collect($this->appSchema()->getSchemas())->contains('name', config('dynamic_data.namespace')));
    }
}
