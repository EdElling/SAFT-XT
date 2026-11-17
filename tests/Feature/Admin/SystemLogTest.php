<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\SystemLogPage;
use App\Logs\LogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** B-065: Administration › Systemprotokoll – Ende der Logdatei, nur mit Recht, nur lesen. */
class SystemLogTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->storage = sys_get_temp_dir().'/saft-system-log-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/logs');
        $this->app->useStoragePath($this->storage);
        File::put($this->storage.'/logs/laravel.log', implode("\n", [
            '[2026-11-17 08:00:00] local.INFO: Erster Eintrag',
            '[2026-11-17 09:00:00] local.WARNING: Geplante Aufgabe fehlgeschlagen {"task":4,"error":"SQLSTATE[42S02]","error_reference":"ABCD1234"}',
            '[stacktrace]',
            '#0 /var/www/app/Tasks/TaskRunner.php(66): run()',
            '[2026-11-17 10:00:00] testing.ERROR: Dritter Eintrag mit Ablauf',
            '#0 {main}',
            '',
        ]));
        File::put($this->storage.'/logs/notizen.txt', 'geheim');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_entries_are_parsed_newest_first_with_reference(): void
    {
        $entries = app(LogReader::class)->entries(null);

        $this->assertSame(['ERROR', 'WARNING', 'INFO'], array_map(static fn ($e): string => $e->level, $entries));
        $this->assertSame('Geplante Aufgabe fehlgeschlagen', $entries[1]->message, 'Kontext abgetrennt.');
        $this->assertSame('ABCD1234', $entries[1]->reference);
        $this->assertStringContainsString('#0 /var/www/app/Tasks/TaskRunner.php(66): run()', $entries[1]->text);
        $this->assertSame(['laravel.log'], app(LogReader::class)->files(), 'Nur Logdateien.');
        $this->assertStringEndsWith('logs/laravel.log', (string) app(LogReader::class)->path('../../.env'), 'Kein Pfad aus der Anfrage.');
    }

    public function test_only_the_end_of_large_files_is_read(): void
    {
        $line = '[2026-11-17 11:00:00] local.INFO: '.str_repeat('x', 1000);
        File::append($this->storage.'/logs/laravel.log', str_repeat($line."\n", (int) (LogReader::MAX_BYTES / 1000) + 50));

        $reader = app(LogReader::class);
        $this->assertTrue($reader->truncated(null));
        $this->assertLessThanOrEqual(LogReader::MAX_BYTES, strlen($reader->tail(null)));
        $this->assertStringStartsWith('[2026-11-17 11:00:00]', $reader->tail(null), 'Beginnt mit einem ganzen Eintrag.');
        $this->assertCount(LogReader::MAX_ENTRIES, $reader->entries(null));
    }

    public function test_page_only_with_permission_and_viewing_is_logged(): void
    {
        $this->actingAs($this->userWithPermissions(['tasks.view']));
        $this->get(SystemLogPage::getUrl())->assertForbidden();

        $user = $this->userWithPermissions(['system_log.view']);
        $this->actingAs($user);
        $this->get(SystemLogPage::getUrl())->assertOk()->assertSee('Systemprotokoll');
        $this->assertTrue(Activity::query()->where('event', 'system_log_viewed')->where('causer_id', $user->id)->exists());

        Livewire::test(SystemLogPage::class)
            ->assertSee('Dritter Eintrag mit Ablauf')
            ->searchTable('ABCD1234')
            ->assertSee('Geplante Aufgabe fehlgeschlagen')
            ->assertDontSee('Dritter Eintrag mit Ablauf')
            ->searchTable(null)
            ->filterTable('level', 'INFO')
            ->assertSee('Erster Eintrag')
            ->assertDontSee('Dritter Eintrag mit Ablauf');
    }

    public function test_search_from_the_address_and_download_of_the_extract(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::withQueryParams(['search' => 'ABCD1234'])->test(SystemLogPage::class)
            ->assertSet('tableSearch', 'ABCD1234')
            ->assertDontSee('Erster Eintrag')
            ->callAction('download')
            ->assertFileDownloaded(contentType: 'text/plain; charset=UTF-8');
    }
}
