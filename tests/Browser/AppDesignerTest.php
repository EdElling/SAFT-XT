<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Apps\Designer\AppDesigner;
use App\Apps\Designer\DraftCompiler;
use App\Apps\Designer\DraftReports;
use App\Models\App;
use App\Models\AppDraft;
use App\Models\CustomTable;
use App\Models\Report;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * KI-App-Designer (Bereich FAP, B-107 Etappe 7a). Ohne KI im Testserver: Ein vorbereiteter Entwurf zeigt Vorschau und
 * Diagramm, „App erzeugen“ legt die App als Arbeit an (Fundament 2, F3b: der eingebettete Fortschritt treibt sie im
 * Browser) und wechselt hinein. Das Gespräch mit der KI prüfen die Feature-Tests.
 */
class AppDesignerTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private AppDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $label = static fn (string $de): array => ['de' => $de, 'en' => $de, 'fr' => $de, 'it' => $de];
        $field = static fn (string $name, string $type, array $more = []): array => [...[
            'name' => $name, 'label' => $label(ucfirst($name)), 'type' => $type, 'required' => false, 'unique' => false, 'searchable' => false,
            'sortable' => false, 'length' => 0, 'precision' => 0, 'scale' => 0, 'choices' => [], 'relation_table' => '', 'relation_display' => [],
            'file_kinds' => [], 'max_mb' => 0, 'max_files' => 0, 'rules' => '', 'formula' => '',
        ], ...$more];
        $proposal = [
            'kind' => 'app', 'question' => '',
            'app' => ['name' => $label('Kurse'), 'description' => $label('Kurse und Anmeldungen'), 'prefix' => 'kurse', 'icon' => 'academic-cap'],
            'tables' => [
                ['name' => 'kurse_kurse', 'label' => $label('Kurse'), 'description' => $label(''), 'icon' => 'academic-cap', 'rules' => [], 'fields' => [
                    $field('titel', 'string', ['required' => true]),
                    $field('summe', 'computed', ['formula' => 'sum(kurse_anmeldungen.betrag)', 'precision' => 12, 'scale' => 2]),
                ]],
                ['name' => 'kurse_anmeldungen', 'label' => $label('Anmeldungen'), 'description' => $label(''), 'icon' => 'users', 'rules' => [], 'fields' => [
                    $field('kurs_id', 'relation', ['relation_table' => 'kurse_kurse', 'relation_display' => ['titel'], 'required' => true]),
                    $field('betrag', 'decimal', ['precision' => 10, 'scale' => 2]),
                ]],
            ],
            'menus' => [['label' => $label('Kursbetrieb'), 'icon' => 'calendar', 'tables' => ['kurse_kurse', 'kurse_anmeldungen']]],
        ];
        $compiled = app(DraftCompiler::class)->compile($proposal);
        $this->assertSame([], $compiled['problems']);

        $this->draft = AppDraft::query()->create([
            'user_id' => User::query()->where('email', self::ADMIN)->value('id'),
            'title' => 'Kursverwaltung mit Anmeldungen',
            'history' => [['role' => 'user', 'content' => 'Kursverwaltung mit Anmeldungen'], ['role' => 'assistant', 'content' => 'Proposal with the tables: kurse_kurse, kurse_anmeldungen.']],
            'proposal' => $proposal,
            'definition' => $compiled['definition'],
            'status' => AppDraft::DRAFT,
        ]);
    }

    /** FAP-19 */
    public function test_a_draft_is_previewed_and_created_as_an_active_app(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Mit KI entwerfen', 15)
                ->clickLink('Mit KI entwerfen')
                ->waitFor('[data-app-designer]', 15)
                // Ohne KI im Testserver: Hinweis statt Eingabe, Entwurf und Vorschau bleiben.
                ->assertPresent('[data-designer-unavailable]')
                ->assertSeeIn('[data-designer-drafts]', 'Kursverwaltung mit Anmeldungen')
                ->assertSeeIn('[data-designer-chat]', 'Vorschlag mit den Tabellen kurse_kurse, kurse_anmeldungen')
                ->assertSeeIn('[data-designer-app-name]', 'Kurse')
                ->assertSeeIn('[data-designer-counts]', '2 Tabellen')
                ->assertSeeIn('[data-designer-table="kurse_kurse"]', 'sum(kurse_anmeldungen.betrag)')
                ->waitFor('[data-designer-preview] .saft-data-model svg', 20)
                ->assertDontSeeIn('[data-designer-preview] .saft-data-model', 'Räume');

            // Geänderter Entwurf (wie nach „Anpassen“): Das Diagramm wird neu gezeichnet.
            $this->addRooms();
            $browser->click('[data-designer-draft="'.$this->draft->id.'"]')
                ->waitForTextIn('[data-designer-preview] .saft-data-model', 'Räume', 20)
                ->assertSeeIn('[data-designer-counts]', '3 Tabellen');

            $this->clickVisibleButton($browser, 'App erzeugen');
            $browser->waitFor('.fi-modal-window input[id$="prefix"]', 15)
                ->assertInputValue('.fi-modal-window input[id$="prefix"]', 'kurse');
            $browser->within('.fi-modal-window', fn (Browser $modal) => $modal->press('App erzeugen'));
            $browser->waitForText('App „Kurse“ erzeugt.', 30)
                ->waitForLocation('/admin', 15);
        });

        $app = App::query()->where('prefix', 'like', 'kurse%')->sole();
        $this->assertTrue($app->is_active);
        $this->assertSame(['kurse_anmeldungen', 'kurse_kurse', 'kurse_raeume'], CustomTable::query()->where('app_id', $app->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(AppDraft::CREATED, $this->draft->refresh()->status);
    }

    /** FAP-21 */
    public function test_reports_after_creating_show_progress_links_and_retry(): void
    {
        $admin = User::query()->where('email', self::ADMIN)->firstOrFail();
        // Wie die Arbeit „App erzeugen“, ohne Übersetzung (keine KI im Testserver).
        $designer = app(AppDesigner::class);
        $app = $designer->install($admin, $this->draft, $designer->prepareCreate($admin, $this->draft), reports: false);
        $report = Report::query()->create(['name' => 'Summe je Kurs', 'is_shared' => true, 'owner_id' => $admin->id, 'definition' => [
            'base' => (int) CustomTable::query()->where('name', 'kurse_kurse')->value('id'),
            'fields' => [['key' => 't', 'path' => [], 'field' => (int) CustomTable::query()->where('name', 'kurse_kurse')->sole()->fields()->where('name', 'titel')->value('id')]],
        ]]);
        $this->draft->refresh()->forceFill(['reports' => [
            ['title' => 'Summe je Kurs', 'description' => 'x', 'status' => DraftReports::CREATED, 'report_id' => $report->id, 'message' => null],
            ['title' => 'Anmeldungen je Monat', 'description' => 'y', 'status' => DraftReports::FAILED, 'report_id' => null, 'message' => 'Welcher Zeitraum?'],
        ]])->save();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/app-designer?draft='.$this->draft->id)
                ->waitFor('[data-designer-reports]', 15)
                ->assertSeeIn('[data-designer-report="created"]', 'Summe je Kurs')
                ->assertSeeIn('[data-designer-report="failed"]', 'nicht möglich: Welcher Zeitraum?')
                ->assertPresent('[data-designer-to-app]')
                // Erneut versuchen startet die Schleife; ohne KI im Testserver wieder „nicht möglich“, mit neuem Grund.
                ->click('[data-designer-report-retry]')
                ->waitUntilMissingText('Welcher Zeitraum?', 30)
                ->waitFor('[data-designer-report="failed"]', 30)
                ->assertDontSee('wird erstellt');
            $browser->clickLink('Summe je Kurs')->waitForLocation('/admin/reports/'.Report::query()->where('name', 'Summe je Kurs')->value('id'), 15);
        });

        $this->assertNotNull($app->fresh());
    }

    /** Entwurf um eine Tabelle ergänzen, wie es eine Runde „Anpassen“ täte. */
    private function addRooms(): void
    {
        $proposal = $this->draft->refresh()->proposal;
        $proposal['tables'][] = ['name' => 'kurse_raeume', 'label' => ['de' => 'Räume', 'en' => 'Rooms', 'fr' => 'Salles', 'it' => 'Aule'], 'description' => ['de' => '', 'en' => '', 'fr' => '', 'it' => ''], 'icon' => 'home', 'rules' => [], 'fields' => [
            [...$proposal['tables'][0]['fields'][0], 'name' => 'name'],
        ]];
        $compiled = app(DraftCompiler::class)->compile($proposal);
        $this->assertSame([], $compiled['problems']);
        $this->draft->forceFill(['proposal' => $proposal, 'definition' => $compiled['definition']])->save();
    }
}
