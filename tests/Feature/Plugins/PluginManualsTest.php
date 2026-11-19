<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Ai\ChatClient;
use App\Apps\CurrentApp;
use App\Apps\PluginApps;
use App\Documentation\Documentation;
use App\Filament\Pages\DocumentationPage;
use App\Help\HelpAssistant;
use App\Models\App;
use App\Plugins\Api\Manual;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\Context\SchemaMarkdown;
use App\Plugins\PluginInspector;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use App\Plugins\SaftPlugin;
use App\Plugins\Testing\PluginTestCase;
use App\Services\DynamicSchemaService;
use App\Settings\AppSettings;
use Tests\Fakes\FakeChatClient;

/** B-037/B-038: Handbücher aus Plugins (Online-Hilfe, Hilfe-Assistent) und Beschreibung von Prüfregeln. */
class PluginManualsTest extends PluginTestCase
{
    private const string TRANSFER = 'plugin-lager-umbuchung-manual';

    private const string BOARD = 'plugin-lager-tafel-manual';

    /** Tabellen der Lager-Demo, die die beiden Plugins brauchen – ohne sie ruhen Plugins und Handbücher. */
    private function warehouse(): void
    {
        $relation = static fn (string $name, string $table): array => ['name' => $name, 'label' => $name, 'type' => 'relation', 'options' => ['table' => $table, 'display_field' => 'bezeichnung']];
        $this->createTable('lager_artikel', 'Artikel', [
            ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
        ]);
        $this->createTable('lager_orte', 'Lagerorte', [['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string']]);
        $this->createTable('lager_bestaende', 'Bestände', [$relation('artikel_id', 'lager_artikel'), $relation('lagerort_id', 'lager_orte'), ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer']]);
        $this->createTable('lager_bewegungen', 'Lagerbewegungen', [
            $relation('artikel_id', 'lager_artikel'), $relation('lagerort_id', 'lager_orte'),
            ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['options' => [['value' => 'Eingang']]]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'zeitpunkt', 'label' => 'Zeitpunkt', 'type' => 'datetime'],
        ]);
    }

    public function test_manuals_rest_without_the_tables_of_their_plugin(): void
    {
        $user = $this->actingWithPermissions(['lager-umbuchung.execute']);

        $this->assertSame(['user', 'faq'], Documentation::visibleFor($user), 'Ohne Lager-Demo ruhen Plugin und Handbuch.');
    }

    public function test_plugin_manuals_appear_for_people_with_a_permission(): void
    {
        $this->warehouse();
        app()->setLocale('de');
        $user = $this->actingWithPermissions(['lager-umbuchung.execute']);

        $this->assertSame(['user', 'faq', self::TRANSFER], Documentation::visibleFor($user));
        $this->assertSame('Lager-Umbuchung', Documentation::label(self::TRANSFER));
        $this->get(DocumentationPage::getUrl())->assertOk()
            ->assertSee('data-doc-tab="'.self::TRANSFER.'"', false)
            ->assertSee('id="'.self::TRANSFER.'-mindestbestand"', false)
            ->assertDontSee('data-doc-tab="'.self::BOARD.'"', false);

        $this->actingWithPermissions(['data.lager_bewegungen.view']);
        $this->get(DocumentationPage::getUrl())->assertSee('data-doc-tab="'.self::TRANSFER.'"', false);

        $this->actingWithPermissions([]);
        $this->get(DocumentationPage::getUrl())->assertDontSee('data-doc-tab="'.self::TRANSFER.'"', false);
    }

    public function test_language_falls_back_and_manuals_rest_with_their_plugin(): void
    {
        $this->warehouse();
        $user = $this->actingWithPermissions(['lager-tafel.view']);
        app()->setLocale('it');
        $this->assertSame('it', Documentation::language(self::BOARD));
        $this->assertSame('Tabellone delle giacenze', Documentation::label(self::BOARD));
        $this->assertStringStartsNotWith('# ', ltrim(Documentation::source(self::BOARD)), 'Überschrift der Ebene 1 entfällt.');

        app()->setLocale('de');
        PluginStates::temporarily('lager-umbuchung', false, function () use ($user): void {
            // Lager-Tafel braucht Lager-Umbuchung: Beide ruhen, beide Handbücher fehlen.
            $this->assertNotContains(self::BOARD, Documentation::visibleFor($user));
            $this->assertNotContains(self::TRANSFER, Documentation::keys());
        });

        $this->assertContains(self::BOARD, Documentation::visibleFor($user));
    }

    public function test_help_assistant_knows_plugin_manuals(): void
    {
        $this->warehouse();
        config(['ai.provider' => 'anthropic']);
        $settings = app(AppSettings::class);
        $settings->ai_help_enabled = true;
        $settings->save();
        $fake = (new FakeChatClient)->push('Siehe [Inventur](#'.self::TRANSFER.'-bestände-ansehen-und-korrigieren).');
        $this->app->instance(ChatClient::class, $fake);
        app()->setLocale('de');
        $user = $this->actingWithPermissions(['help.assistant', 'lager-umbuchung.execute']);

        $conversation = app(HelpAssistant::class)->ask($user, null, 'Wie korrigiere ich einen Bestand?');

        $system = implode("\n", array_column($fake->calls[0]['system'], 'text'));
        $this->assertStringContainsString('<document id="'.self::TRANSFER.'" title="Lager-Umbuchung">', $system);
        $this->assertStringContainsString('{#'.self::TRANSFER.'-bestände-ansehen-und-korrigieren}', $system);
        $this->assertStringNotContainsString(self::BOARD, $system, 'Ohne Recht von Lager-Tafel nicht dessen Handbuch.');
        $this->assertSame(self::TRANSFER, $conversation->messages()->get()->last()->sources[0]['source'] ?? null);
    }

    public function test_field_rule_descriptions_reach_the_data_model(): void
    {
        app()->setLocale('de');
        $this->createTable('personen', 'Personen', [
            ['name' => 'iban', 'label' => 'IBAN', 'type' => 'string', 'options' => ['length' => 40], 'validation_rules' => ['checks' => ['ch-pruefungen.iban']]],
        ]);

        $markdown = app(SchemaMarkdown::class)->tables(app(SchemaExporter::class)->export(['personen']));

        $this->assertStringContainsString('plugin checks: ch-pruefungen.iban (Länderkürzel, zwei Prüfziffern und Kontonummer', $markdown);
    }

    public function test_check_reports_invalid_or_missing_manuals(): void
    {
        $plugin = new class extends SaftPlugin
        {
            public function id(): string
            {
                return 'handbuch-test';
            }

            public function label(): string
            {
                return 'Handbuch-Test';
            }

            public function manuals(): array
            {
                return [
                    new Manual('Falsch', ['de' => 'X', 'en' => 'X'], 'docs'),
                    new Manual('fehlt', ['de' => 'X', 'en' => 'X'], 'gibt/es/nicht'),
                ];
            }
        };
        app()->setLocale('de');

        $messages = array_column(app(PluginInspector::class)->inspect($plugin), 'message', 'message');

        $this->assertArrayHasKey('Handbuch „Falsch“: ungültige Kennung (Kleinbuchstaben, Ziffern und Bindestriche).', $messages);
        $this->assertArrayHasKey('Handbuch „fehlt“: die englische Fassung gibt/es/nicht/en.md fehlt.', $messages);
    }

    public function test_manuals_of_an_apps_plugin_appear_only_in_that_app(): void
    {
        $this->warehouse();
        $warehouseApp = App::query()->orderBy('id')->firstOrFail();
        $shop = App::query()->create(['name' => ['de' => 'Shop'], 'sort_order' => 9]);
        app(DynamicSchemaService::class)->createTable(['app_id' => $shop->id, 'name' => 'kunden', 'label' => 'Kunden'], [['name' => 'firma', 'label' => 'Firma', 'type' => 'string']]);
        $user = $this->actingWithPermissions(['lager-umbuchung.execute', 'data.lager_artikel.view', 'data.kunden.view']);

        $this->assertSame($warehouseApp->id, app(PluginApps::class)->appIdFor(app(PluginRegistry::class)->get('lager-umbuchung')));

        app(CurrentApp::class)->switch($user, $shop);
        $this->assertNotContains(self::TRANSFER, Documentation::visibleFor($user), 'Lager-Plugin gehört nicht zum Shop.');

        app(CurrentApp::class)->switch($user, $warehouseApp);
        $this->assertContains(self::TRANSFER, Documentation::visibleFor($user));
    }
}
