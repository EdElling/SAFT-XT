<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataPermissions;
use App\Models\Activity;
use App\Models\Role;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Laravel\Dusk\Browser;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Browser\Concerns\CreatesOrderData;
use Tests\DuskTestCase;

/** Export der Listen (Bereich EXP). */
class ExportTest extends DuskTestCase
{
    use CreatesOrderData;

    private const string ADMIN = 'admin@example.com';

    private const string USER = 'vertrieb@example.com';

    private string $downloads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin(self::ADMIN);
        $this->createOrderData();

        $this->downloads = sys_get_temp_dir().'/saft-dusk-downloads-'.getmypid();
        @mkdir($this->downloads);
        array_map('unlink', glob($this->downloads.'/*') ?: []);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->downloads.'/*') ?: []);
        @rmdir($this->downloads);

        parent::tearDown();
    }

    private function enableExport(): void
    {
        app(DynamicSchemaService::class)->updateTable($this->kunden, ['export_enabled' => true]);
        app(DynamicSchemaService::class)->updateTable($this->bestellungen, ['export_enabled' => true]);
    }

    private function restrictedUser(array $actions): User
    {
        $role = Role::create(['name' => 'vertrieb', 'guard_name' => 'web']);
        $role->givePermissionTo(array_map(static fn (string $action): string => DataPermissions::name('kunden', $action), $actions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->activeUser(self::USER)->assignRole($role);
    }

    /** Lädt in ein eigenes Verzeichnis, statt der Standardablage des Browsers. */
    private function allowDownloads(Browser $browser): void
    {
        $browser->driver->executeCustomCommand('/session/:sessionId/chromium/send_command', 'POST', [
            'cmd' => 'Browser.setDownloadBehavior',
            'params' => ['behavior' => 'allow', 'downloadPath' => $this->downloads],
        ]);
    }

    private function export(Browser $browser, string $format, int $table = 1): Browser
    {
        $browser->clickAtXPath("(//button[contains(., 'Exportieren')])[{$table}]")->pause(300);

        return $this->clickVisibleButton($browser, $format);
    }

    /** Wartet, bis die fertige Datei vorliegt, und liefert ihren Pfad. */
    private function downloaded(Browser $browser, string $extension): string
    {
        $file = null;

        $browser->waitUsing(20, 200, function () use ($extension, &$file): bool {
            $file = glob($this->downloads.'/*.'.$extension)[0] ?? null;

            return $file !== null && filesize($file) > 0 && glob($this->downloads.'/*.crdownload') === [];
        });

        return $file ?? throw new RuntimeException("Keine .{$extension}-Datei heruntergeladen.");
    }

    /** @return list<list<string>> */
    private function csv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'BOM für Excel');

        return array_values(array_map(
            static fn (string $line): array => str_getcsv($line, ';', '"', ''),
            array_filter(explode("\n", substr($content, 3)), filled(...)),
        ));
    }

    /** EXP-01 */
    public function test_switch_in_table_designer_controls_the_button(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
            $browser->assertDontSee('Exportieren');

            $browser->visit("/admin/table-designer/{$this->kunden->id}/edit")
                ->waitFor('[id="form.export_enabled"]', 15)
                ->click('[id="form.export_enabled"]')
                ->press('Speichern')
                ->waitForText('Gespeichert', 15);

            $this->assertTrue($this->kunden->fresh()->export_enabled);

            $browser->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15)->assertSee('Exportieren');
        });
    }

    /** EXP-02 */
    public function test_role_right_from_the_matrix_controls_the_button(): void
    {
        $this->enableExport();
        $user = $this->restrictedUser(['view']);
        $role = $user->roles->first();

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, self::USER)->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
            $browser->assertDontSee('Exportieren');
            $this->logout($browser);

            $checkbox = 'input[data-matrix-cell="kunden.export"]';
            $this->login($browser, self::ADMIN)->visit("/admin/roles/{$role->id}/edit?tab=data-rights")
                ->waitFor($checkbox, 15)
                ->assertSee('Exportieren')
                ->click($checkbox)
                ->press('Speichern')
                ->waitForText('Gespeichert', 15);
            // Neu laden: Die Meldung verdeckt sonst das Benutzermenü.
            $browser->visit('/admin')->waitFor('.fi-user-menu-trigger', 15);
            $this->logout($browser);

            $this->login($browser, self::USER)->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15)->assertSee('Exportieren');
        });
    }

    /** EXP-03 */
    public function test_csv_download_contains_the_filtered_view(): void
    {
        $this->enableExport();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->allowDownloads($browser);
            $browser->visit('/admin/data/kunden')->waitForText('Beta AG', 15);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->type('input[id="tableFiltersForm.ort.value"]', 'Berlin');
            });
            $browser->waitUntilMissingText('Beta AG', 15);

            $this->export($browser, 'CSV (.csv)');
            $path = $this->downloaded($browser, 'csv');

            $this->assertMatchesRegularExpression('/^kunden_\d{4}-\d{2}-\d{2}_\d{4}\.csv$/', basename($path));
            $this->assertSame([['Firma', 'Ort'], ['ACME GmbH', 'Berlin']], $this->csv($path));

            // Die Seite bleibt nach dem Download bedienbar.
            $browser->assertSee('ACME GmbH')->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** EXP-04 */
    public function test_excel_download_opens_with_header_and_rows(): void
    {
        $this->enableExport();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->allowDownloads($browser);
            $browser->visit('/admin/data/kunden')->waitForText('Beta AG', 15);

            $this->export($browser, 'Excel (.xlsx)');
            $path = $this->downloaded($browser, 'xlsx');

            $reader = new Reader;
            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $this->assertSame('Kunden', $sheet->getName());

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(static fn ($cell): mixed => $cell->getValue(), $row->getCells());
                }
            }

            $reader->close();

            // Neueste zuerst, wie die Liste.
            $this->assertSame([['Firma', 'Ort'], ['Beta AG', 'Hamburg'], ['ACME GmbH', 'Berlin']], $rows);
        });
    }

    /** EXP-05 */
    public function test_detail_table_exports_only_the_selected_customer(): void
    {
        $this->enableExport();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->allowDownloads($browser);
            $browser->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);

            $this->export($browser, 'CSV (.csv)', 2);
            $path = $this->downloaded($browser, 'csv');

            $this->assertStringStartsWith('bestellungen_', basename($path));
            $this->assertSame([['Nummer'], ['B-100']], $this->csv($path));
        });
    }

    /** EXP-06 */
    public function test_export_appears_in_the_activity_log(): void
    {
        $this->enableExport();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->allowDownloads($browser);
            $browser->visit('/admin/data/kunden')->waitForText('Beta AG', 15);

            $this->export($browser, 'CSV (.csv)');
            $this->downloaded($browser, 'csv');

            $browser->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)->assertSee('exportiert');

            $entry = Activity::query()->where('event', 'exported')->sole();
            $browser->visit("/admin/activity-log/{$entry->id}")->waitForText('Änderungen', 15)
                ->assertSee('format')
                ->assertSee('csv')
                ->assertSee('zeilen');
        });
    }

    /** EXP-07 */
    public function test_user_without_right_can_not_export_and_sees_no_button(): void
    {
        $this->enableExport();
        $this->restrictedUser(['view']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::USER)->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);

            $browser->assertDontSee('Exportieren');
            $this->assertSame([], glob($this->downloads.'/*') ?: []);
        });
    }
}
