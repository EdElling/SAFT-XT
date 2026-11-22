<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\LetterheadInspector;
use App\Documents\LetterheadResolver;
use App\Documents\PdfFonts;
use App\Documents\PdfRenderer;
use App\Documents\SvgSanitizer;
use App\Filament\Pages\DocumentSettingsPage;
use App\Models\App;
use App\Models\DocumentLetterhead;
use App\Models\Role;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Briefpapier und PDF-Erzeugung (B-124, D1a): Erben je Wert, PDF/A, Hintergrund, Schutz vor Inhalten von aussen, Seite. */
class LetterheadTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private App $testApp;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->seedAdminStructure();
        $this->testApp = self::ensureTestApp();
    }

    /** Ein A4-PDF mit $pages Seiten, auf jeder Seite der Text „$label n“ (optional halbtransparent). */
    private static function pdf(string $label, int $pages = 1, bool $transparent = false): string
    {
        PdfFonts::boot();
        $pdf = new Tcpdf('mm');
        $font = $pdf->font->insert($pdf->pon, PdfFonts::DEFAULT, '', 12);

        for ($i = 1; $i <= $pages; $i++) {
            $pdf->addPage(['format' => 'A4']);
            $pdf->page->addContent(($transparent ? $pdf->graph->getAlpha(0.5) : '').$font['out'].$pdf->getTextCell($label.' '.$i, 20, 20));
        }

        return $pdf->getOutPDFString();
    }

    private static function pages(string $pdf): int
    {
        $file = storage_path('framework/testing/doc-'.uniqid().'.pdf');
        file_put_contents($file, $pdf);
        $pages = PdfRenderer::pageCount($file);
        @unlink($file);

        return $pages;
    }

    private function store(string $name, string $content): string
    {
        Storage::disk('local')->put(DocumentLetterhead::DIRECTORY.'/'.$name, $content);

        return DocumentLetterhead::DIRECTORY.'/'.$name;
    }

    public function test_app_values_override_the_installation_one_by_one(): void
    {
        DocumentLetterhead::installation()->update([
            'color_primary' => '#112233', 'color_text' => '#000000', 'address_position' => DocumentLetterhead::RIGHT,
            'margins' => ['first' => ['top' => 50], 'next' => ['top' => 20]],
            'sender' => ['firma' => 'Muster AG', 'iban' => 'CH00 1111'], 'pdfa' => true,
        ]);
        DocumentLetterhead::query()->create([
            'app_id' => $this->testApp->id, 'color_primary' => '#aa0000', 'margins' => ['first' => ['left' => 30]],
            'sender' => ['firma' => 'Muster AG, Musikschule'], 'pdfa' => false,
        ]);

        $own = app(LetterheadResolver::class)->for($this->testApp->id);
        $this->assertSame(['#aa0000', '#000000', '#6b7280', DocumentLetterhead::RIGHT, false], [$own->primary, $own->text, $own->secondary, $own->addressPosition, $own->pdfa]);
        $this->assertSame([50.0, 30.0, 20.0], [$own->margins['first']['top'], $own->margins['first']['left'], $own->margins['next']['top']]);
        $this->assertSame(['Muster AG, Musikschule', 'CH00 1111', null], [$own->sender['firma'], $own->sender['iban'], $own->sender['mwst']]);

        $installation = app(LetterheadResolver::class)->for(null);
        $this->assertSame(['#112233', 'Muster AG', true], [$installation->primary, $installation->sender['firma'], $installation->pdfa]);
    }

    public function test_renders_pdfa_with_letterhead_background_on_first_and_following_pages(): void
    {
        DocumentLetterhead::installation()->update(['first_page_path' => $this->store('brief.pdf', self::pdf('BRIEFPAPIER', 2))]);
        $letterhead = app(LetterheadResolver::class)->for(null);

        $pdf = app(PdfRenderer::class)->render('<h1>Rechnung</h1>'.str_repeat('<p>Zeile</p>', 120), $letterhead, ['title' => 'Rechnung']);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('pdfaid:part', $pdf);
        $this->assertGreaterThanOrEqual(2, self::pages($pdf));
    }

    public function test_short_document_keeps_one_page_with_address_field(): void
    {
        $pdf = app(PdfRenderer::class)->render('<p>Kurz</p>', app(LetterheadResolver::class)->for(null), ['address' => 'Frau<br>Erika Muster']);

        $this->assertSame(1, self::pages($pdf));
    }

    public function test_house_font_is_converted_and_embedded(): void
    {
        $ttf = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

        if (! is_file($ttf)) {
            $this->markTestSkipped('Keine TTF-Datei auf diesem System.');
        }

        Storage::disk('local')->put(DocumentLetterhead::FONT_DIRECTORY.'/haus.ttf', (string) file_get_contents($ttf));
        DocumentLetterhead::installation()->update(['font_regular_path' => DocumentLetterhead::FONT_DIRECTORY.'/haus.ttf', 'pdfa' => false]);
        $letterhead = app(LetterheadResolver::class)->for(null);

        $pdf = app(PdfRenderer::class)->render('<p>Hausschrift <b>fett</b> <i>kursiv</i></p>', $letterhead);

        $this->assertTrue(PdfFonts::isConvertible($ttf));
        $this->assertFalse(PdfFonts::isConvertible(__FILE__));
        $this->assertStringContainsString('DejaVuSans-Bold', $pdf);
        $this->assertStringNotContainsString('/BaseFont /DejaVuSans ', $pdf);
    }

    public function test_separate_following_pages_and_pdfa_off(): void
    {
        DocumentLetterhead::installation()->update([
            'first_page_path' => $this->store('erste.pdf', self::pdf('ERSTE')),
            'next_pages_path' => $this->store('folge.pdf', self::pdf('FOLGE')),
            'pdfa' => false,
        ]);

        $pdf = app(PdfRenderer::class)->render(str_repeat('<p>Zeile</p>', 120), app(LetterheadResolver::class)->for(null));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('pdfaid:part', $pdf);
    }

    public function test_nothing_is_loaded_from_outside(): void
    {
        $html = PdfRenderer::sanitize('<script>x()</script><link rel="stylesheet" href="http://evil/x.css"><img src="http://evil/a.png"><img src=\'/etc/passwd\'>'
            .'<img src="data:image/png;base64,AAAA"><div style="background: url(http://evil/b.png)">t</div><style>@import url("http://evil/c.css");</style><a href="https://example.org">Link</a>');

        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('passwd', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('data:image/png;base64,AAAA', $html);
        $this->assertStringContainsString('href="https://example.org"', $html);
    }

    public function test_inspector_detects_unreadable_files_and_transparency(): void
    {
        $plain = storage_path('framework/testing/plain.pdf');
        $glass = storage_path('framework/testing/glass.pdf');
        $broken = storage_path('framework/testing/broken.pdf');
        @mkdir(dirname($plain), 0777, true);
        file_put_contents($plain, self::pdf('A', 2));
        file_put_contents($glass, self::pdf('B', 1, transparent: true));
        file_put_contents($broken, 'kein pdf');

        $inspector = app(LetterheadInspector::class);
        $this->assertSame(['readable' => true, 'pages' => 2, 'a4' => true, 'transparency' => false], $inspector->inspect($plain));
        $this->assertTrue($inspector->inspect($glass)['transparency']);
        $this->assertFalse($inspector->inspect($broken)['readable']);

        array_map('unlink', [$plain, $glass, $broken]);
    }

    public function test_settings_page_saves_installation_and_app_deviations(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DocumentSettingsPage::class)
            ->assertSet('scope', DocumentSettingsPage::INSTALLATION)
            ->fillForm([
                'first_page_path' => UploadedFile::fake()->createWithContent('brief.pdf', self::pdf('BRIEF', 2)),
                'color_primary' => '#123456',
                'margins' => ['first' => ['top' => '55']],
                'sender' => ['firma' => 'Muster AG', 'iban' => 'CH00 1111'],
                'address_position' => DocumentLetterhead::RIGHT,
                'pdfa' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $installation = DocumentLetterhead::query()->whereNull('app_id')->sole();
        $this->assertSame(['#123456', 55.0, 'Muster AG', DocumentLetterhead::RIGHT], [$installation->color_primary, (float) $installation->margins['first']['top'], $installation->sender['firma'], $installation->address_position]);
        Storage::disk('local')->assertExists((string) $installation->first_page_path);

        Livewire::test(DocumentSettingsPage::class)
            ->set('scope', (string) $this->testApp->id)
            ->assertFormSet(['color_primary' => null, 'pdfa_app' => 'inherit'])
            ->fillForm(['sender' => ['firma' => 'Musikschule'], 'pdfa_app' => 'off'])
            ->call('save')
            ->assertHasNoFormErrors();

        $letterhead = app(LetterheadResolver::class)->for($this->testApp->id);
        $this->assertSame(['Musikschule', 'CH00 1111', '#123456', false], [$letterhead->sender['firma'], $letterhead->sender['iban'], $letterhead->primary, $letterhead->pdfa]);
        $this->assertNotNull($letterhead->firstPage);

        Livewire::test(DocumentSettingsPage::class, [])->set('scope', (string) $this->testApp->id)->callAction('reset');
        $this->assertFalse(DocumentLetterhead::query()->where('app_id', $this->testApp->id)->exists());
    }

    public function test_rejects_unreadable_pdf_and_non_ttf_fonts(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DocumentSettingsPage::class)
            ->fillForm([
                'first_page_path' => UploadedFile::fake()->createWithContent('kaputt.pdf', '%PDF-1.4 kaputt'),
                'font_regular_path' => UploadedFile::fake()->createWithContent('schrift.otf', 'OTTO'),
            ])
            ->call('save')
            ->assertHasFormErrors(['first_page_path', 'font_regular_path']);
    }

    public function test_sample_page_downloads_a_pdf(): void
    {
        $this->actingAs($this->superAdmin());

        $response = Livewire::test(DocumentSettingsPage::class)->callAction('sample');

        $response->assertFileDownloaded('Probeseite.pdf');
    }

    public function test_svg_logo_is_sanitized(): void
    {
        $svg = SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
            .'<script>alert(2)</script><foreignObject><div>x</div></foreignObject>'
            .'<image href="http://evil/a.png"/><image xlink:href="data:image/png;base64,AAAA"/><use href="#kreis"/>'
            .'<circle id="kreis" r="5" style="fill: url(http://evil/b)"/><rect width="5" height="5" fill="#123456"/></svg>');

        $this->assertNotNull($svg);
        $this->assertStringNotContainsString('evil', $svg);
        $this->assertStringNotContainsString('alert', $svg);
        $this->assertStringNotContainsString('foreignObject', $svg);
        $this->assertStringContainsString('data:image/png;base64,AAAA', $svg);
        $this->assertStringContainsString('href="#kreis"', $svg);
        $this->assertStringContainsString('fill="#123456"', $svg);

        $this->assertNull(SvgSanitizer::sanitize('<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'));
        $this->assertNull(SvgSanitizer::sanitize('<html><body>kein svg</body></html>'));
        $this->assertNull(SvgSanitizer::sanitize('kein xml'));
    }

    public function test_svg_logo_upload_is_stored_sanitized_and_rendered(): void
    {
        $this->actingAs($this->superAdmin());
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="60"><script>alert(1)</script><rect width="200" height="60" fill="#0f4c81"/></svg>';

        Livewire::test(DocumentSettingsPage::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('logo.svg', $svg)])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = (string) DocumentLetterhead::installation()->logo_path;
        $this->assertStringEndsWith('.svg', $path);
        $this->assertStringNotContainsString('script', (string) Storage::disk('local')->get($path));

        $letterhead = app(LetterheadResolver::class)->for(null);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', (string) PdfRenderer::dataUri((string) $letterhead->logo));
        $this->assertStringStartsWith('%PDF-', app(PdfRenderer::class)->render('<p>Text</p>', $letterhead));

        Livewire::test(DocumentSettingsPage::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('kaputt.svg', '<!DOCTYPE svg><svg/>')])
            ->call('save')
            ->assertHasFormErrors(['logo_path']);
    }

    public function test_large_png_logo_is_scaled_down(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('gd fehlt.');
        }

        $image = imagecreatetruecolor(1500, 500);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        $file = storage_path('framework/testing/logo-'.uniqid().'.png');
        @mkdir(dirname($file), 0777, true);
        imagepng($image, $file);

        $uri = (string) PdfRenderer::dataUri($file);
        $size = getimagesizefromstring((string) base64_decode(substr($uri, strlen('data:image/png;base64,'))));

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertSame([PdfRenderer::LOGO_WIDTH, 333], [$size[0] ?? null, $size[1] ?? null]);
        $this->assertFalse(PdfRenderer::logoNeedsGd($file));
        @unlink($file);
    }

    public function test_logo_height_is_inherited_and_width_capped(): void
    {
        DocumentLetterhead::installation()->update(['logo_height' => 20]);
        DocumentLetterhead::query()->create(['app_id' => $this->testApp->id]);
        $this->assertSame(20.0, app(LetterheadResolver::class)->for($this->testApp->id)->logoHeight);
        DocumentLetterhead::query()->where('app_id', $this->testApp->id)->update(['logo_height' => 12.5]);
        $this->assertSame(12.5, app(LetterheadResolver::class)->for($this->testApp->id)->logoHeight);

        $dir = storage_path('framework/testing');
        @mkdir($dir, 0777, true);
        file_put_contents($square = $dir.'/quadrat.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100"/></svg>');
        file_put_contents($wide = $dir.'/breit.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1000px" height="100px"><rect width="1000" height="100"/></svg>');

        $renderer = app(PdfRenderer::class);
        $this->assertSame('width="20.0mm" height="20.0mm"', $renderer->logoSize($square, 20));
        // 10 : 1 bei 20 mm Höhe wären 200 mm – begrenzt auf 80 mm Breite, damit 8 mm hoch.
        $this->assertSame('width="80.0mm" height="8.0mm"', $renderer->logoSize($wide, 20));

        array_map('unlink', [$square, $wide]);
    }

    public function test_head_sits_opposite_the_address_field_without_hint(): void
    {
        $this->actingAs($this->superAdmin());
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100"/></svg>';

        Livewire::test(DocumentSettingsPage::class)
            ->fillForm([
                'logo_path' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
                'logo_height' => '35',
                'address_position' => DocumentLetterhead::RIGHT,
                'sender' => ['firma' => 'Muster AG', 'adresse' => "Weg 1\n8000 Zürich"],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified(__('documents.settings.hints'));

        $renderer = app(PdfRenderer::class);
        $right = app(LetterheadResolver::class)->for(null);
        $this->assertSame(35.0, $right->logoHeight);
        $this->assertStringContainsString('text-align: left', $renderer->head($right));
        $this->assertStringStartsWith('%PDF-', $renderer->render('<p>Text</p>', $right, ['address' => 'Frau<br>Erika Muster']));

        DocumentLetterhead::installation()->update(['address_position' => DocumentLetterhead::LEFT]);
        $this->assertStringContainsString('text-align: right', $renderer->head(app(LetterheadResolver::class)->for(null)));
    }

    public function test_access_by_installation_right_or_app_right(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view']));
        $this->get(DocumentSettingsPage::getUrl())->assertForbidden();

        $role = Role::query()->create(['name' => 'schulleitung', 'guard_name' => 'web', 'app_id' => $this->testApp->id]);
        $role->givePermissionTo('documents.manage');
        $user = $this->userWithPermissions();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertSame([$this->testApp->id], array_keys(DocumentSettingsPage::scopes($user)));
        $this->get(DocumentSettingsPage::getUrl())->assertOk();
        Livewire::test(DocumentSettingsPage::class)
            ->assertSet('scope', (string) $this->testApp->id)
            ->set('scope', DocumentSettingsPage::INSTALLATION)
            ->assertForbidden();

        $this->actingAs($this->userWithPermissions(['settings.app.update']));
        $this->assertSame([DocumentSettingsPage::INSTALLATION], array_keys(DocumentSettingsPage::scopes(auth()->user())));
    }
}
