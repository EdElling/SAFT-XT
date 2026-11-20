<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\DynamicData\Ui\DynamicRecordActions;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Models\CustomField;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** B-113: Zeilenaktionen der Admin-Tabellen als Symbole mit Tooltip; Verlauf der Felder im Table-Designer. */
class RowIconsTest extends DynamicDataTestCase
{
    public function test_admin_lists_show_row_actions_as_icons_with_a_symbol(): void
    {
        $this->actingAs($this->superAdmin());
        $this->projects();

        foreach (['/admin/users', '/admin/roles?tab=all', '/admin/menus?tab=all', '/admin/table-designer?tab=all', '/admin/apps', '/admin/scheduled-tasks?tab=all', '/admin/activity-log', '/admin/database-connections', '/admin/report-links?tab=all', '/admin/identity-providers'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            preg_match_all('/<tr[^>]*fi-ta-row.*?<\/tr>/s', $html, $rows);
            // Listen mit Einträgen im Test (die übrigen dürfen leer sein).
            if (in_array($url, ['/admin/users', '/admin/roles?tab=all', '/admin/menus?tab=all', '/admin/table-designer?tab=all', '/admin/apps'], true)) {
                $this->assertNotEmpty($rows[0], $url);
            }

            foreach ($rows[0] as $row) {
                preg_match_all('/<button[^>]*class="[^"]*fi-icon-btn[^"]*"[^>]*>(.*?)<\/button>/s', $row, $buttons);

                foreach ($buttons[1] as $inner) {
                    $this->assertStringContainsString('<svg', $inner, $url.': Symbol-Knopf ohne Symbol');
                }
            }

            // Keine Zeilenaktion mit sichtbarem Text (Filament: Beschriftung als fi-link-label bzw. fi-btn-label).
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*fi-(link|btn)-label[^"]*"[^>]*>\s*(Bearbeiten|Löschen|Anzeigen)/', implode('', $rows[0]), $url.': Zeilenaktion mit Text');
        }
    }

    public function test_field_history_shows_the_changes(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        $field = $table->fields()->where('name', 'titel')->sole();
        $this->service()->updateField($field, ['label' => ['de' => 'Projekttitel']]);

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->assertActionVisible(TestAction::make('history')->table(CustomField::query()->find($field->id)))
            ->mountAction(TestAction::make('history')->table(CustomField::query()->find($field->id)))
            ->assertHasNoErrors();

        $rows = DynamicRecordActions::modelHistoryRows($field);
        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('Projekttitel', $rows[0]['what']);
    }
}
