<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\AppSettingsPage;
use App\Filament\Pages\DocumentationPage;
use App\Settings\AppSettings;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Logo (B-126): Safttropfen oder eigenes Logo aus den Einstellungen, in Kopfzeile, Anmeldung und Browser-Tab. */
class BrandingTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        Storage::fake('local');
    }

    /** Kleines echtes PNG. */
    private static function png(string $name = 'logo.png'): UploadedFile
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 2, 2, 8, 2, 0, 0, 0)).$chunk('IDAT', (string) gzcompress(str_repeat("\x00".str_repeat("\x10\x80\xf0", 2), 2))).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_default_logo_is_the_juice_drop_next_to_the_name(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('data-saft-logo', false)
            ->assertSee(asset('favicon.svg'), false)
            ->assertSee('SAFT XT');

        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_own_logo_is_uploaded_served_and_replaced(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['logo_path' => self::png()])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = (string) app(AppSettings::class)->refresh()->logo_path;
        $this->assertStringStartsWith(Branding::DIRECTORY.'/', $path);
        Storage::disk('local')->assertExists($path);

        // Ohne Anmeldung ausgeliefert, mit Version gegen veraltete Browser-Caches.
        auth()->logout();
        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/admin/login')->assertSee(route('branding.logo', ['v' => substr(md5($path), 0, 8)]), false);
        // Das Symbol im Tab bleibt der Safttropfen, bis es eingeschaltet wird.
        $this->assertSame(asset('favicon.svg'), Branding::faviconUrl());

        $this->actingAs($this->superAdmin());
        Livewire::test(AppSettingsPage::class)
            ->fillForm(['logo_path' => null, 'logo_as_favicon' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull(app(AppSettings::class)->refresh()->logo_path);
        $this->assertSame(asset('favicon.svg'), Branding::faviconUrl(), 'Ohne Logo bleibt der Safttropfen.');
    }

    public function test_logo_as_favicon_and_only_images_are_served(): void
    {
        Storage::disk('local')->put('branding/logo.png', (string) self::png()->getContent());
        $settings = app(AppSettings::class);
        $settings->logo_path = 'branding/logo.png';
        $settings->logo_as_favicon = true;
        $settings->save();

        $this->assertStringContainsString('/branding/logo?v=', Branding::faviconUrl());

        // Kein Bild (z. B. von Hand hinterlegt): nicht ausliefern.
        Storage::disk('local')->put('branding/logo.png', '<script>alert(1)</script>');
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_about_page_always_shows_the_saft_logo(): void
    {
        $settings = app(AppSettings::class);
        $settings->logo_path = 'branding/eigen.png';
        $settings->save();
        Storage::disk('local')->put('branding/eigen.png', (string) self::png()->getContent());

        $this->actingAs($this->superAdmin());
        Livewire::test(DocumentationPage::class)->assertSeeHtml('src="'.asset('favicon.svg').'" alt="" style="height: 2.5rem; width: 2.5rem" data-saft-about-logo');
    }
}
