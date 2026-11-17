<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\ExternalData\TableConnectorService;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use Database\Seeders\ExternalResearchDatabase;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\DuskTestCase;

/** Geplante Aufgaben (Bereich TSK): Verwaltung, Zeitplan aus dem Table-Connector, Dashboard-Widget. */
class ScheduledTasksTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    private const string ADMIN = 'admin@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin();
    }

    protected function externalDatabaseName(): string
    {
        return self::EXTERNAL_DATABASE;
    }

    /** TSK-01 */
    public function test_system_tasks_run_now_and_show_the_run(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)
                ->visit('/admin/scheduled-tasks')
                ->waitForText('Laufprotokoll bereinigen', 15)
                ->assertSee('Datenstände bereinigen')
                ->assertSee('täglich um 03:30')
                ->assertPresent('[data-scheduler-missing]');

            // Systemaufgaben haben keinen Löschen-Knopf.
            $row = "//tr[contains(@class, 'fi-ta-row')][contains(., 'Laufprotokoll bereinigen')]";
            $this->assertCount(0, $browser->driver->findElements(WebDriverBy::xpath($row."//button[@aria-label='Löschen']")));

            $browser->clickAtXPath($row."//button[@aria-label='Jetzt ausführen']")
                ->waitForText('Laufprotokoll bereinigen', 5)
                ->pause(400);
            $this->clickVisibleButton($browser, 'Bestätigen')
                ->waitForText('Ausgeführt: Laufprotokoll bereinigen', 15);

            // Verlauf auf der Bearbeitungsseite.
            $browser->clickAtXPath($row."//a[@aria-label='Bearbeiten'] | ".$row."//button[@aria-label='Bearbeiten']")
                ->waitForText('Läufe', 15);
            // Der Verlauf lädt erst, wenn er sichtbar wird – scrollen, bis er da ist (die Seite kann noch wachsen).
            $browser->waitUsing(15, 300, static function () use ($browser): bool {
                return (bool) $browser->driver->executeScript(
                    'window.scrollTo(0, document.body.scrollHeight); return document.body.innerText.includes("Von Hand");',
                );
            });
            $browser->assertSee('0 Läufe gelöscht.');
        });
    }

    /** TSK-02 */
    public function test_schedule_set_up_from_the_table_connector(): void
    {
        ExternalResearchDatabase::on(self::EXTERNAL_DATABASE)->reset();
        $people = app(TableConnectorService::class)->connect($this->researchConnection(), ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'personen']);

        $this->browse(function (Browser $browser) use ($people): void {
            $this->login($browser, self::ADMIN)
                ->visit('/admin/table-connector/'.$people->id.'/edit')
                ->waitForText('Datenstände', 15);
            $this->clickVisibleButton($browser, 'Datenstände')->pause(300)
                ->script('window.scrollTo(0, document.body.scrollHeight);');
            $browser->waitFor('[data-snapshot-schedule]', 20)
                ->assertSeeIn('[data-snapshot-schedule]', 'Kein Zeitplan');
            // Weiterleitung: Filament rendert die Aktion als Link.
            $browser->clickLink('Zeitplan einrichten')
                ->waitFor('[id="form.frequency"]', 15)
                ->select('[id="form.frequency"]', 'weekdays')
                ->pause(500);
            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitForText('Läufe', 15);

            $task = ScheduledTask::query()->where('type', 'snapshot_refresh')->sole();
            $this->assertSame(['table_id' => $people->id], array_map('intval', $task->parameterValues()));
            $this->assertSame('werktags um 06:00', $task->schedule()->describe());

            // Wie vom Scheduler: der Stand erscheint als „automatisch“, der Zeitplan beim Table-Connector.
            Artisan::call('tasks:run', ['task' => $task->id]);
            $browser->visit('/admin/table-connector/'.$people->id.'/edit')->waitForText('Datenstände', 15);
            $this->clickVisibleButton($browser, 'Datenstände')->pause(300)
                ->script('window.scrollTo(0, document.body.scrollHeight);');
            $browser->waitFor('[data-snapshot-schedule]', 20)
                ->assertSeeIn('[data-snapshot-schedule]', 'werktags um 06:00')
                ->assertSee('automatisch');
        });

        $this->assertSame(20, $people->fresh()->latestSnapshot()?->row_count);
    }

    /** TSK-09: Art mit Textfeld als Parameter (Beispiel-Plugin Lager-Tafel) – vorbelegt, eingetippter Wert kommt an. */
    public function test_task_with_a_text_parameter_is_created(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)
                ->visit('/admin/scheduled-tasks/create')
                ->waitFor('[id="form.type"]', 15)
                ->select('[id="form.type"]', 'lager_tafel_inventory_reminder')
                ->waitFor('[id="form.parameters.days"]', 15)
                ->pause(500)
                ->assertInputValue('[id="form.parameters.days"]', '90')
                ->clear('[id="form.parameters.days"]')
                ->type('[id="form.parameters.days"]', '30')
                ->pause(300);
            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitForText('Läufe', 15)
                ->assertDontSee('muss ausgefüllt werden');
        });

        $task = ScheduledTask::query()->where('type', 'lager_tafel_inventory_reminder')->sole();
        $this->assertSame(['days' => 30], array_map('intval', $task->parameterValues()));
    }

    /** TSK-12: Technische Details eines fehlgeschlagenen Laufs – nur mit Recht, Hinweis auf die Logdatei. */
    public function test_error_details_of_a_failed_run(): void
    {
        $prune = ScheduledTask::query()->where('type', 'task_runs_prune')->sole();
        $prune->runs()->create([
            'trigger' => TaskRun::MANUAL, 'status' => ScheduledTask::FAILED, 'started_at' => now(), 'finished_at' => now(),
            'message' => 'Die Aufgabe ist fehlgeschlagen (Referenz TEST1234).',
            'error_detail' => TaskRunner::errorDetail(new \RuntimeException('Testfehler für die Anzeige'), 'TEST1234'),
        ]);
        $this->activeUser('aufgaben@example.com')->givePermissionTo(['tasks.view', 'tasks.update']);

        $this->browse(function (Browser $admin, Browser $other): void {
            $this->login($admin, 'admin@example.com')
                ->visit('/admin/scheduled-tasks/'.ScheduledTask::query()->where('type', 'task_runs_prune')->value('id').'/edit')
                ->waitForText('Läufe', 15);
            $this->scrollToRuns($admin);
            $admin->press('Details')
                ->waitForText('Technische Details (Referenz TEST1234)', 15)
                ->assertSee('Testfehler für die Anzeige')
                ->assertSee('RuntimeException')
                ->assertSee('storage/logs/laravel.log')
                ->assertSee('dort nach TEST1234 suchen');

            $this->login($other, 'aufgaben@example.com')
                ->visit('/admin/scheduled-tasks/'.ScheduledTask::query()->where('type', 'task_runs_prune')->value('id').'/edit')
                ->waitForText('Läufe', 15);
            $this->scrollToRuns($other);
            $other->assertDontSee('Details');
        });
    }

    /** Der Verlauf lädt erst, wenn er sichtbar wird: scrollen, bis der fehlgeschlagene Testlauf da ist. */
    private function scrollToRuns(Browser $browser): void
    {
        $browser->waitUsing(15, 300, static fn (): bool => (bool) $browser->driver->executeScript(
            'window.scrollTo(0, document.body.scrollHeight); return document.body.innerText.includes("Referenz TEST1234");',
        ));
    }

    /** TSK-03 */
    public function test_dashboard_widget_shows_failures(): void
    {
        $prune = ScheduledTask::query()->where('type', 'task_runs_prune')->sole();
        $prune->runs()->create(['trigger' => TaskRun::SCHEDULE, 'status' => ScheduledTask::FAILED, 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(5), 'message' => 'Testfehler']);
        $prune->forceFill(['consecutive_failures' => 1, 'last_status' => ScheduledTask::FAILED])->save();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->waitForText('Dashboard anpassen', 15);
            // Das Widget steht unten und lädt erst beim Hinscrollen.
            $browser->script('window.scrollTo(0, document.body.scrollHeight);');
            $browser->waitFor('[data-tasks-status="failing"]', 20)
                ->assertSeeIn('[data-tasks-status="failing"]', 'Laufprotokoll bereinigen')
                ->assertPresent('[data-tasks-status="scheduler"]')
                ->assertSee('Testfehler');
        });

    }
}
