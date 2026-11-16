<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\Filament\Pages\DataModelPage;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\CreatesOrderData;
use Tests\DuskTestCase;

/** Seite „Datenmodell“ im echten Browser (Bereich DMO). */
class DataModelTest extends DuskTestCase
{
    use CreatesOrderData;

    private const string ADMIN = 'admin@example.com';

    private const string CANVAS = '[data-data-model-canvas]';

    private function canvasText(Browser $browser): string
    {
        return (string) $browser->script("return document.querySelector('".self::CANVAS."').textContent;")[0];
    }

    /** DMO-01 */
    public function test_menu_entry_draws_the_group_and_switches_fields_without_reload(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        // Lieferanten liegen in einer anderen Gruppe und erscheinen als Randknoten.
        $schema = app(DynamicSchemaService::class);
        $suppliers = $schema->createTable(['name' => 'lieferanten', 'label' => 'Lieferanten "Nord"'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $schema->addField($this->bestellungen->fresh(), ['name' => 'lieferant_id', 'label' => 'Lieferant', 'type' => 'relation', 'options' => ['table' => 'lieferanten', 'display_field' => 'firma']]);
        $master = Menu::create(['label' => 'Stammdaten', 'sort_order' => 80]);
        app(DataMenu::class)->create($suppliers, $master->id);

        $group = Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->value('parent_id');
        Menu::create(['label' => 'Datenmodell', 'parent_id' => $group, 'route' => DataModelPage::ROUTE, 'icon' => 'heroicon-o-share', 'sort_order' => 999]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin')->waitForText('Datenmodell', 15);
            $browser->clickLink('Datenmodell')
                ->waitFor(self::CANVAS.' svg', 30)
                ->assertSee('Datenmodell: Daten')
                ->assertDontSee('konnte nicht');

            $text = $this->canvasText($browser);

            foreach (['Kunden', 'Bestellungen', 'Positionen', 'Lieferanten "Nord"'] as $label) {
                $this->assertStringContainsString($label, $text);
            }

            $this->assertStringNotContainsString('firma', $text, 'Kompakt: nur Tabellennamen.');
            $browser->screenshot('data-model-compact');

            // Umschalten ohne Neuladen; die Wahl bleibt beim nächsten Aufruf erhalten.
            $browser->check('[data-data-model-toggle]')
                ->waitUsing(15, 100, fn (): bool => $browser->attribute(self::CANVAS, 'data-fields') === '1');
            $this->assertStringContainsString('firma', $this->canvasText($browser));
            $this->assertStringContainsString('Firma *', $this->canvasText($browser));
            $browser->screenshot('data-model-fields');

            $browser->refresh()->waitFor(self::CANVAS.' svg', 30)
                ->waitUsing(15, 100, fn (): bool => $browser->attribute(self::CANVAS, 'data-fields') === '1')
                ->uncheck('[data-data-model-toggle]')
                ->waitUsing(15, 100, fn (): bool => $browser->attribute(self::CANVAS, 'data-fields') === '0');
            $this->assertStringNotContainsString('firma', $this->canvasText($browser));
        });
    }
}
