<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\DuskTestCase;

/** Popup „Details“ und die Aktionen darin (Bereich POP). */
class DetailsPopupTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string TITLE_INPUT = 'input[id$=".titel"]';

    private CustomTable $projekte;

    private DynamicRecord $projekt;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = app(DynamicSchemaService::class);
        $kunden = $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $kunde = DynamicRecord::forTable($kunden)->create(['firma' => 'ACME GmbH']);

        $this->projekte = $schema->createTable(['name' => 'projekte', 'label' => 'Projekte'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'budget', 'label' => 'Budget', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
            ['name' => 'aktiv', 'label' => 'Aktiv', 'type' => 'boolean'],
            ['name' => 'start', 'label' => 'Start', 'type' => 'date'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'wip', 'label' => 'In Arbeit'], ['value' => 'done', 'label' => 'Erledigt']]]],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'text', 'show_in_table' => false],
        ]);
        app(DataMenu::class)->ensure($this->projekte);

        $this->projekt = DynamicRecord::forTable($this->projekte)->create([
            'titel' => 'Alpha',
            'budget' => '1500.50',
            'aktiv' => true,
            'start' => '2026-02-01',
            'status' => 'wip',
            'kunde_id' => $kunde->id,
            'notiz' => 'Nur im Popup sichtbar',
        ]);
    }

    private function openPopup(Browser $browser): Browser
    {
        $this->login($browser, self::ADMIN)->visit('/admin/data/projekte');

        return $this->openDetails($browser, 'Alpha');
    }

    private function stored(): DynamicRecord
    {
        return DynamicRecord::forTable($this->projekte)->newQuery()->withTrashed()->findOrFail($this->projekt->id);
    }

    /** POP-01 */
    public function test_popup_shows_all_fields_in_readable_form(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/projekte')->waitForText('Alpha', 15)
                ->assertDontSee('Nur im Popup sichtbar');

            $this->openDetails($browser, 'Alpha')
                ->waitForText('Nur im Popup sichtbar', 15)
                ->assertSee('In Arbeit')
                ->assertSee('1.500,50')
                ->assertSee('01.02.2026')
                ->assertSee('ACME GmbH')
                ->assertSee('Ja');
        });
    }

    /** POP-02 */
    public function test_edit_from_popup_updates_list_and_summary_without_reload(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/projekte');
            $this->clickRow($browser, 'Alpha')->waitFor('tr.saft-row-selected', 15);

            $this->openDetails($browser, 'Alpha');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor(self::TITLE_INPUT, 15);
            $browser->clear(self::TITLE_INPUT)->type(self::TITLE_INPUT, 'Omega');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);

            $this->assertSame('Omega', $this->stored()->getAttribute('titel'));

            $this->clickVisibleButton($browser, 'Schliessen');
            $browser->waitForTextIn('tr.saft-row-selected', 'Omega', 15)->assertDontSee('Alpha');
        });
    }

    /** POP-03 */
    public function test_cancelling_the_edit_changes_nothing(): void
    {
        $this->superAdmin();
        $activities = Activity::query()->where('subject_id', $this->projekt->id)->where('subject_type', $this->projekt->getMorphClass())->count();

        $this->browse(function (Browser $browser): void {
            $this->openPopup($browser);
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor(self::TITLE_INPUT, 15);
            $browser->clear(self::TITLE_INPUT)->type(self::TITLE_INPUT, 'Verworfen');
            $this->clickVisibleButton($browser, 'Abbrechen')->waitUntilMissing(self::TITLE_INPUT, 15);

            $browser->assertDontSee('Verworfen')->assertSee('Alpha');
        });

        $this->assertSame('Alpha', $this->stored()->getAttribute('titel'));
        $this->assertSame($activities, Activity::query()->where('subject_id', $this->projekt->id)->where('subject_type', $this->projekt->getMorphClass())->count());
    }

    /** POP-04 */
    public function test_history_shows_user_and_old_and_new_value(): void
    {
        $admin = $this->superAdmin();

        $this->browse(function (Browser $browser) use ($admin): void {
            $this->openPopup($browser);
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor(self::TITLE_INPUT, 15);
            $browser->clear(self::TITLE_INPUT)->type(self::TITLE_INPUT, 'Omega');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);

            $this->clickVisibleButton($browser, 'Verlauf')
                ->waitForText('Alpha → Omega', 15)
                ->assertSee($admin->name);

            $this->clickVisibleButton($browser, 'Schliessen');
            $browser->waitUntilMissingText('Alpha → Omega', 15)->waitForText('Bearbeiten', 15);
            $this->assertSame('Omega', $this->stored()->getAttribute('titel'));
        });
    }

    /** POP-05 */
    public function test_delete_asks_first_then_removes_the_row_and_closes_all_windows(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->openPopup($browser);

            $this->clickVisibleButton($browser, 'Löschen')->waitForText('Abbrechen', 15);
            $this->clickVisibleButton($browser, 'Abbrechen')->pause(500);
            $this->assertFalse($this->stored()->trashed());

            $this->clickVisibleButton($browser, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen');

            $browser->waitUntilMissingText('Alpha', 15)->waitUntilMissingText('Bearbeiten', 15);
            $this->assertTrue($this->stored()->trashed());
        });
    }

    private function updateTitle(Browser $browser, string $title): Browser
    {
        $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor(self::TITLE_INPUT, 15);
        $browser->clear(self::TITLE_INPUT)->type(self::TITLE_INPUT, $title);

        return $browser;
    }

    private function showDeleted(Browser $browser): Browser
    {
        return $this->applyFilters($browser, static function (Browser $b): void {
            $b->waitFor('select[id="tableFiltersForm.trashed.value"]', 15)->select('select[id="tableFiltersForm.trashed.value"]', '0');
        });
    }

    /** POP-06 */
    public function test_deleted_record_can_be_restored_from_the_popup(): void
    {
        $this->superAdmin();
        $this->projekt->delete();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/projekte')->waitForText('Noch keine Datensätze', 15);
            $this->showDeleted($browser)->waitForText('Alpha', 15);

            $this->openDetails($browser, 'Alpha')->assertSee('Dieser Datensatz ist gelöscht');
            $this->clickVisibleButton($browser, 'Wiederherstellen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Wiederherstellen')->waitForText('wiederhergestellt', 15);

            $this->assertFalse($this->stored()->trashed());

            // Der Filter „nur gelöschte“ bleibt in der Sitzung stehen: zurücksetzen.
            $browser->visit('/admin/data/projekte')->waitForText('Nur gelöschte Einträge', 15)->assertDontSee('Alpha');
            $this->resetFilters($browser)->waitForText('Alpha', 15);
            $this->openDetails($browser, 'Alpha')->assertDontSee('Dieser Datensatz ist gelöscht');
            $this->clickVisibleButton($browser, 'Verlauf')->waitForText('wiederhergestellt', 15)->assertSee('wiederhergestellt');
        });
    }

    /** POP-07 */
    public function test_delete_guard_message_names_the_details_and_stays_visible(): void
    {
        $this->superAdmin();
        $kunde = DynamicRecord::forTableName('kunden')->newQuery()->firstOrFail();
        app(DataMenu::class)->ensure(CustomTable::query()->where('name', 'kunden')->firstOrFail());

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden');
            $this->openDetails($browser, 'ACME GmbH');
            $this->clickVisibleButton($browser, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')
                ->waitForText(__('errors.titles.business_rule'), 15)
                ->assertSeeIn('.fi-no-notification', '1')
                ->assertSeeIn('.fi-no-notification', 'Projekte')
                ->pause(7000)
                ->assertSee(__('errors.titles.business_rule'));
        });

        $this->assertNull($kunde->fresh()->deleted_at);
    }

    /** POP-08: läuft ohne Test-Transaktion und prüft damit den echten Datenbankkonflikt. */
    public function test_restore_with_a_taken_unique_value_reports_a_conflict(): void
    {
        $this->superAdmin();
        $table = app(DynamicSchemaService::class)->createTable(['name' => 'nummern', 'label' => 'Nummern'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true, 'is_unique' => true],
        ]);
        $old = DynamicRecord::forTable($table)->create(['nummer' => 'K-1']);
        $old->delete();
        DynamicRecord::forTable($table)->create(['nummer' => 'K-1']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/nummern')->waitForText('K-1', 15);
            $this->showDeleted($browser)->pause(600);

            $this->openDetails($browser, 'K-1');
            $this->clickVisibleButton($browser, 'Wiederherstellen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Wiederherstellen')
                ->waitForText(__('errors.titles.conflict'), 15)
                ->assertDontSee('Unerwarteter Fehler');
        });

        $this->assertTrue(DynamicRecord::forTable($table)->newQuery()->withTrashed()->findOrFail($old->id)->trashed());
    }

    /** POP-09 */
    public function test_read_only_user_gets_only_history_and_close(): void
    {
        $this->activeUser('leser@example.com')->givePermissionTo(DataPermissions::name('projekte', 'view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'leser@example.com')->visit('/admin/data/projekte');
            $this->openDetails($browser, 'Alpha')
                ->assertSee('Verlauf')
                ->assertDontSee('Bearbeiten')
                ->assertDontSee('Löschen')
                ->assertDontSee('Wiederherstellen');
        });
    }

    /** POP-10 */
    public function test_parallel_edits_do_not_fail_and_both_are_logged(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $first, Browser $second): void {
            foreach ([$first, $second] as $browser) {
                $this->openPopup($browser);
            }

            $this->updateTitle($first, 'Erste Fassung');
            $this->updateTitle($second, 'Zweite Fassung');

            $this->clickVisibleButton($first, 'Speichern')->waitForText('Gespeichert', 15);
            $this->clickVisibleButton($second, 'Speichern')->waitForText('Gespeichert', 15)->assertDontSee('Unerwarteter Fehler');
        });

        $this->assertSame('Zweite Fassung', $this->stored()->getAttribute('titel'));
        $this->assertSame(2, Activity::query()
            ->where('subject_type', $this->projekt->getMorphClass())->where('subject_id', $this->projekt->id)
            ->where('event', 'updated')->count());
    }

    /** POP-11 */
    public function test_saving_a_record_deleted_elsewhere_gives_no_error_page(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $first, Browser $second): void {
            $this->openPopup($first);
            $this->updateTitle($first, 'Zu spät');

            $this->openPopup($second);
            $this->clickVisibleButton($second, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($second, 'Löschen')->waitUntilMissingText('Alpha', 15);

            $this->clickVisibleButton($first, 'Speichern')
                ->waitForText(__('errors.rules.record_deleted'), 15)
                ->assertDontSee('Unerwarteter Fehler')
                ->assertPresent(self::SELECTOR_SIDEBAR);
        });

        $this->assertTrue($this->stored()->trashed());
        $this->assertSame('Alpha', $this->stored()->getAttribute('titel'));
    }

    /** POP-12 */
    public function test_field_hidden_in_the_form_keeps_its_value(): void
    {
        $this->superAdmin();
        app(DynamicSchemaService::class)->updateField(
            CustomField::query()->where('custom_table_id', $this->projekte->id)->where('name', 'notiz')->firstOrFail(),
            ['show_in_form' => false],
        );

        $this->browse(function (Browser $browser): void {
            $this->openPopup($browser);
            $this->updateTitle($browser, 'Neu')->assertMissing('textarea[id$=".notiz"]');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);
        });

        DynamicRecord::forget('projekte');
        $this->assertSame('Neu', $this->stored()->getAttribute('titel'));
        $this->assertSame('Nur im Popup sichtbar', $this->stored()->getAttribute('notiz'));
    }
}
