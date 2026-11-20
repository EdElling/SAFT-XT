<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\Definition\AppDefinition;
use App\Apps\Update\ChangeExecutor;
use App\Apps\Update\ChangePlan;
use App\Apps\Update\ChangePlanner;
use App\Apps\Update\ChangeStep;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Aktualisieren einer App aus einem Paket (B-107, Etappe 6a): Änderungsplan mit drei Ständen, Ausführen. */
class AppUpdateTest extends DynamicDataTestCase
{
    private User $admin;

    private App $sales;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
        $this->sales = self::ensureTestApp();
        $this->sales->update(['name' => ['de' => 'Verkauf'], 'prefix' => 'vk', 'version' => '1.0']);

        $this->service()->createTable(['app_id' => $this->sales->id, 'name' => 'vk_kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string'],
        ]);
        $this->service()->createTable(['app_id' => $this->sales->id, 'name' => 'vk_bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'string'],
        ]);
        DynamicRecord::forTableName('vk_bestellungen')->create(['nummer' => 'B-1', 'menge' => '3']);
        DynamicRecord::forTableName('vk_bestellungen')->create(['nummer' => 'B-2', 'menge' => '3']);

        // Ausgangsstand wie nach dem Installieren von Version 1.0.
        AppBaseline::query()->create(['app_id' => $this->sales->id, 'kind' => AppBaseline::INSTALLED, 'version' => '1.0', 'definition' => $this->definition(), 'installed_by' => $this->admin->id]);
        $this->storage = $this->fakeBackup();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function fakeBackup(): string
    {
        Storage::fake('local');
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });
        $storage = sys_get_temp_dir().'/saft-app-update-'.uniqid();
        $this->app->useStoragePath($storage);

        return $storage;
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return app(AppDefinition::class)->build($this->sales->refresh(), AppDefinition::PARTS, $this->admin);
    }

    private function key(string $table, string $field): string
    {
        return (string) CustomField::query()->where('name', $field)->whereHas('custom_table', static fn ($q) => $q->where('name', $table))->value('uuid');
    }

    /**
     * Paket „Version 2.0“: Feld eines Pakets ändern, hinzufügen oder entfernen.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function package(callable $change): array
    {
        $definition = $this->definition();
        $definition['app']['version'] = '2.0';

        return $change($definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function field(array $definition, string $table, string $field, callable $change): array
    {
        foreach ($definition['tables'] as $i => $t) {
            if ($t['name'] === $table) {
                foreach ($t['fields'] as $j => $f) {
                    if ($f['name'] === $field) {
                        $definition['tables'][$i]['fields'][$j] = $change($f);
                    }
                }
            }
        }

        return $definition;
    }

    private function plan(array $package): ChangePlan
    {
        return app(ChangePlanner::class)->plan($this->sales->refresh(), $package, $this->admin);
    }

    public function test_plan_takes_package_changes_keeps_local_ones_and_reports_conflicts(): void
    {
        $package = $this->package(function (array $d): array {
            // Paket: Bezeichnung von „menge“ und „firma“, neues Feld, neue Tabelle, „nummer“ entfernt.
            $d = self::field($d, 'vk_bestellungen', 'menge', static fn (array $f): array => [...$f, 'label' => ['de' => 'Anzahl']]);
            $d = self::field($d, 'vk_kunden', 'firma', static fn (array $f): array => [...$f, 'label' => ['de' => 'Firmenname']]);
            $orders = array_search('vk_bestellungen', array_column($d['tables'], 'name'), true);
            $d['tables'][$orders]['fields'] = array_values(array_filter($d['tables'][$orders]['fields'], static fn (array $f): bool => $f['name'] !== 'nummer'));
            $d['tables'][$orders]['fields'][] = ['key' => (string) Str::uuid(), 'name' => 'lieferdatum', 'label' => ['de' => 'Lieferdatum'], 'type' => 'date', 'sort_order' => 9];
            $d['tables'][] = ['key' => (string) Str::uuid(), 'name' => 'vk_lieferanten', 'label' => ['de' => 'Lieferanten'], 'fields' => [['key' => (string) Str::uuid(), 'name' => 'name', 'label' => ['de' => 'Name'], 'type' => 'string']]];

            return $d;
        });

        // Lokal: „menge“ anders benannt (Konflikt), „firma“ unverändert, Kunden-Beschreibung ergänzt (bleibt).
        $this->service()->updateField(CustomField::query()->where('uuid', $this->key('vk_bestellungen', 'menge'))->sole(), ['label' => ['de' => 'Stückzahl']]);
        $this->service()->updateTable(CustomTable::query()->where('name', 'vk_kunden')->sole(), ['description' => ['de' => 'Lokal ergänzt']]);

        $plan = $this->plan($package);
        $this->assertSame([], $plan->blockers);
        $ids = array_map(static fn (ChangeStep $s): string => $s->id, $plan->steps);
        $menge = $this->key('vk_bestellungen', 'menge');
        $nummer = $this->key('vk_bestellungen', 'nummer');

        $this->assertContains('field:'.$menge.':conflict', $ids);
        $this->assertNotContains('field:'.$menge, $ids, 'Nur der Konflikt, keine Paketänderung.');
        $this->assertContains('field:'.$this->key('vk_kunden', 'firma'), $ids);
        $this->assertNotContains('table:'.CustomTable::query()->where('name', 'vk_kunden')->value('uuid'), $ids, 'Lokale Beschreibung bleibt.');
        // „nummer“ hat Daten: deaktivieren vorgewählt, löschen zerstörend und nicht vorgewählt.
        $this->assertSame([ChangeStep::RISKY, true], [$plan->step('field:'.$nummer.':deactivate')->level, $plan->step('field:'.$nummer.':deactivate')->preselected()]);
        $this->assertSame([ChangeStep::DESTRUCTIVE, false], [$plan->step('field:'.$nummer.':delete')->level, $plan->step('field:'.$nummer.':delete')->preselected()]);
        $this->assertFalse($plan->step('field:'.$menge.':conflict')->preselected(), 'Konflikt: behalten.');

        // Ausführen: Vorauswahl plus Konflikt übernehmen.
        $result = app(ChangeExecutor::class)->apply($this->sales, $plan, [...$plan->preselected(), 'field:'.$menge.':conflict'], false, $this->admin);

        $this->assertNotEmpty($result['steps']);
        $this->assertSame('Anzahl', CustomField::query()->where('uuid', $menge)->sole()->getTranslation('label', 'de'));
        $this->assertSame('Firmenname', CustomField::query()->where('uuid', $this->key('vk_kunden', 'firma'))->sole()->getTranslation('label', 'de'));
        $this->assertSame('Lokal ergänzt', CustomTable::query()->where('name', 'vk_kunden')->sole()->getTranslation('description', 'de'));
        $this->assertFalse(CustomField::query()->where('uuid', $nummer)->sole()->is_active, 'Deaktiviert, Daten bleiben.');
        $this->assertSame(2, DynamicRecord::forTableName('vk_bestellungen')->whereNotNull('nummer')->count());
        $new = CustomTable::query()->where('name', 'vk_lieferanten')->sole();
        $this->assertSame([$this->sales->id, $package['tables'][2]['key']], [$new->app_id, $new->uuid]);
        $this->assertSame('date', CustomField::query()->where('name', 'lieferdatum')->sole()->type->value);

        $this->assertSame('2.0', $this->sales->fresh()->version);
        $baseline = AppBaseline::query()->where('app_id', $this->sales->id)->latest('id')->first();
        $this->assertSame([AppBaseline::UPDATED, '2.0'], [$baseline->kind, $baseline->version]);
        $this->assertCount(1, File::glob($this->storage.'/backups/*'), 'Vorher gesichert.');

        // Dasselbe Paket noch einmal: nichts mehr zu tun (deaktiviertes Feld bleibt, bis man es löscht).
        $this->assertSame([], array_map(static fn (ChangeStep $s): string => $s->id, $this->plan($package)->steps));
    }

    public function test_destructive_steps_need_confirmation_and_impossible_type_changes_are_blocked(): void
    {
        $nummer = $this->key('vk_bestellungen', 'nummer');
        $menge = $this->key('vk_bestellungen', 'menge');
        $firma = $this->key('vk_kunden', 'firma');
        $package = $this->package(function (array $d): array {
            // Text → Ganzzahl mit Daten: wie im Table-Designer nicht möglich; Umbenennen schon.
            $d = self::field($d, 'vk_bestellungen', 'menge', static fn (array $f): array => [...$f, 'type' => 'integer']);
            $d = self::field($d, 'vk_kunden', 'firma', static fn (array $f): array => [...$f, 'name' => 'firmenname']);
            $orders = array_search('vk_bestellungen', array_column($d['tables'], 'name'), true);
            $d['tables'][$orders]['fields'] = array_values(array_filter($d['tables'][$orders]['fields'], static fn (array $f): bool => $f['name'] !== 'nummer'));

            return $d;
        });
        $plan = $this->plan($package);

        $type = $plan->step('field:'.$menge);
        $this->assertSame(ChangeStep::RISKY, $type->level);
        $this->assertStringContainsString('2 Werte werden umgewandelt', implode(' ', $type->notes));
        $this->assertStringContainsString('nur bei leerer Spalte', (string) $type->blocked);
        $this->assertFalse($type->selectable());
        $this->assertStringContainsString('firma → firmenname', implode(' ', $plan->step('field:'.$firma)->notes));

        $selected = [...$plan->preselected(), 'field:'.$nummer.':delete', 'field:'.$menge];

        try {
            app(ChangeExecutor::class)->apply($this->sales, $plan, $selected, false, $this->admin);
            $this->fail('Bestätigung erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('ausdrücklich bestätigen', $e->getMessage());
        }

        app(ChangeExecutor::class)->apply($this->sales, $plan, $selected, true, $this->admin);
        $this->assertNull(CustomField::query()->where('uuid', $nummer)->first(), 'Gelöscht.');
        $this->assertFalse($this->appSchema()->hasColumn($this->qualified('vk_bestellungen'), 'nummer'));
        $this->assertSame('string', CustomField::query()->where('uuid', $menge)->sole()->type->value, 'Gesperrter Schritt wird nicht ausgeführt.');
        $this->assertSame('firmenname', CustomField::query()->where('uuid', $firma)->sole()->name);
    }

    public function test_failed_step_stops_and_keeps_the_baseline(): void
    {
        // „menge“ eindeutig machen geht nicht: zweimal „3“.
        $package = $this->package(fn (array $d): array => self::field($d, 'vk_bestellungen', 'menge', static fn (array $f): array => [...$f, 'is_unique' => true]));
        $plan = $this->plan($package);
        $this->assertStringContainsString('doppelte Werte', implode(' ', $plan->step('field:'.$this->key('vk_bestellungen', 'menge'))->notes));

        try {
            app(ChangeExecutor::class)->apply($this->sales, $plan, $plan->preselected(), false, $this->admin);
            $this->fail('Abbruch erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Aktualisieren abgebrochen', $e->getMessage());
        }

        $this->assertSame('1.0', $this->sales->fresh()->version);
        $this->assertSame(AppBaseline::INSTALLED, AppBaseline::query()->where('app_id', $this->sales->id)->latest('id')->value('kind'));
    }

    public function test_other_app_is_refused_and_without_baseline_every_difference_is_a_conflict(): void
    {
        $other = $this->package(static fn (array $d): array => array_replace_recursive($d, ['app' => ['key' => (string) Str::uuid()]]));
        $this->assertStringContainsString('anderer Schlüssel', implode(' ', $this->plan($other)->blockers));

        AppBaseline::query()->where('app_id', $this->sales->id)->delete();
        $package = $this->package(fn (array $d): array => self::field($d, 'vk_bestellungen', 'menge', static fn (array $f): array => [...$f, 'label' => ['de' => 'Anzahl']]));
        $plan = $this->plan($package);

        $this->assertStringContainsString('keinen Ausgangsstand', implode(' ', $plan->warnings));
        $step = $plan->step('field:'.$this->key('vk_bestellungen', 'menge').':conflict');
        $this->assertNotNull($step);
        $this->assertFalse($step->preselected());
    }

    public function test_fields_used_by_other_apps_cannot_be_withdrawn(): void
    {
        $shop = App::query()->create(['name' => ['de' => 'Shop'], 'prefix' => 'shop', 'sort_order' => 2]);
        $customers = CustomTable::query()->where('name', 'vk_kunden')->sole();
        $this->service()->updateInterface($customers, true, ['firma']);
        $this->service()->createTable(['app_id' => $shop->id, 'name' => 'shop_auftraege', 'label' => 'Aufträge'], [
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'vk_kunden', 'display_fields' => ['firma']]],
        ]);
        AppBaseline::query()->where('app_id', $this->sales->id)->update(['definition' => json_encode($this->definition())]);

        $package = $this->package(function (array $d): array {
            $i = array_search('vk_kunden', array_column($d['tables'], 'name'), true);
            unset($d['tables'][$i]['is_shared']);
            $d['tables'][$i]['fields'] = array_map(static fn (array $f): array => array_diff_key($f, ['is_shared' => true]), $d['tables'][$i]['fields']);

            return $d;
        });
        $step = $this->plan($package)->step('table:'.$customers->uuid);

        $this->assertNotNull($step->blocked);
        $this->assertStringContainsString('shop_auftraege.kunde_id', $step->blocked);
        $this->assertFalse($step->selectable());
    }

    public function test_update_dialog_runs_the_selected_steps(): void
    {
        $key = (string) Str::uuid();
        $package = $this->package(function (array $d) use ($key): array {
            $i = array_search('vk_kunden', array_column($d['tables'], 'name'), true);
            $d['tables'][$i]['fields'][] = ['key' => $key, 'name' => 'ort', 'label' => ['de' => 'Ort'], 'type' => 'string', 'sort_order' => 5];

            return $d;
        });
        $file = UploadedFile::fake()->createWithContent('vk-2.0.saft-app.json', (string) json_encode($package));

        Livewire::test(ListApps::class)
            ->callTableAction('updateApp', $this->sales, ['file' => [$file], 'steps' => ['field:'.$key]])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('apps.update.done', ['name' => 'Verkauf']));

        $this->assertSame($key, CustomField::query()->where('name', 'ort')->sole()->uuid);
        $this->assertSame('2.0', $this->sales->fresh()->version);

        // Ohne Recht keine Aktion.
        $this->actingAs($this->userWithPermissions(['apps.view', 'apps.manage', 'apps.install']));
        Livewire::test(ListApps::class)->assertTableActionHidden('updateApp', $this->sales);
    }

    public function test_own_fields_stay_and_new_field_rules_are_checked_against_the_data(): void
    {
        // Paket: „nummer“ höchstens 2 Zeichen – beide Werte („B-1“, „B-2“) sind länger.
        $package = $this->package(fn (array $d): array => self::field($d, 'vk_bestellungen', 'nummer', static fn (array $f): array => [...$f, 'validation_rules' => ['max_length' => 2]]));

        // Danach in SAFT angelegt, mit Wert: bleibt, als Hinweis genannt.
        $this->service()->addField(CustomTable::query()->where('name', 'vk_bestellungen')->sole(), ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'string']);
        DynamicRecord::forTableName('vk_bestellungen')->where('nummer', 'B-1')->update(['notiz' => 'eigene Angabe']);
        $plan = $this->plan($package);

        $this->assertSame(['Feld · Bestellungen › notiz (1 Wert vorhanden)'], $plan->local);
        $this->assertNull($plan->step('field:'.$this->key('vk_bestellungen', 'notiz').':delete'), 'Eigene Felder werden nicht entfernt.');
        $rules = $plan->step('field:'.$this->key('vk_bestellungen', 'nummer'));
        $this->assertSame(ChangeStep::RISKY, $rules->level);
        $this->assertStringContainsString('2 Werte verletzen die neuen Feldregeln', implode(' ', $rules->notes));
    }
}
