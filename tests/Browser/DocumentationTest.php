<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Online-Hilfe „Dokumentation“ (Bereich DOK). */
class DocumentationTest extends DuskTestCase
{
    /** DOK-01 */
    public function test_search_jumps_to_the_section_and_about_shows_the_version(): void
    {
        $this->standardUser();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'benutzer@example.com')
                ->clickLink('Dokumentation')
                ->waitFor('[data-documentation]', 15)
                ->assertMissing('[data-doc-tab="admin"]')
                ->assertSee('Anwenderhandbuch');

            // Bilder kommen über die geschützte Route.
            $browser->waitUsing(10, 200, fn (): bool => (bool) $browser->script('const img = document.querySelector(".saft-doc img"); return !! img && img.complete && img.naturalWidth > 0;')[0]);

            // Suche über alle sichtbaren Handbücher; Treffer in „Häufige Fragen“ wechselt den Reiter.
            $browser->type('[data-doc-search]', 'keine E-Mail erhalten')
                ->waitFor('[data-doc-result]', 5)
                ->click('[data-doc-result]')
                ->pause(800)
                ->assertVisible('[data-doc="faq"]')
                ->assertMissing('[data-doc-results] [data-doc-result]');

            $browser->script('window.scrollTo(0, 0);');
            $browser->pause(300)->click('[data-doc-tab="about"]')
                ->waitFor('[data-saft-version]', 5)
                ->assertSeeIn('[data-saft-version]', (string) config('saft.version'))
                ->assertSee('PolyForm Noncommercial License 1.0.0');

            // Der Verweis im Kopf der LICENSE auf THIRD_PARTY_NOTICES.md klappt die Liste auf.
            $browser->assertScript('document.getElementById("third-party-components").open', false)
                ->click('[data-notices-link]')
                ->pause(600)
                ->assertScript('document.getElementById("third-party-components").open', true)
                ->assertVisible('[data-saft-notices] table');
        });
    }
}
