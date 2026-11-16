<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Auth\AuthMode;
use App\DynamicData\DataMenu;
use App\Exceptions\Database\DatabaseErrorClassifier;
use App\Exceptions\Database\DatabaseErrorType;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Support\ActivityChanges;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomField;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Once;
use Livewire\Livewire;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresAuth;

/** Befunde aus der Vorbereitung des manuellen GUI-Tests (Nummern siehe Testliste). */
class ManualTestFindingsTest extends DynamicDataTestCase
{
    use ConfiguresAuth;

    /** KAS-11 */
    public function test_completely_invalid_selection_is_removed_from_the_url(): void
    {
        $this->actingAs($this->superAdmin());
        $this->projects();

        Livewire::withQueryParams(['sel' => 'abc'])
            ->test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSet('selection', null);
    }

    /** KAS-12 */
    public function test_two_relations_to_one_table_get_distinct_tabs(): void
    {
        $this->actingAs($this->superAdmin());
        $kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string'],
        ]);
        $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'rechnung_an_id', 'label' => 'Rechnung an', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        $first = $this->fieldId('bestellungen', 'kunde_id');
        $second = $this->fieldId('bestellungen', 'rechnung_an_id');
        $this->service()->updateTable($kunden, ['detail_field_ids' => [$first, $second]]);
        $kunde = DynamicRecord::forTable($kunden)->create(['firma' => 'ACME']);

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])
            ->set('selection', (string) $kunde->id)
            ->assertSee('Bestellungen (über „Kunde“)')
            ->assertSee('Bestellungen (über „Rechnung an“)');

        preg_match_all('/data-tab-key="([^"]+)"/', $page->html(), $keys);
        $this->assertCount(2, $keys[1]);
        $this->assertCount(2, array_unique($keys[1]));
    }

    /** EIN-01, EIN-02 */
    public function test_lists_show_dates_in_german_format(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->service()->createTable(['name' => 'termine', 'label' => 'Termine'], [
            ['name' => 'tag', 'label' => 'Tag', 'type' => 'date'],
            ['name' => 'zeitpunkt', 'label' => 'Zeitpunkt', 'type' => 'datetime'],
        ]);
        DynamicRecord::forTable($table)->create(['tag' => '2026-02-01', 'zeitpunkt' => '2026-03-04 14:30:00']);

        Livewire::test(DynamicDataPage::class, ['table' => 'termine'])
            ->assertSee('01.02.2026')
            ->assertSee('04.03.2026 14:30')
            ->assertDontSee('Feb 1, 2026');
    }

    /** TDE-07 */
    public function test_activating_a_field_needs_no_confirmation_about_hiding(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        $field = CustomField::findOrFail($this->fieldId('projekte', 'status'));
        $this->service()->updateField($field, ['is_active' => false]);

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make('toggleActive')->table($field->fresh()))
            ->assertHasNoErrors();

        $this->assertTrue($field->fresh()->is_active);
    }

    /** BEN-06 */
    public function test_log_names_deleted_causers_instead_of_system(): void
    {
        $user = User::factory()->create(['name' => 'Anna Beispiel']);
        $entry = activity()->causedBy($user)->log('Änderung');
        $system = activity()->log('Automatisch');

        $this->assertSame('Anna Beispiel', ActivityChanges::causerLabel($entry->fresh()));

        $user->delete();
        $this->assertSame('Anna Beispiel (gelöscht)', ActivityChanges::causerLabel($entry->fresh()));

        $id = $user->id;
        $user->forceDelete();
        $this->assertSame("Gelöschter Benutzer #{$id}", ActivityChanges::causerLabel($entry->fresh()));

        $this->assertSame('System', ActivityChanges::causerLabel($system->fresh()));
        $this->assertSame('System / Anmeldung', ActivityChanges::causerLabel($system->fresh(), 'System / Anmeldung'));
    }

    /** PRT-03 */
    #[DataProvider('impossibleRecordUrls')]
    public function test_impossible_record_ids_give_not_found(string $url): void
    {
        $this->actingAs($this->superAdmin())->get($url)->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function impossibleRecordUrls(): array
    {
        return [
            'Benutzer' => ['/admin/users/abc/edit'],
            'Rollen' => ['/admin/roles/abc/edit'],
            'Menüs' => ['/admin/menus/abc/edit'],
            'Table-Designer' => ['/admin/table-designer/abc/edit'],
            'Protokoll' => ['/admin/activity-log/create'],
            'Zu große Zahl' => ['/admin/users/99999999999999999999/edit'],
        ];
    }

    /** NAV-03 */
    public function test_parent_menu_entry_without_icon_does_not_break_the_panel(): void
    {
        $this->actingAs($this->superAdmin());
        $group = Menu::create(['label' => 'Ebene 1', 'sort_order' => 950, 'is_active' => true]);

        foreach ([null, 'heroicon-o-gibtsnicht'] as $number => $icon) {
            $second = Menu::create(['parent_id' => $group->id, 'label' => "Ebene 2.{$number}", 'icon' => $icon, 'route' => 'filament.admin.resources.users.index', 'sort_order' => $number, 'is_active' => true]);
            Menu::create(['parent_id' => $second->id, 'label' => "Ebene 3.{$number}", 'route' => 'filament.admin.resources.roles.index', 'sort_order' => 1, 'is_active' => true]);
        }

        $this->get('/admin')->assertOk()->assertSee('Ebene 2.0')->assertSee('Ebene 2.1');
    }

    /** ANM-06 */
    public function test_password_reset_request_gives_the_same_answer_for_every_address(): void
    {
        $this->setAuthMode(AuthMode::Local, passwordReset: true);
        NotificationFacade::fake();
        $user = User::factory()->create(['email' => 'max@example.com', 'is_active' => true]);
        $user->assignRole('super-admin');

        foreach (['gibt-es-nicht@example.com', 'max@example.com', 'max@example.com'] as $email) {
            // Die Seite begrenzt Anfragen pro Minute; geprüft wird hier die Sperre je Konto.
            Cache::flush();
            Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $email])->call('request');

            Notification::assertNotified(__('passwords.sent'));
        }
    }

    /** POP-11 */
    public function test_deleted_record_offers_no_edit_action(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        $record = DynamicRecord::forTable($table)->create(['titel' => 'Alpha']);
        $record->delete();

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->filterTable('trashed', false)
            ->assertActionHidden([TestAction::make('details')->table($record), TestAction::make('edit')]);
    }

    /** KAS-19 */
    public function test_deleting_the_selected_root_record_clears_the_selection(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        $first = DynamicRecord::forTable($table)->create(['titel' => 'Alpha']);
        $other = DynamicRecord::forTable($table)->create(['titel' => 'Beta']);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->set('selection', (string) $first->id)
            ->call('refreshCascade', 'projekte', $other->id)
            ->assertSet('selection', (string) $first->id)
            ->call('refreshCascade', 'projekte', $first->id)
            ->assertSet('selection', null);
    }

    /** VOR-02 */
    public function test_links_use_a_view_only_for_entries_with_own_columns(): void
    {
        $table = $this->projects();
        $entry = app(DataMenu::class)->ensure($table);

        $this->assertNull(DataMenu::firstMenuIdFor('projekte'));

        $entry->update(['view_config' => ['columns' => [$this->fieldId('projekte', 'titel')]]]);
        Once::flush();
        $this->assertSame($entry->id, DataMenu::firstMenuIdFor('projekte'));
    }

    /** TDE-08 */
    public function test_renaming_a_display_field_keeps_dependent_relations_working(): void
    {
        $this->actingAs($this->superAdmin());
        $kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        $kunde = DynamicRecord::forTable($kunden)->create(['firma' => 'ACME']);
        DynamicRecord::forTableName('bestellungen')->create(['kunde_id' => $kunde->id]);

        $this->service()->updateField(CustomField::findOrFail($this->fieldId('kunden', 'firma')), ['name' => 'firmenname']);

        $relation = CustomField::findOrFail($this->fieldId('bestellungen', 'kunde_id'));
        $this->assertSame('firmenname', $relation->optionsArray()['display_field']);

        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->assertOk()
            ->assertSee('ACME');
    }

    /** NAV-09: bereits gespeicherte ungültige Icons dürfen das Panel nicht ausfallen lassen. */
    public function test_invalid_stored_icon_does_not_break_the_panel(): void
    {
        $this->actingAs($this->superAdmin());
        Log::spy();
        Menu::create(['label' => 'Kaputt', 'route' => 'filament.admin.resources.users.index', 'icon' => 'heroicon-o-gibtsnicht', 'is_active' => true, 'sort_order' => 99]);

        $this->get('/admin')->assertOk()->assertSee('Kaputt');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['icon'] ?? null) === 'heroicon-o-gibtsnicht');
    }

    /** NAV-09 */
    public function test_menu_form_rejects_unknown_icon_and_accepts_known_icon(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateMenu::class)
            ->fillForm(['label' => 'Neu', 'icon' => 'heroicon-o-gibtsnicht'])
            ->call('create')
            ->assertHasFormErrors(['icon']);

        Livewire::test(CreateMenu::class)
            ->fillForm(['label' => 'Neu', 'icon' => 'heroicon-o-users'])
            ->call('create')
            ->assertHasNoFormErrors(['icon']);
    }

    /** TDE-16 */
    public function test_table_form_rejects_unknown_icon(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateCustomTable::class)
            ->fillForm(['label' => 'Neu', 'name' => 'neu', 'icon' => 'heroicon-o-gibtsnicht'])
            ->call('create')
            ->assertHasFormErrors(['icon']);
    }

    /** KAS-20 */
    public function test_new_child_cannot_be_created_under_deleted_parent(): void
    {
        $this->actingAs($this->superAdmin());
        $kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden', 'detail_field_ids' => []], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        $this->service()->updateTable($kunden, ['detail_field_ids' => [$this->fieldId('bestellungen', 'kunde_id')]]);
        $kunde = DynamicRecord::forTable($kunden)->create(['firma' => 'ACME']);
        $component = fn () => Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'kunden',
            'parentId' => $kunde->id,
            'relationFieldId' => $this->fieldId('bestellungen', 'kunde_id'),
            'level' => 1,
        ]);
        $newRecord = TestAction::make(CreateAction::class)->table();

        $component()->assertActionVisible($newRecord);

        $kunde->delete();

        $component()->assertActionHidden($newRecord);
        $this->assertSame(0, DynamicRecord::forTableName('bestellungen')->newQuery()->count());
    }

    /** ANM-07 */
    public function test_login_accepts_email_in_any_letter_case(): void
    {
        $user = User::factory()->create(['email' => 'max@example.com', 'password' => 'Geheim-12345', 'is_active' => true]);
        $user->assignRole('super-admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'MAX@Example.COM', 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    /** ANM-07 */
    public function test_password_reset_accepts_email_in_any_letter_case(): void
    {
        NotificationFacade::fake();
        $this->setAuthMode(AuthMode::Local, passwordReset: true);
        $user = User::factory()->create(['email' => 'max@example.com', 'is_active' => true]);
        $user->assignRole('super-admin');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'MAX@Example.COM'])
            ->call('request');

        NotificationFacade::assertSentTo($user, ResetPasswordNotification::class);
    }

    /** ANM-07: Dublette in anderer Schreibweise ergibt einen Feldfehler statt eines Datenkonflikts. */
    public function test_user_form_detects_duplicate_email_in_other_letter_case(): void
    {
        $this->actingAs($this->superAdmin());
        User::factory()->create(['email' => 'max@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Neu', 'email' => 'MAX@Example.com', 'password' => 'Geheim-12345', 'password_confirmation' => 'Geheim-12345'])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    /** EIN-12 */
    public function test_removing_a_used_select_option_warns_with_count(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        DynamicRecord::forTable($table)->create(['titel' => 'A', 'status' => 'erledigt']);
        DynamicRecord::forTable($table)->create(['titel' => 'B', 'status' => 'offen']);
        $field = CustomField::findOrFail($this->fieldId('projekte', 'status'));

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(EditAction::class)->table($field), [
                'options' => ['options' => [['value' => 'offen', 'label' => 'Offen']]],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(['offen'], array_column($field->fresh()->optionsArray()['options'], 'value'));
        Notification::assertNotified('Verwendeter Auswahlwert entfernt');
        // Daten bleiben unverändert.
        $this->assertSame(1, DynamicRecord::forTable($table)->newQuery()->where('status', 'erledigt')->count());
    }

    /** EIN-12: nicht benutzte Werte lassen sich ohne Warnung entfernen. */
    public function test_removing_an_unused_select_option_does_not_warn(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        DynamicRecord::forTable($table)->create(['titel' => 'B', 'status' => 'offen']);
        $field = CustomField::findOrFail($this->fieldId('projekte', 'status'));

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(EditAction::class)->table($field), [
                'options' => ['options' => [['value' => 'offen', 'label' => 'Offen']]],
            ])
            ->assertHasNoFormErrors();

        Notification::assertNotNotified('Verwendeter Auswahlwert entfernt');
    }

    /** EIN-05 */
    public function test_integer_field_rejects_values_outside_the_column_range(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        $create = fn () => TestAction::make(CreateAction::class)->table();

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction($create(), ['titel' => 'Zu groß', 'anzahl' => '999999999999999999999999999999'])
            ->assertHasFormErrors(['anzahl']);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction($create(), ['titel' => 'Grenzwert', 'anzahl' => '9223372036854775807'])
            ->assertHasNoFormErrors();

        $this->assertSame(1, DynamicRecord::forTable($table)->newQuery()->count());
    }

    /** EIN-05 */
    public function test_integer_filter_tolerates_out_of_range_and_fractional_values(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        DynamicRecord::forTable($table)->create(['titel' => 'Klein', 'anzahl' => 2]);
        DynamicRecord::forTable($table)->create(['titel' => 'Mittel', 'anzahl' => 5]);

        $page = fn () => Livewire::test(DynamicDataPage::class, ['table' => 'projekte']);

        $page()->filterTable('anzahl', ['from' => '999999999999999999999999999999'])
            ->assertOk()->assertDontSee('Klein')->assertDontSee('Mittel');
        $page()->filterTable('anzahl', ['until' => '999999999999999999999999999999'])
            ->assertOk()->assertSee('Klein')->assertSee('Mittel');
        $page()->filterTable('anzahl', ['from' => '2.5'])
            ->assertOk()->assertDontSee('Klein')->assertSee('Mittel');
        $page()->filterTable('anzahl', ['until' => '2.5'])
            ->assertOk()->assertSee('Klein')->assertDontSee('Mittel');
    }

    /** EIN-05: Sicherheitsnetz für Überlauf, den die Formularprüfung nicht abfängt. */
    public function test_numeric_overflow_is_classified_as_user_correctable(): void
    {
        $pdo = new PDOException('out of range');
        $pdo->errorInfo = ['22003', 7, 'value out of range'];
        $e = new QueryException('pgsql', 'insert', [], $pdo);

        $type = app(DatabaseErrorClassifier::class)->classify($e);

        $this->assertSame(DatabaseErrorType::ValueOutOfRange, $type);
        $this->assertSame(422, $type->status());
        $this->assertNotSame('errors.db.value_out_of_range', __('errors.db.value_out_of_range'));
    }
}
