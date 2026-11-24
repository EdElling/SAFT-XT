<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\WorkItem;
use App\Services\DynamicSchemaService;
use App\Work\Types\ExportWork;
use Laravel\Dusk\Browser;
use RuntimeException;
use Tests\Browser\Concerns\CreatesOrderData;
use Tests\DuskTestCase;

/** Arbeiten (Bereich ARB, Fundament 2): Seite „Arbeiten“ bzw. „Meine Arbeiten“, Fortschritt und Ergebnis-Download. */
class WorkItemsTest extends DuskTestCase
{
    use CreatesOrderData;

    private const string ADMIN = 'admin@example.com';

    private string $downloads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin(self::ADMIN);
        $this->createOrderData();
        app(DynamicSchemaService::class)->updateTable($this->kunden, ['export_enabled' => true]);

        $this->downloads = sys_get_temp_dir().'/saft-dusk-work-'.getmypid();
        @mkdir($this->downloads);
        array_map('unlink', glob($this->downloads.'/*') ?: []);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->downloads.'/*') ?: []);
        @rmdir($this->downloads);

        parent::tearDown();
    }

    private function allowDownloads(Browser $browser): void
    {
        $browser->driver->executeCustomCommand('/session/:sessionId/chromium/send_command', 'POST', [
            'cmd' => 'Browser.setDownloadBehavior',
            'params' => ['behavior' => 'allow', 'downloadPath' => $this->downloads],
        ]);
    }

    private function downloaded(Browser $browser, string $extension): string
    {
        $file = null;

        $browser->waitUsing(20, 200, function () use ($extension, &$file): bool {
            $file = glob($this->downloads.'/*.'.$extension)[0] ?? null;

            return $file !== null && filesize($file) > 0 && glob($this->downloads.'/*.crdownload') === [];
        });

        return $file ?? throw new RuntimeException("Keine .{$extension}-Datei heruntergeladen.");
    }

    /** ARB-01: Ein Export ist eine Arbeit; die Liste zeigt sie, die Seite liefert das Ergebnis als echten Download. */
    public function test_a_finished_export_is_listed_and_its_result_downloads_from_the_job_page(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->allowDownloads($browser);
            $browser->visit('/admin/data/kunden')->waitForText('Beta AG', 15);

            // Kleine Liste: nach der Mitarbeit im Request fertig, Datei kommt wie gewohnt.
            $browser->clickAtXPath("(//button[contains(., 'Exportieren')])[1]")->pause(300);
            $this->clickVisibleButton($browser, 'CSV (.csv)');
            unlink($this->downloaded($browser, 'csv'));

            $item = WorkItem::query()->where('type', ExportWork::KEY)->latest('id')->firstOrFail();
            $this->assertSame(WorkItem::DONE, $item->status);

            // Benutzermenü → Meine Arbeiten: die Arbeit steht in der Liste.
            $browser->click('.fi-user-menu-trigger')->waitForText('Meine Arbeiten', 10)->clickLink('Meine Arbeiten')
                ->waitForLocation('/admin/work-items', 15)
                ->waitFor('.fi-ta-table', 15)
                ->assertSee('Exportieren')
                ->assertSee('Kunden')
                ->assertSee('fertig');

            // Seite der Arbeit: 100 %, Meldung, „Ergebnis herunterladen“ liefert die Datei – kein Seitenwechsel.
            $browser->visit('/admin/work-items/'.$item->uuid)
                ->waitFor('[data-work-progress]', 15)
                ->assertSeeIn('[data-work-progress]', '100 %')
                ->assertSeeIn('[data-work-message]', '2 Zeilen exportiert.')
                ->click('[data-work-download]');
            $path = $this->downloaded($browser, 'csv');

            $this->assertStringStartsWith('kunden_', basename($path));
            $browser->assertPathIs('/admin/work-items/'.$item->uuid)->assertSee('Ergebnis herunterladen')->assertDontSee('Firma;Ort');
        });
    }
}
