<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Import\RecordImporter;
use App\DynamicData\Ui\DynamicValueFormatter;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Pages\MySettingsPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Support\AppLocale;
use Illuminate\Http\Request;
use Livewire\Livewire;

/** Zahlenformat: wie die Sprache oder Schweiz (App-Einstellung) – Anzeige, CSV-Export und Import. */
class NumberFormatTest extends DynamicDataTestCase
{
    private CustomTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->superAdmin());
        $this->table = $this->service()->createTable(['name' => 'preise', 'label' => 'Preise', 'import_enabled' => true], [
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);
    }

    /** Wahl in „Meine Einstellungen“: null = automatisch, „lang“ = wie die Sprache, „CH“ = Schweiz. */
    private function region(?string $choice): void
    {
        auth()->user()->update(['number_format' => $choice]);
    }

    /** @return array{0: string, 1: string} Betrag und Menge formatiert */
    private function shown(string $locale): array
    {
        app()->setLocale($locale);
        $record = DynamicRecord::forTableName('preise')->make(['betrag' => '1234567.50', 'menge' => 12345]);
        $fields = $this->table->fields()->get()->keyBy('name');

        // Ältere ICU-Versionen setzen für de_CH das typografische Apostroph (1’234.50), neuere das gerade (1'234.50).
        return array_map(self::apostrophe(...), [DynamicValueFormatter::display($fields['betrag'], $record), DynamicValueFormatter::display($fields['menge'], $record)]);
    }

    private static function apostrophe(?string $text): ?string
    {
        return $text === null ? null : str_replace('’', "'", $text);
    }

    public function test_numbers_follow_the_language_or_the_swiss_format(): void
    {
        $this->region(AppLocale::NUMBERS_BY_LANGUAGE);
        $this->assertSame(['1.234.567,50', '12.345'], $this->shown('de'));
        $this->assertSame(['1,234,567.50', '12,345'], $this->shown('en'));
        app()->setLocale('de');
        $this->assertSame(',', AppLocale::setting('csv_decimal'));

        $this->region('CH');
        $this->assertSame(['1\'234\'567.50', '12\'345'], $this->shown('de'));
        $this->assertSame(['1\'234\'567.50', '12\'345'], $this->shown('it'));
        $this->assertSame(['1\'234\'567.50', '12\'345'], $this->shown('en'));
        $this->assertSame(["1\u{202F}234\u{202F}567,50", "12\u{202F}345"], $this->shown('fr'), 'Französisch nach Schweizer Regel.');

        // CSV: Dezimalpunkt und Semikolon (wie Excel in der Schweiz).
        foreach (['de', 'fr', 'it', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(['.', ';'], [AppLocale::setting('csv_decimal'), AppLocale::setting('csv_delimiter')], $locale);
        }

        $this->region(AppLocale::NUMBERS_BY_LANGUAGE);
        app()->setLocale('de');
        $this->assertSame([',', ';'], [AppLocale::setting('csv_decimal'), AppLocale::setting('csv_delimiter')]);
    }

    /** Frischer Request mit dieser Browsersprache (Symfony merkt sich die Sprachen je Request). */
    private function browser(string $acceptLanguage): void
    {
        $this->app->instance('request', Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]));
    }

    public function test_automatic_follows_the_preferred_browser_language(): void
    {
        $this->region(null);
        $this->browser('de-CH,de;q=0.9,en;q=0.8');
        $this->assertSame('CH', AppLocale::region());
        $this->assertSame(['1\'234\'567.50', '12\'345'], $this->shown('de'));

        $this->browser('de-DE,de-CH;q=0.9');
        $this->assertNull(AppLocale::region(), 'Nur die bevorzugte Sprache zählt.');

        // Eine feste Wahl schlägt den Browser.
        $this->browser('de-CH');
        $this->region(AppLocale::NUMBERS_BY_LANGUAGE);
        $this->assertNull(AppLocale::region());
        $this->assertSame(['1.234.567,50', '12.345'], $this->shown('de'));
    }

    public function test_data_view_uses_the_chosen_number_format(): void
    {
        app()->setLocale('de');
        DynamicRecord::forTableName('preise')->create(['betrag' => '1234567.50', 'menge' => 12345]);

        $this->region('CH');
        $html = self::apostrophe(html_entity_decode(Livewire::test(DynamicDataPage::class, ['table' => 'preise'])->html(), ENT_QUOTES));
        $this->assertStringContainsString("1'234'567.50", $html);
        $this->assertStringContainsString("12'345", $html);

        $this->region(AppLocale::NUMBERS_BY_LANGUAGE);
        Livewire::test(DynamicDataPage::class, ['table' => 'preise'])->assertSee('1.234.567,50')->assertSee('12.345');
    }

    public function test_choice_is_saved_in_my_settings(): void
    {
        Livewire::test(MySettingsPage::class)
            ->assertFormSet(['number_format' => 'auto'])
            ->fillForm(['number_format' => 'CH'])
            ->call('save');
        $this->assertSame('CH', auth()->user()->fresh()->number_format);

        Livewire::test(MySettingsPage::class)->assertFormSet(['number_format' => 'CH'])->fillForm(['number_format' => 'auto'])->call('save');
        $this->assertNull(auth()->user()->fresh()->number_format);
    }

    public function test_import_reads_swiss_numbers(): void
    {
        app()->setLocale('de');
        $this->region('CH');
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, "Betrag;Menge\n1'234.50;2'000\n12.5;3\n");

        $this->assertSame(2, app(RecordImporter::class)->import($this->table, $path, 'preise.csv', auth()->user()));
        $this->assertSame(['1234.50', '12.50'], DynamicRecord::forTableName('preise')->newQuery()->orderBy('id')->pluck('betrag')->map(fn ($v): string => number_format((float) $v, 2, '.', ''))->all());
        $this->assertSame([2000, 3], DynamicRecord::forTableName('preise')->newQuery()->orderBy('id')->pluck('menge')->map(fn ($v): int => (int) $v)->all());
        unlink($path);
    }
}
