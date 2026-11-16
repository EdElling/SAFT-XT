<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Import\DataImporter;
use App\DynamicData\Import\ImportAnalyzer;
use App\DynamicData\Import\ImportColumn;
use App\DynamicData\Import\ImportResult;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/** Einspielen der Daten: Umwandlung, Blöcke, Protokoll, alles oder nichts. */
class DataImporterTest extends DynamicDataTestCase
{
    private string $dir;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->user = $this->superAdmin();
        $this->actingAs($this->user);
        $this->dir = sys_get_temp_dir().'/saft-import-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_values_of_all_types_are_imported_with_one_log_entry(): void
    {
        $csv = "Nr;Name;PLZ;Preis;Menge;Datum;Zeit;Aktiv;Notiz\n"
            ."1;Müller AG;01067;1.234,50;3;01.03.2026;01.03.2026 08:30;ja;\"zwei\nZeilen\"\n"
            ."2;Schmidt;80331;0,99;;2026-03-02;2026-03-02 17:00:05;nein;\n";
        $result = $this->import('artikel.csv', $csv, menuGroup: app(DataMenu::class)->defaultGroup());

        $this->assertSame(2, $result->rows);
        $rows = $this->rows('artikel');
        $this->assertSame(
            ['nr' => 1, 'name' => 'Müller AG', 'plz' => '01067', 'preis' => '1234.50', 'menge' => 3, 'datum' => '2026-03-01', 'aktiv' => true, 'notiz' => "zwei\nZeilen"],
            array_intersect_key($rows[0], array_flip(['nr', 'name', 'plz', 'preis', 'menge', 'datum', 'aktiv', 'notiz'])),
        );
        $this->assertStringStartsWith('2026-03-01 08:30:00', (string) $rows[0]['zeit']);
        $this->assertNull($rows[1]['menge']);
        $this->assertFalse($rows[1]['aktiv']);
        $this->assertSame([$this->user->id, $this->user->id], [$rows[1]['created_by'], $rows[1]['updated_by']]);
        $this->assertNotNull($rows[1]['created_at']);

        $this->assertSame('artikel', $result->menu?->route_parameters['table']);
        $log = Activity::query()->where('event', 'imported')->sole();
        $this->assertSame([$result->table->id, $this->user->id], [$log->subject_id, $log->causer_id]);
        $this->assertEquals(['datei' => 'artikel.csv', 'blatt' => null, 'zeilen' => 2, 'spalten' => 9], $log->attribute_changes['attributes']);
        $this->assertSame(0, Activity::query()->where('log_name', 'data')->where('event', 'created')->count(), 'Kein Eintrag je Datensatz.');
    }

    public function test_a_value_that_does_not_fit_rolls_everything_back(): void
    {
        $path = $this->file('kunden.csv', "Name;Menge\nA;1\nB;viele\n");
        $columns = app(ImportAnalyzer::class)->analyze($path, 'kunden.csv')->columns;
        // Typ wie nach einer falschen Wahl: „Menge“ als Ganzzahl, obwohl Text erkannt wurde.
        $wrong = new ImportColumn($columns[1]->index, 'Menge', 'menge', FieldType::Integer);
        $permissions = Permission::query()->count();

        try {
            app(DataImporter::class)->import($path, 'kunden.csv', null, ['name' => 'kunden', 'label' => 'Kunden'], [$columns[0], $wrong], app(DataMenu::class)->defaultGroup(), $this->user);
            $this->fail('Abbruch erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Zelle B3: „viele“ passt nicht zum Typ Ganzzahl. Bitte den Typ der Spalte ändern (z. B. Text) oder den Wert korrigieren.', $e->getMessage());
        }

        $this->assertNull(CustomTable::query()->where('name', 'kunden')->first());
        $this->assertFalse(app(DynamicDataNamespace::class)->connection()->getSchemaBuilder()->hasTable(app(DynamicDataNamespace::class)->qualify('kunden')));
        $this->assertSame(0, Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->count());
        $this->assertSame($permissions, Permission::query()->count());
        $this->assertSame(0, Activity::query()->where('event', 'imported')->count());
    }

    public function test_changed_file_is_rejected(): void
    {
        $path = $this->file('liste.csv', "Name;Ort\nA;B\n");
        $columns = app(ImportAnalyzer::class)->analyze($path, 'liste.csv')->columns;
        file_put_contents($path, "Vorname;Ort\nA;B\n");

        $this->expectExceptionMessage('Die Datei hat sich seit der Prüfung verändert. Bitte erneut hochladen.');
        app(DataImporter::class)->import($path, 'liste.csv', null, ['name' => 'liste', 'label' => 'Liste'], $columns, null, $this->user);
    }

    public function test_many_rows_and_many_columns_are_inserted_in_blocks(): void
    {
        $lines = ['Nr;Text;Betrag'];

        for ($i = 1; $i <= 2500; $i++) {
            $lines[] = "{$i};Zeile {$i};".number_format($i / 4, 2, ',', '.');
        }

        $result = $this->import('viele.csv', implode("\n", $lines)."\n");
        $this->assertSame(2500, $result->rows);
        $this->assertSame(2500, $this->table('viele')->count());
        $this->assertSame('625.00', (string) $this->table('viele')->where('nr', 2500)->value('betrag'));

        // 180 Spalten: höchstens 60'000 Platzhalter je Abfrage, also weniger als 500 Zeilen je Block.
        $header = implode(';', array_map(fn (int $c): string => "Spalte {$c}", range(1, 180)));
        $row = implode(';', range(1, 180));
        $result = $this->import('breit.csv', $header."\n".str_repeat($row."\n", 400));
        $this->assertSame(400, $this->table('breit')->count());
        $this->assertSame(180, $result->table->fields()->count());
        $this->assertSame(DataImporter::MAX_SORTABLE, $result->table->fields()->where('is_sortable', true)->count(), 'MySQL: höchstens 64 Indizes.');
    }

    private function import(string $name, string $content, int|string|null $menuGroup = null): ImportResult
    {
        $path = $this->file($name, $content);
        $analysis = app(ImportAnalyzer::class)->analyze($path, $name);
        $this->assertTrue($analysis->isImportable(), implode("\n", $analysis->messages()));

        return app(DataImporter::class)->import($path, $name, $analysis->sheet, ['name' => (string) $analysis->tableName, 'label' => $name], $analysis->columns, $menuGroup, $this->user);
    }

    private function file(string $name, string $content): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $content);

        return $path;
    }

    private function table(string $name): Builder
    {
        $namespace = app(DynamicDataNamespace::class);

        return $namespace->connection()->table($namespace->qualify($name));
    }

    /** @return list<array<string, mixed>> mit Wahrheitswerten als bool (MySQL liefert 0/1) */
    private function rows(string $table): array
    {
        return $this->table($table)->orderBy('id')->get()->map(function (object $row): array {
            $row = (array) $row;
            $row['aktiv'] = $row['aktiv'] === null ? null : (bool) $row['aktiv'];
            $row['nr'] = (int) $row['nr'];
            $row['menge'] = $row['menge'] === null ? null : (int) $row['menge'];
            $row['datum'] = substr((string) $row['datum'], 0, 10);

            return $row;
        })->all();
    }
}
