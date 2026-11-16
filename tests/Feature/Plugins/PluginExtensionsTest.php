<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Validation\FieldRuleCheck;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\PluginsPage;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use App\Plugins\Api\Saft;
use App\Plugins\PluginInspector;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Formelfunktionen und Prüfregeln aus Plugins: Verfügbarkeit, Konflikte, Fehler im Plugin, fehlende Plugins. */
class PluginExtensionsTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());
    }

    /**
     * @param  list<FormulaFunction>  $functions
     * @param  list<FieldRule>  $rules
     * @param  array<string, list<string>>  $required
     */
    private function plugin(string $id, array $functions = [], array $rules = [], array $required = []): SaftPlugin
    {
        $plugin = new class($id, $functions, $rules, $required) extends SaftPlugin
        {
            public function __construct(private string $key, private array $functions, private array $rules, private array $required) {}

            public function id(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Test '.$this->key;
            }

            public function formulaFunctions(): array
            {
                return $this->functions;
            }

            public function fieldRules(): array
            {
                return $this->rules;
            }

            public function requiredTables(): array
            {
                return $this->required;
            }
        };

        app(PluginRegistry::class)->add($plugin);

        return $plugin;
    }

    private static function double(string $name = 'verdoppeln'): FormulaFunction
    {
        return new FormulaFunction($name, 1, 1, static fn (array $args): string => bcmul($args[0], '2', 2), 'Verdoppelt');
    }

    private static function even(): FieldRule
    {
        return new FieldRule('gerade', 'Gerade Zahl', ['integer'], static fn (mixed $v): ?string => (int) $v % 2 === 0 ? null : 'Nicht gerade.');
    }

    public function test_functions_are_offered_checked_for_conflicts_and_resting_plugins_offer_none(): void
    {
        $this->plugin('test-a', [self::double(), self::double('round'), self::double('ungültig!')]);
        $this->plugin('test-b', [self::double()]);
        $this->plugin('test-ruht', [self::double('ruhend')], required: ['gibt_es_nicht' => []]);

        $functions = array_keys(app(PluginRegistry::class)->formulaFunctions());
        $this->assertContains('verdoppeln', $functions);
        $this->assertNotContains('ruhend', $functions, 'Ruhendes Plugin.');
        $this->assertNotContains('ungültig!', $functions);
        $this->assertSame([1, 1], FormulaAnalyzer::functions()['verdoppeln']);
        $this->assertSame([1, 2], FormulaAnalyzer::functions()['round'], 'Eingebaute Funktion bleibt.');

        $messages = static fn (string $id): array => array_column(array_filter(
            app(PluginInspector::class)->inspect(app(PluginRegistry::class)->get($id)),
            static fn (array $r): bool => $r['level'] === PluginInspector::ERROR,
        ), 'message');
        foreach ([
            'Formelfunktion „round“: eingebaute Funktion von SAFT, wird übergangen.',
            'Ungültiger Name der Formelfunktion „ungültig!“ (Kleinbuchstaben, Ziffern und _).',
            'Formelfunktion „verdoppeln“ gibt es auch im Plugin test-b; nur die erste (nach Kennung) gilt.',
        ] as $message) {
            $this->assertContains($message, $messages('test-a'));
        }

        Livewire::test(PluginsPage::class)->assertSee('Formelfunktionen: verdoppeln(), round(), ungültig!()');
    }

    public function test_computed_fields_use_plugin_functions_and_survive_errors_and_missing_plugins(): void
    {
        $this->plugin('test-formel', [
            self::double(),
            new FormulaFunction('kaputt', 1, 1, static fn (): string => throw new RuntimeException('Fehler im Plugin')),
        ]);
        $this->service()->createTable(['name' => 'werte', 'label' => 'Werte'], [
            ['name' => 'zahl', 'label' => 'Zahl', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
            ['name' => 'doppelt', 'label' => 'Doppelt', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'verdoppeln(zahl) + 1']],
            ['name' => 'fehler', 'label' => 'Fehler', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'kaputt(zahl)']],
        ]);

        $record = Saft::table('werte')->create(['zahl' => '2.50'])->fresh();
        $this->assertSame('6.00', (string) $record->getAttribute('doppelt'));
        $this->assertNull($record->getAttribute('fehler'), 'Fehler im Plugin: leer statt Abbruch.');

        try {
            $this->service()->addField(CustomTable::query()->where('name', 'werte')->sole(), ['name' => 'falsch', 'label' => 'Falsch', 'type' => 'computed', 'options' => ['result' => 'decimal', 'formula' => 'verdoppeln(zahl, 2)']]);
            $this->fail('Falsche Anzahl Argumente angenommen.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('verdoppeln', $e->getMessage());
        }

        // Plugin weg: die Formel ergibt leer, die Prüfung nennt das Feld.
        $this->app->forgetInstance(PluginRegistry::class);
        $this->app->singleton(PluginRegistry::class);
        $this->assertNull(Saft::table('werte')->create(['zahl' => '1'])->fresh()->getAttribute('doppelt'));
        $this->assertContains('Feld werte.doppelt: Formelfunktion „verdoppeln“ nicht verfügbar, das Ergebnis bleibt leer.', app(PluginInspector::class)->tableDesignerIssues());
    }

    public function test_rules_check_values_and_are_skipped_when_unavailable_unsuitable_or_failing(): void
    {
        $this->plugin('test-regel', rules: [
            self::even(),
            new FieldRule('kaputt', 'Kaputt', ['integer'], static fn (): ?string => throw new RuntimeException('Fehler im Plugin')),
            new FieldRule('text', 'Nur Text', ['string'], static fn (): string => 'Immer falsch.'),
        ]);
        $this->service()->createTable(['name' => 'zahlen', 'label' => 'Zahlen'], [
            ['name' => 'wert', 'label' => 'Wert', 'type' => 'integer', 'validation_rules' => ['checks' => ['test-regel.gerade', 'test-regel.kaputt', 'test-regel.text', 'fehlt.regel']]],
        ]);
        $field = CustomField::query()->where('name', 'wert')->sole();

        $this->assertSame(['test-regel.gerade', 'test-regel.kaputt', 'test-regel.text', 'fehlt.regel'], FieldRuleCheck::selected($field));
        $this->assertSame(['Nicht gerade.'], app(FieldRuleCheck::class)->messages($field, 3), 'Nur die passende Regel prüft; Fehler im Plugin, falscher Typ und fehlendes Plugin werden übersprungen.');
        $this->assertSame([], app(FieldRuleCheck::class)->messages($field, 4));
        $integerRules = array_keys(app(PluginRegistry::class)->fieldRules('integer'));
        $this->assertContains('test-regel.gerade', $integerRules);
        $this->assertNotContains('test-regel.text', $integerRules);

        $this->assertContains('Feld zahlen.wert: Prüfung „fehlt.regel“ nicht verfügbar, wird übersprungen.', app(PluginInspector::class)->tableDesignerIssues());
        $this->assertContains('Feld zahlen.wert: Prüfung „test-regel.text“ nicht verfügbar, wird übersprungen.', app(PluginInspector::class)->tableDesignerIssues());

        Saft::table('zahlen')->create(['wert' => 4]);
        $this->expectExceptionMessage('Nicht gerade.');
        Saft::table('zahlen')->create(['wert' => 5]);
    }

    public function test_invalid_rule_definitions_and_check_keys_are_rejected(): void
    {
        $this->plugin('test-falsch', rules: [
            new FieldRule('Gross', 'Ungültig', ['string'], static fn (): ?string => null),
            new FieldRule('datum', 'Datum', ['date'], static fn (): ?string => null),
        ]);

        $errors = array_column(array_filter(
            app(PluginInspector::class)->inspect(app(PluginRegistry::class)->get('test-falsch')),
            static fn (array $r): bool => $r['level'] === PluginInspector::ERROR,
        ), 'message');
        $this->assertContains('Ungültiger Name der Prüfregel „Gross“ (Kleinbuchstaben, Ziffern und Bindestriche).', $errors);
        $this->assertContains('Prüfregel „datum“: Feldtypen fehlen oder sind nicht erlaubt (erlaubt: string, text, integer, decimal).', $errors);
        $this->assertSame([], app(PluginRegistry::class)->fieldRules('date'), 'Ungültiger Name wird nie angeboten; Datum gibt es im Table-Designer nicht.');

        $this->expectException(BusinessRuleException::class);
        $this->service()->createTable(['name' => 'falsch', 'label' => 'Falsch'], [
            ['name' => 'wert', 'label' => 'Wert', 'type' => 'string', 'validation_rules' => ['checks' => ['kein punkt']]],
        ]);
    }
}
