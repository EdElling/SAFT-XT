<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\Letterhead;
use App\Documents\LetterheadResolver;
use App\Documents\PdfRenderer;
use App\Documents\QrBill\QrBillFactory;
use App\Documents\Templates\DocumentContext;
use App\Documents\Templates\RecordContext;
use App\Documents\Templates\TemplateChecker;
use App\Documents\Templates\TemplateException;
use App\Documents\Templates\TemplateRenderer;
use App\Filament\Pages\DocumentSettingsPage;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DocumentLetterhead;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use Database\Seeders\GitarrenshopDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\QrBill;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** QR-Rechnung (B-124, D4a): Daten nach Schweizer Standard, Referenzen, Fehler, Zahlteil im PDF, Briefpapier. */
class QrBillTest extends DynamicDataTestCase
{
    private CustomTable $receipts;

    private DocumentTemplate $template;

    private DynamicRecord $receipt;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->receipts = CustomTable::query()->where('name', 'gitarren_belege')->sole();
        $this->template = DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->sole();
        $this->receipt = DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'V-2025-001')->sole();
    }

    private function build(?array $settings = null, array $sender = []): QrBill
    {
        $letterhead = app(LetterheadResolver::class)->for($this->receipts->app_id);
        $letterhead = new Letterhead(...[...get_object_vars($letterhead), 'sender' => [...$letterhead->sender, ...$sender]]);
        $context = new DocumentContext(new RecordContext($this->receipt, $this->receipts, $this->superAdmin(), 'de'), $letterhead, 'de');

        return app(QrBillFactory::class)->build($settings ?? (array) $this->template->qr_bill, $context, $letterhead);
    }

    private static function payload(QrBill $bill): string
    {
        return $bill->getQrCode()->getText();
    }

    public function test_demo_invoice_builds_a_valid_bill_with_rf_reference_and_debtor(): void
    {
        $bill = $this->build();
        $lines = explode("\n", self::payload($bill));

        $this->assertSame(['SPC', '0200', '1', 'CH9300762011623852957', 'S', 'Gitarrenshop Muster AG'], array_slice($lines, 0, 6));
        $this->assertSame(number_format((float) $this->receipt->offen, 2, '.', ''), $lines[18]);
        $this->assertSame(['S', 'Lena Meier', 'Bahnhofstrasse', '12', '8001', 'Zürich', 'CH'], array_slice($lines, 20, 7));
        $this->assertSame(PaymentReference::TYPE_SCOR, $bill->getPaymentReference()->getType());
        $this->assertStringStartsWith('RF', (string) $bill->getPaymentReference()->getReference());
        $this->assertStringEndsWith('V2025001', (string) $bill->getPaymentReference()->getReference());
        $this->assertContains('Rechnung V-2025-001', $lines);
    }

    public function test_creditor_address_falls_back_to_the_letterhead_address(): void
    {
        $bill = $this->build(null, ['strasse' => null, 'plz' => null, 'ort' => null, 'land' => null, 'adresse' => "Feldhofweg 6\n8610 Uster"]);

        $this->assertSame(['S', 'Gitarrenshop Muster AG', 'Feldhofweg', '6', '8610', 'Uster', 'CH'], array_slice(explode("\n", self::payload($bill)), 4, 7));
        $this->assertSame(['street' => 'Seestrasse 1', 'postal_code' => '8000', 'city' => 'Zürich', 'country' => 'CH'], QrBillFactory::parseAddress('Seestrasse 1, CH-8000 Zürich'));
        $this->assertNull(QrBillFactory::parseAddress("Postfach\nZürich"), 'Ohne „PLZ Ort“ keine Adresse.');
    }

    public function test_house_number_is_split_off_or_taken_from_its_own_field(): void
    {
        foreach ([
            'Bahnhofstrasse 12a' => ['Bahnhofstrasse', '12a'],
            'Hauptstrasse 3-5' => ['Hauptstrasse', '3-5'],
            'Via Nassa 5' => ['Via Nassa', '5'],
            '12, rue du Lac' => ['rue du Lac', '12'],
            'Strasse des 17. Juni 4' => ['Strasse des 17. Juni', '4'],
            'Postfach 123' => ['Postfach 123', null],
            'Seestrasse' => ['Seestrasse', null],
        ] as $street => $expected) {
            $this->assertSame($expected, QrBillFactory::splitStreet($street), $street);
        }

        $bill = $this->build(
            [...(array) $this->template->qr_bill, 'debtor_street' => 'Im Grund', 'debtor_building_number' => '7 B'],
            ['strasse' => 'Seestrasse', 'hausnummer' => '1a'],
        );
        $lines = explode("\n", self::payload($bill));

        $this->assertSame(['Seestrasse', '1a'], array_slice($lines, 6, 2), 'Hausnummer des Briefpapiers.');
        $this->assertSame(['Im Grund', '7 B'], array_slice($lines, 22, 2), 'Hausnummer aus dem eigenen Feld der Vorlage.');
    }

    public function test_eur_uses_the_iban_never_the_qr_iban(): void
    {
        $settings = [...(array) $this->template->qr_bill, 'currency' => 'EUR'];
        $bill = $this->build($settings, ['qr_iban' => 'CH44 3199 9123 0008 8901 2']);

        $this->assertSame(PaymentReference::TYPE_SCOR, $bill->getPaymentReference()->getType());
        $this->assertStringContainsString("CH9300762011623852957\n", self::payload($bill));

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('nur für CHF');
        $this->build($settings, ['iban' => null, 'qr_iban' => 'CH44 3199 9123 0008 8901 2']);
    }

    public function test_qr_iban_gives_a_qr_reference_from_the_digits(): void
    {
        $bill = $this->build(null, ['qr_iban' => 'CH44 3199 9123 0008 8901 2']);

        $this->assertSame(PaymentReference::TYPE_QR, $bill->getPaymentReference()->getType());
        $this->assertSame(27, strlen((string) $bill->getPaymentReference()->getReference()));
        $this->assertStringContainsString('CH4431999123000889012', self::payload($bill));

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('höchstens 26 Ziffern');
        $this->build([...(array) $this->template->qr_bill, 'reference' => 'ohne Ziffern'], ['qr_iban' => 'CH44 3199 9123 0008 8901 2']);
    }

    public function test_missing_or_invalid_data_is_reported_readably(): void
    {
        try {
            $this->build(null, ['plz' => null, 'ort' => null]);
            $this->fail('Briefpapier unvollständig.');
        } catch (TemplateException $exception) {
            $this->assertStringContainsString('fehlen im Briefpapier: PLZ, Ort', $exception->getMessage());
        }

        try {
            $this->build(null, ['iban' => 'DE89 3704 0044 0532 0130 00']);
            $this->fail('Nur Schweizer und Liechtensteiner IBAN.');
        } catch (TemplateException $exception) {
            $this->assertStringContainsString('Schweizer oder Liechtensteiner IBAN', $exception->getMessage());
        }

        try {
            $this->build(null, ['iban' => 'CH89 0076 1016 0120 0953 1']);
            $this->fail('Prüfziffer falsch.');
        } catch (TemplateException $exception) {
            $this->assertStringContainsString('„CH8900761016012009531“ im Briefpapier ist ungültig', $exception->getMessage());
        }

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('keine Zahl');
        $this->build([...(array) $this->template->qr_bill, 'amount' => '{{belegnummer}}']);
    }

    public function test_payment_part_at_the_bottom_or_on_its_own_page(): void
    {
        $user = $this->superAdmin();
        $pages = static function (string $pdf): int {
            $file = tempnam(sys_get_temp_dir(), 'pdf');
            file_put_contents($file, $pdf);

            try {
                return PdfRenderer::pageCount($file);
            } finally {
                unlink($file);
            }
        };

        // Kurzer Inhalt: Zahlteil passt darunter. Die Demo-Rechnung selbst reicht bis in den Bereich des Zahlteils.
        $this->template->body = '<h1>Rechnung {{belegnummer}}</h1><p>Kurz.</p>';
        $bottom = app(TemplateRenderer::class)->render($this->template, $this->receipt, $user);
        $this->template->qr_bill = [...(array) $this->template->qr_bill, 'position' => QrBillFactory::PAGE];
        $own = app(TemplateRenderer::class)->render($this->template, $this->receipt, $user);
        $this->template->qr_bill = [...(array) $this->template->qr_bill, 'enabled' => false];
        $without = app(TemplateRenderer::class)->render($this->template, $this->receipt, $user);

        $this->assertSame($pages($without->pdf), $pages($bottom->pdf), 'Kurze Rechnung: Zahlteil unten auf der letzten Seite.');
        $this->assertSame($pages($without->pdf) + 1, $pages($own->pdf), 'Eigene Seite.');
        $this->assertStringContainsString('liberationsans', strtolower($own->pdf), 'Schrift des Standards eingebettet.');
    }

    public function test_placeholders_of_the_qr_bill_are_checked(): void
    {
        $errors = app(TemplateChecker::class)->check($this->receipts, '<p>x</p>', null, null, [], [...(array) $this->template->qr_bill, 'amount' => '{{gibtsnicht}}'])['errors'];

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('QR-Rechnung, Betrag', $errors[0]);
        $this->assertSame([], app(TemplateChecker::class)->check($this->receipts, '<p>x</p>', null, null, [], [...(array) $this->template->qr_bill, 'enabled' => false, 'amount' => '{{gibtsnicht}}'])['errors'], 'Ausgeschaltet: nicht geprüft.');
    }

    public function test_letterhead_stores_the_creditor_address_in_parts(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DocumentSettingsPage::class)
            ->fillForm(['sender' => ['firma' => 'Muster AG', 'strasse' => 'Seestrasse 1', 'plz' => '6300', 'ort' => 'Zug', 'land' => 'CH', 'qr_iban' => 'CH44 3199 9123 0008 8901 2']])
            ->call('save')
            ->assertHasNoErrors();

        // MySQL ordnet JSON-Schlüssel um: nur Inhalt vergleichen.
        $this->assertEquals(['strasse' => 'Seestrasse 1', 'plz' => '6300', 'ort' => 'Zug', 'qr_iban' => 'CH44 3199 9123 0008 8901 2'], array_intersect_key(
            (array) DocumentLetterhead::query()->whereNull('app_id')->sole()->sender, array_flip(['strasse', 'plz', 'ort', 'qr_iban']),
        ));
    }

    public function test_invalid_letterhead_data_is_a_notification_in_the_dialog(): void
    {
        $this->actingAs($this->superAdmin());
        $letterhead = DocumentLetterhead::query()->where('app_id', $this->receipts->app_id)->sole();
        $letterhead->update(['sender' => [...(array) $letterhead->sender, 'iban' => 'CH89 0076 1016 0120 0953 1']]);

        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->mountAction(TestAction::make('documentRow')->table($this->receipt))
            ->callMountedAction()
            ->assertNotified('Das Dokument konnte nicht erstellt werden');
    }

    public function test_letterhead_rejects_invalid_iban_and_qr_iban(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DocumentSettingsPage::class)
            ->fillForm(['sender' => ['firma' => 'Muster AG', 'iban' => 'CH89 0076 1016 0120 0953 1', 'qr_iban' => 'CH93 0076 2011 6238 5295 7']])
            ->call('save')
            ->assertHasFormErrors(['sender.iban', 'sender.qr_iban']);
    }
}
