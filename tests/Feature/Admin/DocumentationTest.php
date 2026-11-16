<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Documentation\Documentation;
use App\Filament\Pages\DocumentationPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Online-Hilfe „Dokumentation“ aus den Handbüchern in docs/manual. */
class DocumentationTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
    }

    public function test_users_see_user_guide_and_faq_admins_also_the_admin_guide_never_sysadmin(): void
    {
        $this->actingAs($this->userWithPermissions());
        $page = Livewire::test(DocumentationPage::class)
            ->assertOk()
            ->assertSee('Anwenderhandbuch')
            ->assertSee('Häufige Fragen')
            ->assertSee('Über SAFT XT')
            ->assertSeeHtml('id="user-41-die-tabellenansicht"')
            ->assertDontSeeHtml('data-doc="admin"')
            ->assertDontSee('5.4 Table-Designer')
            // Systemadministration erscheint nie.
            ->assertDontSee('Befehlsreferenz')
            ->assertDontSee('php artisan db:backup');
        // Verweise ins Administratorhandbuch sind für Benutzer nur Text.
        $this->assertStringNotContainsString('href="#admin-', $page->html());

        $this->actingAs($this->userWithPermissions(['menus.view']));
        Livewire::test(DocumentationPage::class)
            ->assertSee('Administratorhandbuch')
            ->assertSeeHtml('id="admin-4-table-designer"')
            ->assertDontSee('Befehlsreferenz');
        $this->assertTrue(Documentation::isAdmin($this->superAdmin()));
    }

    public function test_about_shows_version_and_licence(): void
    {
        $this->actingAs($this->userWithPermissions());
        Livewire::test(DocumentationPage::class)
            ->assertSeeHtml('data-saft-version>'.config('saft.version').'<')
            ->assertSee('PolyForm Noncommercial License 1.0.0')
            ->assertSee('Laravel '.app()->version());
    }

    public function test_language_english_and_fallback_for_other_languages(): void
    {
        $this->actingAs($this->userWithPermissions());

        app()->setLocale('en');
        Livewire::test(DocumentationPage::class)->assertSee('User guide')->assertSeeHtml('id="user-41-the-table-view"')
            ->assertDontSee('available in German and English');

        // Französisch und Italienisch: Anwender-, FAQ- und Administratorhandbuch in der Sprache.
        app()->setLocale('fr');
        Livewire::test(DocumentationPage::class)->assertSeeHtml('id="user-41-la-vue-tableau"')
            ->assertSeeHtml('id="faq-questions-fréquentes"')
            ->assertDontSeeHtml('data-language-note');

        app()->setLocale('it');
        $this->actingAs($this->userWithPermissions(['menus.view']));
        Livewire::test(DocumentationPage::class)->assertSeeHtml('id="user-41-la-vista-tabella"')
            ->assertSeeHtml('id="admin-4-designer-di-tabelle"')
            ->assertSeeHtml('href="#admin-4-designer-di-tabelle"')
            ->assertDontSeeHtml('data-language-note');
    }

    public function test_images_only_from_the_manual_folder_and_only_when_signed_in(): void
    {
        $url = route('documentation.image', ['lang' => 'de', 'file' => '02-dashboard.png']);
        $this->get($url)->assertRedirect();

        $this->actingAs($this->userWithPermissions());
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/documentation/images/de/fehlt.png')->assertNotFound();
        $this->get('/documentation/images/de/..%2F..%2F..%2F.env')->assertNotFound();
        $this->get('/documentation/images/xx/02-dashboard.png')->assertNotFound();
    }
}
