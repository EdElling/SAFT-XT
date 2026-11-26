<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\Definition\AppDefinition;
use App\Apps\Update\ChangeExecutor;
use App\Apps\Update\ChangePlan;
use App\Apps\Update\ChangePlanner;
use App\Apps\Update\ChangeStep;
use App\Apps\Update\ChangeText;
use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\AppManual;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
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

    public function test_grouped_number_sequence_is_created_after_its_select_and_changed_codes_are_risky(): void
    {
        $art = ['key' => (string) Str::uuid(), 'name' => 'art', 'label' => ['de' => 'Art'], 'type' => 'select', 'is_required' => true, 'sort_order' => 9,
            'options' => ['options' => [['value' => 'Auftrag', 'label' => ['de' => 'Auftrag']], ['value' => 'Gutschrift', 'label' => ['de' => 'Gutschrift']]]]];
        $beleg = ['key' => (string) Str::uuid(), 'name' => 'beleg', 'label' => ['de' => 'Beleg'], 'type' => 'number', 'sort_order' => 8,
            'options' => ['pattern' => '{GROUP}-{#}', 'reset' => 'never', 'group_field' => 'art', 'group_codes' => ['Auftrag' => 'A', 'Gutschrift' => 'G'], 'start' => 1, 'length' => 60]];
        $package = $this->package(static function (array $d) use ($art, $beleg): array {
            $orders = array_search('vk_bestellungen', array_column($d['tables'], 'name'), true);
            // Der Nummernkreis steht vor seinem Gruppenfeld.
            $d['tables'][$orders]['fields'] = [...$d['tables'][$orders]['fields'], $beleg, $art];

            return $d;
        });

        app(ChangeExecutor::class)->apply($this->sales, $plan = $this->plan($package), $plan->preselected(), false, $this->admin);
        $this->assertSame('G-1', DynamicRecord::forTableName('vk_bestellungen')->create(['nummer' => 'B-3', 'art' => 'Gutschrift'])->beleg);

        // 3.0: Kurzzeichen geändert, neue Wahl mit eigenem Kurzzeichen – heikel; Nummernkreis vor dem Auswahlfeld geändert.
        $next = self::field($this->definition(), 'vk_bestellungen', 'beleg', static fn (array $f): array => [...$f, 'options' => [...$f['options'], 'group_codes' => ['Auftrag' => 'AU', 'Gutschrift' => 'G', 'Rückgabe' => 'R']]]);
        $next = self::field($next, 'vk_bestellungen', 'art', static fn (array $f): array => [...$f, 'options' => ['options' => [...$f['options']['options'], ['value' => 'Rückgabe', 'label' => ['de' => 'Rückgabe']]]]]);
        $next['app']['version'] = '3.0';
        $plan = $this->plan($next);
        $step = $plan->step('field:'.$this->key('vk_bestellungen', 'beleg'));

        $this->assertSame(ChangeStep::RISKY, $step->level);
        $this->assertContains(__('apps.update.notes.number_group', ['field' => 'art']), $step->notes);

        app(ChangeExecutor::class)->apply($this->sales, $plan, $plan->preselected(), false, $this->admin);
        $this->assertSame('R-1', DynamicRecord::forTableName('vk_bestellungen')->create(['nummer' => 'B-4', 'art' => 'Rückgabe'])->beleg);
        $this->assertSame('AU-1', DynamicRecord::forTableName('vk_bestellungen')->create(['nummer' => 'B-5', 'art' => 'Auftrag'])->beleg);
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

        // Dieselbe Datei nochmals: nichts zu tun – „Schliessen“, keine Sicherung, kein neuer Ausgangsstand.
        $baselines = AppBaseline::query()->where('app_id', $this->sales->id)->count();
        $this->assertSame([], $this->plan($package)->selectable());
        Livewire::test(ListApps::class)
            ->callTableAction('updateApp', $this->sales, ['file' => [UploadedFile::fake()->createWithContent('vk-2.0.saft-app.json', (string) json_encode($package))], 'steps' => []])
            ->assertHasNoTableActionErrors()
            ->assertNotNotified(__('apps.update.done', ['name' => 'Verkauf']));
        $this->assertSame($baselines, AppBaseline::query()->where('app_id', $this->sales->id)->count());
        $this->assertCount(1, File::glob($this->storage.'/backups/*'), 'Nur die Sicherung des ersten Laufs.');

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

    public function test_reports_links_menus_and_manual_are_updated(): void
    {
        $orders = CustomTable::query()->where('name', 'vk_bestellungen')->sole();
        $customers = CustomTable::query()->where('name', 'vk_kunden')->sole();
        $this->service()->updateField($customers->fields()->where('name', 'firma')->sole(), ['is_unique' => true]);
        $link = ReportLink::query()->create(['label' => ['de' => 'Zur Firma'], 'source_table_id' => $orders->id, 'source_field_id' => $this->fieldId('vk_bestellungen', 'nummer'),
            'target_table_id' => $customers->id, 'target_field_id' => $this->fieldId('vk_kunden', 'firma')]);
        $report = Report::query()->create(['name' => 'Mengen', 'owner_id' => $this->admin->id, 'is_shared' => true, 'definition' => ['base' => $orders->id, 'fields' => [
            ['key' => 'n', 'path' => [], 'field' => $this->fieldId('vk_bestellungen', 'nummer')],
            ['key' => 'f', 'path' => [-$link->id], 'field' => $this->fieldId('vk_kunden', 'firma')],
        ]]]);
        $old = Report::query()->create(['name' => 'Alt', 'owner_id' => $this->admin->id, 'is_shared' => true, 'definition' => ['base' => $orders->id, 'fields' => [['key' => 'n', 'path' => [], 'field' => $this->fieldId('vk_bestellungen', 'nummer')]]]]);
        $group = Menu::query()->create(['app_id' => $this->sales->id, 'label' => ['de' => 'Verkauf'], 'sort_order' => 10]);
        app(DataMenu::class)->create($orders, $group->id);
        AppManual::query()->create(['app_id' => $this->sales->id, 'locale' => 'de', 'content' => "# Verkauf\n"]);
        AppBaseline::query()->where('app_id', $this->sales->id)->update(['definition' => json_encode($this->definition())]);

        $newMenu = (string) Str::uuid();
        $newReport = (string) Str::uuid();
        $package = $this->package(function (array $d) use ($newMenu, $newReport): array {
            $d['reports'] = array_values(array_filter($d['reports'], static fn (array $r): bool => $r['name'] !== 'Alt'));
            $d['reports'][0]['name'] = 'Mengen je Bestellung';
            $d['reports'][] = [...$d['reports'][0], 'key' => $newReport, 'name' => 'Neu', 'definition' => [...$d['reports'][0]['definition'], 'fields' => [$d['reports'][0]['definition']['fields'][0]]]];
            // Gruppe umbenannt: Pfade im Paket lauten „Vertrieb …“.
            $d['menus'] = array_map(static fn (array $m): array => [...$m, 'path' => str_replace('Verkauf', 'Vertrieb', $m['path']), 'parent' => $m['parent'] === null ? null : str_replace('Verkauf', 'Vertrieb', $m['parent'])], $d['menus']);
            $d['menus'][0]['label'] = ['de' => 'Vertrieb'];
            $d['menus'][] = ['key' => $newMenu, 'path' => 'Vertrieb › Neu', 'parent' => 'Vertrieb', 'label' => ['de' => 'Neu'], 'icon' => null, 'permission_name' => null, 'sort_order' => 5, 'is_active' => true, 'target' => ['type' => 'report', 'report' => 'Neu']];
            $d['report_links'] = []; // Verknüpfung entfernt – „Mengen je Bestellung“ nutzt sie weiter
            $d['app']['manual'] = ['de' => "# Vertrieb\n"];

            return $d;
        });
        $plan = $this->plan($package);
        $ids = array_map(static fn (ChangeStep $s): string => $s->id, $plan->selectable());

        $this->assertContains('report:'.$report->uuid, $ids);
        $this->assertContains('report:'.$newReport, $ids);
        $this->assertContains('report:'.$old->uuid.':delete', $ids);
        $this->assertContains('menu:'.$group->uuid, $ids);
        $this->assertContains('manual:de', $ids);
        $this->assertStringContainsString('genutzt, die bleiben: Mengen', (string) $plan->step('report_link:'.$link->uuid.':delete')->blocked, 'Genutzte Verknüpfung bleibt.');

        // Das neue Menü hängt an der umbenannten Gruppe („Vertrieb“ gibt es erst nach dem Umbenennen): über ihren Schlüssel.
        app(ChangeExecutor::class)->apply($this->sales, $plan, $plan->preselected(), false, $this->admin);

        $this->assertSame('Mengen je Bestellung', $report->fresh()->name);
        $this->assertNull(Report::query()->find($old->id));
        $this->assertSame([$this->admin->id, $orders->id], [Report::query()->where('uuid', $newReport)->sole()->owner_id, (int) Report::query()->where('uuid', $newReport)->sole()->definition['base']]);
        $this->assertSame('Vertrieb', $group->fresh()->getTranslation('label', 'de'));
        $entry = Menu::query()->where('uuid', $newMenu)->sole();
        $this->assertSame([$group->id, (string) Report::query()->where('uuid', $newReport)->value('id')], [$entry->parent_id, $entry->route_parameters['record']]);
        $this->assertNotNull(ReportLink::query()->find($link->id));
        $this->assertSame("# Vertrieb\n", AppManual::query()->where('app_id', $this->sales->id)->where('locale', 'de')->value('content'));
    }

    public function test_roles_tasks_and_connected_tables_are_updated(): void
    {
        $sales = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->sales->id]);
        $sales->givePermissionTo('data.vk_kunden.view', 'data.vk_bestellungen.view');
        $old = Role::query()->create(['name' => 'alt', 'guard_name' => 'web', 'app_id' => $this->sales->id]);
        $person = User::factory()->create()->assignRole($sales, $old);
        $task = ScheduledTask::query()->create(['name' => 'Aufräumen', 'type' => 'task_runs_prune', 'app_id' => $this->sales->id, 'frequency' => 'daily', 'at_time' => '03:00', 'parameters' => []]);
        $erp = CustomTable::query()->create(['app_id' => $this->sales->id, 'name' => 'vk_erp', 'label' => ['de' => 'ERP'], 'source' => CustomTable::SOURCE_EXTERNAL, 'external_table' => 'erp', 'is_active' => false]);
        $erp->fields()->create(['name' => 'nr', 'label' => ['de' => 'Nr.'], 'type' => 'string', 'options' => []]);
        AppBaseline::query()->where('app_id', $this->sales->id)->update(['definition' => json_encode($this->definition())]);

        $newRole = (string) Str::uuid();
        $package = $this->package(function (array $d) use ($newRole): array {
            $roles = array_column($d['roles'], null, 'name');
            $roles['vertrieb']['permissions'] = ['data.vk_bestellungen.update', 'data.vk_bestellungen.view'];
            unset($roles['alt']);
            $roles['lager'] = ['key' => $newRole, 'name' => 'lager', 'permissions' => ['data.vk_kunden.view'], 'hidden_menus' => []];
            $d['roles'] = array_values($roles);
            $d['tasks'][0]['at_time'] = '04:00';
            $d['tasks'][] = [...$d['tasks'][0], 'key' => (string) Str::uuid(), 'name' => 'Unbekannt', 'type' => 'gibt_es_nicht'];
            $d['external_tables'][0]['label'] = ['de' => 'ERP-Artikel'];
            $d['external_tables'][0]['allow'] = ['create' => false, 'update' => true, 'delete' => false];
            $d['external_tables'][0]['source']['table'] = 'erp_neu';

            return $d;
        });
        $plan = $this->plan($package);

        $revoke = $plan->step('role:'.$sales->uuid);
        $this->assertSame(ChangeStep::RISKY, $revoke->level);
        $this->assertStringContainsString('betrifft 1 Person', implode(' ', $revoke->notes));
        $this->assertStringContainsString('+ data.vk_bestellungen.update, − data.vk_kunden.view', ChangeText::summary($revoke));
        $delete = $plan->step('role:'.$old->uuid.':delete');
        $this->assertSame([ChangeStep::DESTRUCTIVE, false], [$delete->level, $delete->preselected()], 'Rolle mit Mitgliedern: nur auf ausdrückliche Wahl.');
        $this->assertNotNull(collect($plan->steps)->first(static fn (ChangeStep $s): bool => $s->kind === 'task' && $s->action === ChangeStep::CREATE)?->blocked, 'Unbekannte Aufgabenart.');
        $external = $plan->step('external_table:'.$erp->uuid);
        $this->assertSame(ChangeStep::RISKY, $external->level);
        $this->assertStringContainsString('ändert das Aktualisieren nicht', implode(' ', $external->notes));

        app(ChangeExecutor::class)->apply($this->sales, $plan, [...$plan->preselected(), 'role:'.$old->uuid.':delete'], true, $this->admin);

        $this->assertEqualsCanonicalizing(['data.vk_bestellungen.update', 'data.vk_bestellungen.view'], $sales->fresh()->permissions->pluck('name')->all());
        $this->assertNull(Role::query()->find($old->id));
        $this->assertSame(['vertrieb'], $person->fresh()->getRoleNames()->all());
        $this->assertSame([$this->sales->id, ['data.vk_kunden.view']], [Role::query()->where('uuid', $newRole)->sole()->app_id, Role::query()->where('uuid', $newRole)->sole()->permissions->pluck('name')->all()]);
        $this->assertSame('04:00', $task->fresh()->at_time);
        $this->assertFalse(ScheduledTask::query()->where('name', 'Unbekannt')->exists());
        $erp->refresh();
        $this->assertSame(['ERP-Artikel', true, 'erp'], [$erp->getTranslation('label', 'de'), (bool) $erp->allow_update, $erp->external_table], 'Quelle bleibt.');
    }
}
