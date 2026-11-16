<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Console\Commands\ThirdPartyNoticesCommand;
use App\Filament\Pages\DocumentationPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** THIRD_PARTY_NOTICES.md ist vollständig und erscheint unter „Über SAFT XT“. */
class ThirdPartyNoticesTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    public function test_every_production_package_is_listed(): void
    {
        $notices = (string) file_get_contents(base_path('THIRD_PARTY_NOTICES.md'));
        $installed = json_decode((string) file_get_contents(base_path('vendor/composer/installed.json')), true);
        $dev = (array) $installed['dev-package-names'];

        $missing = collect($installed['packages'])->pluck('name')->reject(fn (string $name): bool => in_array($name, $dev, true))
            ->reject(fn (string $name): bool => str_contains($notices, "| {$name} |"))->values()->all();

        $this->assertSame([], $missing, 'Neue Pakete: php artisan saft:third-party-notices ausführen und die Datei committen.');
        $this->assertStringContainsString('| mermaid |', $notices);
        $this->assertStringContainsString('### Eclipse Public License 2.0 – elkjs', $notices);
        $this->assertStringContainsString('https://github.com/kieler/elkjs', $notices);
        $this->assertStringContainsString('## License texts', $notices);
    }

    /** Mehrere Lizenzdateien: entweder gelten beide (ADDITIONAL_LICENSES) oder sie stehen zur Wahl – nie unentschieden. */
    public function test_packages_with_several_license_files_are_decided(): void
    {
        $installed = json_decode((string) file_get_contents(base_path('vendor/composer/installed.json')), true);
        $dev = (array) $installed['dev-package-names'];
        $decided = [...array_keys(ThirdPartyNoticesCommand::ADDITIONAL_LICENSES), ...ThirdPartyNoticesCommand::ALTERNATIVE_LICENSE_FILES];
        $undecided = [];

        foreach ($installed['packages'] as $package) {
            if (in_array($package['name'], $dev, true) || in_array($package['name'], $decided, true)) {
                continue;
            }

            $files = array_filter(
                (array) glob(base_path('vendor/composer/'.$package['install-path']).'/{LICEN[CS]E,licen[cs]e,License,Licence,COPYING}*', GLOB_BRACE),
                fn ($file): bool => is_file((string) $file) && preg_match('/\.(m?js|php|json)$/', (string) $file) !== 1,
            );

            if (count($files) > 1) {
                $undecided[] = $package['name'];
            }
        }

        $this->assertSame([], $undecided, 'Mehrere Lizenzdateien: in ThirdPartyNoticesCommand::ADDITIONAL_LICENSES (gelten zusammen) oder ALTERNATIVE_LICENSE_FILES (zur Wahl) eintragen.');

        // OpenSpout: MIT und der Apache-2.0-Anteil aus box/spout, beide Texte.
        $notices = (string) file_get_contents(base_path('THIRD_PARTY_NOTICES.md'));
        $this->assertMatchesRegularExpression('/\| openspout\/openspout \| [^|]+ \| MIT AND Apache-2\.0 \| [^|]*Box, Inc\./', $notices);
        $this->assertStringContainsString('### Apache-2.0 – openspout/openspout (code from box/spout)', $notices);
    }

    public function test_about_shows_the_third_party_components(): void
    {
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->actingAs($this->userWithPermissions());

        Livewire::test(DocumentationPage::class)
            ->assertSee('Komponenten von Drittanbietern')
            ->assertSeeHtml('data-saft-notices')
            ->assertSee('laravel/framework')
            ->assertSee('Eclipse Public License 2.0')
            // Der Kopf der LICENSE nennt die Datei: der Name führt zur Liste.
            ->assertSeeHtml('<a href="#third-party-components" data-notices-link>THIRD_PARTY_NOTICES.md</a>')
            ->assertSeeHtml('id="third-party-components"');
    }
}
