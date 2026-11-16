<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\Dashboard;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Persönlich anpassbares Dashboard: Bearbeitungsmodus, Anordnung, Ausblenden, Standard, Rechte. */
class DashboardLayoutPageTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->user = $this->userWithPermissions(['widgets.last_login', 'widgets.my_logins', 'widgets.my_changes', 'widgets.recent_changes', 'activity_log.view', 'widgets.reports', 'reports.view']);
        $this->actingAs($this->user);
    }

    public function test_edit_mode_moves_hides_shows_and_resets_and_is_kept_for_the_user(): void
    {
        $page = Livewire::test(Dashboard::class);
        $default = $page->instance()->layout();
        // Wie bisher: halbbreite Karten abwechselnd links und rechts, volle Breite darunter.
        $this->assertSame(['AccountWidget', 'MyLoginsWidget'], $default['left']);
        $this->assertSame(['LastLoginWidget', 'MyChangesWidget'], $default['right']);
        $this->assertSame(['RecentChangesWidget'], $default['full']);

        $page->assertDontSeeHtml('data-dashboard-editor')
            ->callAction('customizeDashboard')
            ->assertSet('editing', true)
            ->assertSeeHtml('data-dashboard-editor')
            ->assertSeeHtml('data-hide-card="MyLoginsWidget"')
            ->assertSee('Meine Anmeldungen');

        // Verschieben: neue Reihenfolge und Spalte; volle Breite darf nicht in eine Spalte, Unbekanntes fällt weg.
        $page->call('saveLayout', ['left' => ['MyLoginsWidget', 'RecentChangesWidget', 'Fremd'], 'right' => ['MyChangesWidget', 'LastLoginWidget', 'AccountWidget'], 'full' => []]);
        // assertEquals: MySQL sortiert JSON-Schlüssel; die Reihenfolge innerhalb der Listen zählt weiterhin.
        $this->assertEquals(
            ['left' => ['MyLoginsWidget'], 'right' => ['MyChangesWidget', 'LastLoginWidget', 'AccountWidget'], 'full' => ['RecentChangesWidget'], 'hidden' => []],
            $this->user->fresh()->dashboard_layout,
        );

        // Ausblenden und wieder einblenden.
        $page->call('hideCard', 'MyChangesWidget')->assertSeeHtml('data-show-card="MyChangesWidget"');
        $this->assertSame(['MyChangesWidget'], $this->user->fresh()->dashboard_layout['hidden']);
        $this->assertStringNotContainsString('data-dashboard-card="MyChangesWidget"', Livewire::test(Dashboard::class)->html());
        $page->call('showCard', 'MyChangesWidget');
        $this->assertSame([], $this->user->fresh()->dashboard_layout['hidden']);

        // Wieder eingeblendet in die kürzere Spalte; neue Sitzung, gleiche Anordnung.
        $this->assertSame(['MyLoginsWidget', 'MyChangesWidget'], Livewire::test(Dashboard::class)->instance()->layout()['left']);

        $page->callAction('resetDashboard');
        $this->assertNull($this->user->fresh()->dashboard_layout);
        $this->assertSame($default, Livewire::test(Dashboard::class)->instance()->layout());

        $page->callAction('finishDashboard')->assertSet('editing', false)->assertDontSeeHtml('data-dashboard-editor');
    }

    public function test_rights_decide_and_reports_can_be_made_wide(): void
    {
        $report = Report::create(['name' => 'Kennzahl', 'owner_id' => $this->user->id, 'is_shared' => false, 'definition' => ['base' => 1, 'fields' => [], 'visual' => ['type' => 'kpi']]]);
        $this->user->dashboardReports()->attach($report);
        $key = 'report:'.$report->id;

        $layout = Livewire::test(Dashboard::class)->instance()->layout();
        $this->assertContains($key, [...$layout['left'], ...$layout['right']], 'Kennzahl standardmäßig halb breit.');

        // In den breiten Bereich gezogen: volle Breite.
        Livewire::test(Dashboard::class)->call('saveLayout', ['left' => [], 'right' => [], 'full' => [$key, 'RecentChangesWidget']]);
        $this->assertSame([$key, 'RecentChangesWidget'], Livewire::test(Dashboard::class)->instance()->layout()['full']);

        // Ohne Recht keine Karte – auch wenn sie gespeichert ist.
        $this->user->revokePermissionTo('widgets.recent_changes');
        $this->user->revokePermissionTo('widgets.reports');
        $layout = Livewire::test(Dashboard::class)->instance()->layout();
        $this->assertSame([], $layout['full']);
        $this->assertNotContains($key, [...$layout['left'], ...$layout['right'], ...$layout['hidden']]);
    }

    public function test_every_card_has_its_own_livewire_key(): void
    {
        // Mehrere angeheftete Auswertungen: mit gemeinsamem Schlüssel zeigte Livewire nach „Standard wiederherstellen“
        // eine davon mehrfach an.
        foreach (['Eins', 'Zwei'] as $name) {
            $this->user->dashboardReports()->attach(Report::create(['name' => $name, 'owner_id' => $this->user->id, 'is_shared' => false, 'definition' => ['base' => 1, 'fields' => [], 'visual' => ['type' => 'kpi']]]));
        }

        $page = Livewire::test(Dashboard::class)->callAction('customizeDashboard')->callAction('resetDashboard');
        preg_match_all('/wire:key="[^"]*\.content\.([^"]+)"/', $page->html(), $matches);
        $keys = array_values(array_unique($matches[1]));

        $this->assertNotContains('App\\Filament\\Widgets\\ReportCardWidget-0', $keys);
        foreach (Report::query()->pluck('id') as $id) {
            $this->assertContains('dashboard-card-report-'.$id, $keys);
        }
    }
}
