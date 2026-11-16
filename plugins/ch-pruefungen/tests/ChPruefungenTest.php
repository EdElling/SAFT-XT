<?php

declare(strict_types=1);

namespace Saft\ChPruefungen\Tests;

use App\Plugins\Api\Saft;
use App\Plugins\Testing\PluginTestCase;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Saft\ChPruefungen\SwissChecks;

/** Beispiel-Plugin „CH-Prüfungen“: Prüfziffern, Prüfregeln in Maske und Schnittstelle, Formelfunktion rappen(). */
class ChPruefungenTest extends PluginTestCase
{
    public function test_check_digits(): void
    {
        $this->assertTrue(SwissChecks::ahv('756.9217.0769.85'));
        $this->assertTrue(SwissChecks::ahv('7569217076985'));
        $this->assertFalse(SwissChecks::ahv('756.9217.0769.84'), 'Prüfziffer falsch.');
        $this->assertFalse(SwissChecks::ahv('757.9217.0769.85'), 'Beginnt nicht mit 756.');

        $this->assertTrue(SwissChecks::iban('CH93 0076 2011 6238 5295 7'));
        $this->assertTrue(SwissChecks::iban('DE89370400440532013000'));
        $this->assertFalse(SwissChecks::iban('CH94 0076 2011 6238 5295 7'), 'Prüfsumme falsch.');
        $this->assertFalse(SwissChecks::iban('CH93 0076 2011 6238 5295'), 'Zu kurz für die Schweiz.');

        $this->assertTrue(SwissChecks::uid('CHE-123.456.788'));
        $this->assertTrue(SwissChecks::uid('che123456788'));
        $this->assertFalse(SwissChecks::uid('CHE-123.456.789'));

        $this->assertTrue(SwissChecks::postalCode('8001'));
        $this->assertFalse(SwissChecks::postalCode('0800'));
        $this->assertFalse(SwissChecks::postalCode('80011'));

        foreach (['12.32' => '12.30', '12.33' => '12.35', '12.375' => '12.40', '12.425' => '12.45', '-0.03' => '-0.05', '7' => '7.00'] as $amount => $rounded) {
            $this->assertSame($rounded, SwissChecks::roundToFiveRappen((string) $amount), (string) $amount);
        }
    }

    private function people(): void
    {
        $this->createTable('personen', 'Personen', [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['length' => 100]],
            ['name' => 'ahv', 'label' => 'AHV-Nr.', 'type' => 'string', 'options' => ['length' => 20], 'validation_rules' => ['checks' => ['ch-pruefungen.ahv']]],
            ['name' => 'iban', 'label' => 'IBAN', 'type' => 'string', 'options' => ['length' => 40], 'validation_rules' => ['checks' => ['ch-pruefungen.iban']]],
            ['name' => 'plz', 'label' => 'PLZ', 'type' => 'integer', 'validation_rules' => ['checks' => ['ch-pruefungen.plz']]],
        ]);
    }

    public function test_rules_apply_through_the_interface(): void
    {
        $this->actingAsSuperAdmin();
        app()->setLocale('de');
        $this->people();

        try {
            Saft::table('personen')->create(['name' => 'Muster', 'ahv' => '756.9217.0769.84', 'iban' => 'CH93 0076 2011 6238 5295 7', 'plz' => 800]);
            $this->fail('Ungültige AHV-Nummer angenommen.');
        } catch (ValidationException $e) {
            $this->assertSame(['ahv', 'plz'], array_keys($e->errors()));
            $this->assertStringContainsString('Keine gültige AHV-Nummer', $e->errors()['ahv'][0]);
        }

        Saft::table('personen')->create(['name' => 'Muster', 'ahv' => '756.9217.0769.85', 'iban' => 'CH93 0076 2011 6238 5295 7', 'plz' => 8001]);
        Saft::table('personen')->create(['name' => 'Ohne Angaben']);
        $this->assertSame(2, Saft::table('personen')->query()->count(), 'Leere Werte werden nicht geprüft.');
    }

    public function test_rules_apply_in_the_data_view(): void
    {
        $this->actingAsSuperAdmin();
        app()->setLocale('de');
        $this->people();

        $this->dataView('personen')
            ->callAction(TestAction::make(CreateAction::class)->table(), ['name' => 'Muster', 'iban' => 'CH94 0076 2011 6238 5295 7'])
            ->assertHasActionErrors(['iban']);

        $this->assertSame(0, Saft::table('personen')->query()->count());
    }

    public function test_rappen_in_computed_fields(): void
    {
        $this->actingAsSuperAdmin();
        $prices = $this->createTable('preise', 'Preise', [
            ['name' => 'netto', 'label' => 'Netto', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
            ['name' => 'brutto', 'label' => 'Brutto', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'rappen(netto * 1.081)']],
        ]);

        $this->assertSame('10.80', (string) $prices->create(['netto' => '10.00'])->fresh()->getAttribute('brutto'), '10.81 → 10.80');
        $this->assertSame('13.50', (string) $prices->create(['netto' => '12.47'])->fresh()->getAttribute('brutto'), '13.48007 → 13.50');
        $this->assertNull($prices->create([])->fresh()->getAttribute('brutto'), 'Leer bleibt leer.');
    }
}
