<?php

declare(strict_types=1);

namespace Tests\Browser;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Administration › Systemprotokoll (Testliste BTR-10). */
class SystemLogTest extends DuskTestCase
{
    /** BTR-10 */
    public function test_log_entries_are_found_by_reference_and_shown_in_full(): void
    {
        $this->superAdmin();
        // Eintrag mit eindeutiger Referenz in die Logdatei des Servers (gleicher Ordner wie die Anwendung im Test).
        $reference = Str::upper(Str::random(8));
        File::append(storage_path('logs/laravel.log'), '['.now()->format('Y-m-d H:i:s').'] testing.WARNING: Browsertest Systemprotokoll {"error_reference":"'.$reference.'"}'."\n#0 Ablaufzeile-{$reference}\n");

        $this->browse(function (Browser $browser) use ($reference): void {
            $this->login($browser, 'admin@example.com')
                ->visit('/admin/system-log?search='.$reference)
                ->waitForText('Systemprotokoll', 15)
                ->waitForText('Browsertest Systemprotokoll', 15)
                ->assertSee($reference)
                // Zeilenaktion als Symbol (B-113): Bezeichnung als aria-label.
                ->click('tr.fi-ta-row button[aria-label="Anzeigen"]')
                ->waitFor('[data-system-log-entry]', 15)
                ->assertSeeIn('[data-system-log-entry]', 'Ablaufzeile-'.$reference);
        });
    }
}
