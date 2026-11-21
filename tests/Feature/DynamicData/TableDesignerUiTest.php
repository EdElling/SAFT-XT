<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Support\TableLabelHint;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

class TableDesignerUiTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->superAdmin());
    }

    public function test_designer_requires_permissions(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view']));
        $this->get('/admin/table-designer')->assertForbidden();

        $this->actingAs($this->userWithPermissions(['custom_tables.view']));
        $this->get('/admin/table-designer')->assertOk();
        $this->get('/admin/table-designer/create')->assertForbidden();
    }

    public function test_pages_render(): void
    {
        $table = $this->projects();
        $this->service()->createTable(['name' => 'kunden'], [['name' => 'name', 'type' => 'string']]);
        $this->service()->addField($table, ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']]);

        $this->get('/admin/table-designer')->assertOk()->assertSee('Projekte');
        $this->get('/admin/table-designer/create')->assertOk();
        $this->get("/admin/table-designer/{$table->id}/edit")
            ->assertOk()
            ->assertSee($this->qualified('projekte'));

        // Der Feld-Editor wird lazy geladen – daher direkt als Livewire-Komponente prüfen.
        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->assertSee('kunden.name')
            ->assertSee('Verknüpfung');
    }

    public function test_table_name_is_checked_on_leaving_the_field_and_names_the_owner(): void
    {
        $this->projects();
        $this->appSchema()->create($this->qualified('altlast'), fn ($table) => $table->id());

        Livewire::test(CreateCustomTable::class)
            ->fillForm(['name' => 'projekte'])
            ->assertHasFormErrors(['name'])
            ->assertSee('Der Name „projekte“ ist bereits vergeben: SAFT-Tabelle „Projekte“.')
            ->fillForm(['name' => 'altlast'])
            ->assertSee('Im Namensraum existiert bereits eine Tabelle „altlast“ ohne Metadaten')
            ->fillForm(['name' => 'neu_frei'])
            ->assertHasNoFormErrors(['name']);
    }

    public function test_same_label_is_a_hint_not_an_error(): void
    {
        $projects = $this->projects();

        Livewire::test(CreateCustomTable::class)
            ->fillForm(['label' => ['de' => 'projekte '], 'name' => 'projekte_alt', 'fields' => [['label' => ['de' => 'Titel'], 'name' => 'titel', 'type' => 'string', 'show_in_table' => true, 'show_in_form' => true]]])
            ->assertSee('Hinweis: Die Bezeichnung „projekte“ verwendet bereits SAFT-Tabelle projekte.')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(CustomTable::query()->where('name', 'projekte_alt')->exists());

        // Die eigene Bezeichnung zählt nicht.
        Livewire::test(EditCustomTable::class, ['record' => $projects->getRouteKey()])
            ->assertDontSee('verwendet bereits SAFT-Tabelle projekte.');

        // Nur innerhalb der App (B-107): Tabellen anderer Apps stehen nicht in denselben Menüs.
        $other = App::query()->create(['uuid' => (string) Str::uuid(), 'name' => ['de' => 'Andere App'], 'icon' => 'heroicon-o-cube', 'sort_order' => 900]);
        $this->assertNotNull(TableLabelHint::message('Projekte', 'de', null, $projects->app_id));
        $this->assertNull(TableLabelHint::message('Projekte', 'de', null, $other->id));
    }

    public function test_create_table_with_initial_fields_via_form(): void
    {
        Livewire::test(CreateCustomTable::class)
            ->fillForm([
                'label' => ['de' => 'Projekte'],
                'name' => 'projekte',
                'fields' => [
                    ['label' => ['de' => 'Titel'], 'name' => 'titel', 'type' => 'string', 'options' => ['length' => 120], 'is_required' => true, 'show_in_table' => true, 'show_in_form' => true],
                    ['label' => ['de' => 'Status'], 'name' => 'status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen', 'label' => 'Offen']]], 'show_in_table' => true, 'show_in_form' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $table = CustomTable::where('name', 'projekte')->firstOrFail();
        $this->assertSame(['titel', 'status'], $table->fields->pluck('name')->all());
        $this->assertTrue($this->appSchema()->hasColumn($this->qualified('projekte'), 'titel'));
        $this->assertSame(120, $table->fields->first()->optionsArray()['length']);

        // Permissions für die Datensätze entstehen mit der Tabelle.
        $this->assertSame(6, Permission::where('name', 'like', 'data.projekte.%')->count(), 'Anzeigen, Anlegen, Bearbeiten, Löschen, Exportieren, Importieren.');
        $this->assertSame('Daten: Projekte: anzeigen', RoleForm::label('data.projekte.view'));
    }

    public function test_invalid_names_are_form_errors(): void
    {
        Livewire::test(CreateCustomTable::class)
            ->fillForm(['label' => ['de' => 'X'], 'name' => 'public.users', 'fields' => [['label' => ['de' => 'Id'], 'name' => 'id', 'type' => 'string']]])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(0, CustomTable::count());
    }

    public function test_label_suggests_technical_name(): void
    {
        $this->assertSame('groesse_in_m2', FieldForm::suggestName('Größe in m²'));
        $this->assertSame('f_2024_umsatz', FieldForm::suggestName('2024 Umsatz'));
    }

    public function test_add_edit_and_rename_field_in_editor(): void
    {
        $table = $this->projects();

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'label' => ['de' => 'Verantwortlich'], 'name' => 'verantwortlich', 'type' => 'string', 'options' => ['length' => 80],
            ])
            ->assertHasNoFormErrors();

        $this->assertTrue($this->appSchema()->hasColumn($this->qualified('projekte'), 'verantwortlich'));

        DynamicRecord::forTableName('projekte')->create(['verantwortlich' => 'Max']);
        $field = CustomField::where('name', 'verantwortlich')->firstOrFail();

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(EditAction::class)->table($field), ['name' => 'leitung', 'label' => ['de' => 'Leitung']])
            ->assertHasNoFormErrors();

        $this->assertSame('Max', DynamicRecord::forTableName('projekte')->newQuery()->value('leitung'));
    }

    public function test_unsafe_change_shows_notification_and_keeps_data(): void
    {
        $table = $this->projects();
        DynamicRecord::forTable($table)->create(['titel' => 'Text']);
        $field = $table->fields()->where('name', 'titel')->first();

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(EditAction::class)->table($field), ['type' => 'integer']);

        Notification::assertNotified(__('errors.titles.business_rule'));
        $this->assertSame('string', $field->fresh()->type->value);
        $this->assertSame('Text', DynamicRecord::forTableName('projekte')->newQuery()->value('titel'));
    }

    public function test_deactivate_and_drop_field_with_confirmation(): void
    {
        $table = $this->projects();
        $field = $table->fields()->where('name', 'anzahl')->first();
        $manager = fn () => Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class]);

        // Sichtbar, aber gesperrt mit Hinweis, solange das Feld aktiv ist.
        $manager()->assertActionVisible(TestAction::make('dropField')->table($field))
            ->assertActionDisabled(TestAction::make('dropField')->table($field))
            ->assertSee(__('tables.fields.drop_deactivate_first'));

        $manager()->callAction(TestAction::make('toggleActive')->table($field));
        $this->assertFalse($field->fresh()->is_active);

        $manager()->callAction(TestAction::make('dropField')->table($field->fresh()), ['confirmation' => 'falsch']);
        $this->assertTrue($this->appSchema()->hasColumn($this->qualified('projekte'), 'anzahl'), 'Falsche Bestätigung darf nichts löschen.');

        $manager()->callAction(TestAction::make('dropField')->table($field->fresh()), ['confirmation' => 'anzahl']);
        $this->assertFalse($this->appSchema()->hasColumn($this->qualified('projekte'), 'anzahl'));
    }

    public function test_drop_table_only_when_inactive_and_confirmed(): void
    {
        $table = $this->projects();

        Livewire::test(EditCustomTable::class, ['record' => $table->getRouteKey()])
            ->assertActionVisible('dropTable')
            ->assertActionDisabled('dropTable')
            ->assertSee(__('tables.actions.drop_deactivate_first'));

        Livewire::test(EditCustomTable::class, ['record' => $table->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditCustomTable::class, ['record' => $table->getRouteKey()])
            ->callAction('dropTable', ['confirmation' => 'projekte'])
            ->assertRedirect('/admin/table-designer');

        $this->assertFalse($this->appSchema()->hasTable($this->qualified('projekte')));
        $this->assertSame(0, Permission::where('name', 'like', 'data.projekte.%')->count());
    }

    public function test_technical_name_cannot_be_changed_via_form(): void
    {
        $table = $this->projects();

        Livewire::test(EditCustomTable::class, ['record' => $table->getRouteKey()])
            ->fillForm(['name' => 'anders', 'label' => ['de' => 'Neu']])
            ->call('save');

        $this->assertSame('projekte', $table->fresh()->name);
        $this->assertSame('Neu', $table->fresh()->label);
    }

    public function test_viewer_cannot_modify_fields(): void
    {
        $table = $this->projects();
        $this->actingAs($this->userWithPermissions(['custom_tables.view']));

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->assertActionHidden(TestAction::make(CreateAction::class)->table())
            ->assertActionHidden(TestAction::make(EditAction::class)->table($table->fields->first()));
    }
}
