<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\Pages\ListCustomTables;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Support\ActivityChanges;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use Livewire\Livewire;

class TableTranslationTest extends DynamicDataTestCase
{
    private function contracts(): CustomTable
    {
        return $this->service()->createTable(
            ['name' => 'vertraege', 'label' => ['de' => 'Verträge', 'en' => 'Contracts'], 'description' => ['de' => 'Alle Verträge']],
            [
                ['name' => 'titel', 'label' => ['de' => 'Titel', 'en' => 'Title'], 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
                ['name' => 'status', 'label' => ['de' => 'Status'], 'type' => 'select', 'options' => ['options' => [
                    ['value' => 'offen', 'label' => ['de' => 'Offen', 'en' => 'Open']],
                    ['value' => 'alt', 'label' => 'Erledigt'],
                ]]],
            ],
        );
    }

    public function test_labels_follow_the_language_with_fallback(): void
    {
        $table = $this->contracts();
        $field = $table->fields->firstWhere('name', 'status');

        app()->setLocale('en');
        $this->assertSame('Contracts', $table->label);
        $this->assertSame('Alle Verträge', $table->description, 'Ohne Englisch gilt Deutsch');
        $this->assertSame('Title', $table->fields->firstWhere('name', 'titel')->label);
        $this->assertSame('Status', $field->label);

        app()->setLocale('de');
        $this->assertSame('Verträge', $table->fresh()->label);
    }

    public function test_page_columns_and_select_values_are_shown_in_english(): void
    {
        $table = $this->contracts();
        DynamicRecord::forTable($table)->create(['titel' => 'Rahmenvertrag', 'status' => 'offen']);
        DynamicRecord::forTable($table)->create(['titel' => 'Altvertrag', 'status' => 'alt']);
        $this->actingAs($this->superAdmin());

        app()->setLocale('en');

        Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->assertSee('Contracts')
            ->assertSee('Title')
            ->assertSee('Open')
            ->assertSee('Erledigt', escape: false)
            // Ohne englische Beschreibung erscheint die deutsche.
            ->assertSee('Alle Verträge', escape: false);
    }

    public function test_form_saves_every_language_and_clears_removed_ones(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateCustomTable::class)
            ->fillForm([
                'label' => ['de' => 'Lieferanten', 'en' => 'Suppliers'],
                'name' => 'lieferanten',
                'description' => ['de' => 'Unsere Lieferanten', 'en' => ''],
                'fields' => [['label' => ['de' => 'Firma', 'en' => 'Company'], 'name' => 'firma', 'type' => 'string']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $table = CustomTable::query()->where('name', 'lieferanten')->firstOrFail();
        $this->assertSame(['de' => 'Lieferanten', 'en' => 'Suppliers'], $table->getTranslations('label'));
        $this->assertSame(['de' => 'Unsere Lieferanten'], $table->getTranslations('description'));
        $this->assertSame(['de' => 'Firma', 'en' => 'Company'], $table->fields->first()->getTranslations('label'));

        Livewire::test(EditCustomTable::class, ['record' => $table->getRouteKey()])
            ->assertFormSet(['label.de' => 'Lieferanten', 'label.en' => 'Suppliers'])
            ->fillForm(['label' => ['de' => 'Lieferanten', 'en' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['de' => 'Lieferanten'], $table->fresh()->getTranslations('label'));
    }

    public function test_changing_a_field_keeps_its_other_languages(): void
    {
        $table = $this->contracts();
        $field = $table->fields->firstWhere('name', 'titel');

        // Englische Oberfläche, Änderung ohne Bezeichnung: Deutsch darf nicht verloren gehen.
        app()->setLocale('en');
        $this->service()->updateField($field, ['is_searchable' => false]);

        $this->assertSame(['de' => 'Titel', 'en' => 'Title'], $field->fresh()->getTranslations('label'));
    }

    public function test_names_in_lists_and_labels_use_the_current_language(): void
    {
        $this->contracts();
        $this->actingAs($this->superAdmin());
        app()->setLocale('en');

        $this->assertSame('Data: Contracts: view', RoleForm::label('data.vertraege.view'));
        $this->assertSame('Data: Contracts', ActivityChanges::typeLabel(DynamicRecord::MORPH_PREFIX.'vertraege'));
        $this->assertSame(['vertraege' => 'Contracts'], CustomTable::labelOptions());

        Livewire::test(ListCustomTables::class)
            ->searchTable('Contr')
            ->assertCanSeeTableRecords(CustomTable::query()->where('name', 'vertraege')->get())
            ->searchTable('Verträ')
            ->assertCanSeeTableRecords(CustomTable::query()->where('name', 'vertraege')->get())
            ->sortTable('label');
    }

    public function test_data_menu_entry_takes_every_language_and_follows_renames(): void
    {
        $table = $this->contracts();
        $entry = app(DataMenu::class)->ensure($table);

        $this->assertSame(['de' => 'Verträge', 'en' => 'Contracts'], $entry->getTranslations('label'));

        $this->service()->updateTable($table, ['label' => ['de' => 'Kontrakte', 'en' => 'Agreements']]);

        $this->assertSame(['de' => 'Kontrakte', 'en' => 'Agreements'], Menu::query()->findOrFail($entry->id)->getTranslations('label'));
    }
}
