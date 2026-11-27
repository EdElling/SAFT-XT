<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Sammeländerung (Bereich SAE, B-133): Dialog „Ändern …“ mit allen Arten, Vorschau, Prüfen, Löschen und Wiederherstellen. */
class BulkEditTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string MODAL = 'mountedActionSchema0.';

    private CustomTable $courses;

    private CustomTable $enrolments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $schema = app(DynamicSchemaService::class);
        $this->courses = $schema->createTable(['name' => 'kurse', 'label' => 'Kurse'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
        ]);
        $this->enrolments = $schema->createTable(['name' => 'anmeldungen', 'label' => 'Anmeldungen'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                ['value' => 'warteliste', 'label' => ['de' => 'Warteliste']], ['value' => 'aktiv', 'label' => ['de' => 'Aktiv']],
            ]]],
            ['name' => 'von', 'label' => 'Von Woche', 'type' => 'integer'],
            ['name' => 'bis', 'label' => 'Bis Woche', 'type' => 'integer'],
            ['name' => 'kurs_id', 'label' => 'Kurs', 'type' => 'relation', 'options' => ['table' => 'kurse', 'display_field' => 'titel']],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'faellig', 'label' => 'Fällig am', 'type' => 'date'],
            ['name' => 'adresse', 'label' => 'Adresse', 'type' => 'string'],
        ]);
        $this->enrolments = $schema->updateTable($this->enrolments->fresh('fields'), ['validation_rules' => [
            ['left' => 'bis', 'operator' => '>=', 'right_kind' => TableRuleNormalizer::KIND_FIELD, 'right_field' => 'von', 'when_filled' => true],
        ]])->fresh('fields');

        app(DataMenu::class)->ensure($this->courses);
        app(DataMenu::class)->ensure($this->enrolments);
    }

    /** @return list<DynamicRecord> */
    private function enrol(int $count, ?int $course = null): array
    {
        return array_map(fn (int $i): DynamicRecord => DynamicRecord::forTable($this->enrolments)->create([
            'name' => "Person {$i}", 'status' => 'warteliste', 'von' => 1, 'bis' => 10 * $i, 'kurs_id' => $course,
        ]), range(1, $count));
    }

    /** Häkchen einer Zeile; mit $table nur in der Tabelle mit dieser Überschrift (Detailtabelle). */
    private function select(Browser $browser, DynamicRecord $record, ?string $table = null): Browser
    {
        $scope = $table === null ? '' : "//div[contains(@class, 'fi-ta')][.//*[contains(@class, 'fi-ta-header-heading')][contains(., '{$table}')]]";

        return $browser->clickAtXPath("({$scope}//input[contains(@class, 'fi-ta-record-checkbox')][@value='{$record->getKey()}'])[1]")->pause(300);
    }

    /** Den sichtbaren Knopf mit diesem Text (Datenansicht und Detailtabelle haben je eine Werkzeugleiste). */
    private function clickVisibleText(Browser $browser, string $text): Browser
    {
        foreach ($browser->driver->findElements(WebDriverBy::xpath("//button[contains(normalize-space(.), '{$text}')]")) as $button) {
            if ($button->isDisplayed()) {
                $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$button]);
                $button->click();

                return $browser->pause(300);
            }
        }

        $this->fail("Kein sichtbarer Knopf „{$text}“.");
    }

    private function openDialog(Browser $browser): Browser
    {
        return $this->clickVisibleText($browser, 'Ändern …')
            ->waitFor('[data-bulk-preview]', 15)
            ->pause(300);
    }

    private function chooseStatus(Browser $browser, string $value): Browser
    {
        return $browser->select('select[id="'.self::MODAL.'changes.status.status"]', $value)->pause(800);
    }

    private function tick(Browser $browser, string $field): Browser
    {
        return $browser->click('input[id="'.self::MODAL."changes.{$field}.on\"]")->pause(800);
    }

    /** Art der Änderung wählen (Knopfgruppe „setzen | leeren | rechnen | …“). */
    private function chooseOp(Browser $browser, string $field, string $op): Browser
    {
        $id = self::MODAL."changes.{$field}.op-{$op}";

        return $browser->clickAtXPath("(//button[@id='{$id}'] | //label[@for='{$id}'])[1]")->pause(800);
    }

    private function fill(Browser $browser, string $id, string $value): Browser
    {
        $selector = 'input[id="'.self::MODAL.$id.'"]';

        return $browser->clear($selector)->type($selector, $value)->keys($selector, '{tab}')->pause(1000);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<DynamicRecord>
     */
    private function enrolWith(array $rows): array
    {
        return array_map(fn (array $row, int $i): DynamicRecord => DynamicRecord::forTable($this->enrolments)->create([
            'name' => 'Person '.($i + 1), 'status' => 'warteliste', ...$row,
        ]), $rows, array_keys($rows));
    }

    /** @return array<int|string, mixed> */
    private function column(string $field): array
    {
        DynamicRecord::forget('anmeldungen');

        return DynamicRecord::forTableName('anmeldungen')->newQuery()->orderBy('id')->pluck($field)->all();
    }

    /** Alle Datensätze der Seite auswählen und den Dialog öffnen. */
    private function selectAllAndOpen(Browser $browser, string $last): Browser
    {
        $this->login($browser, self::ADMIN)->visit('/admin/data/anmeldungen')->waitForText($last, 15);
        $browser->clickAtXPath("(//input[contains(@class, 'fi-ta-page-checkbox')])[1]")->pause(500);

        return $this->openDialog($browser);
    }

    /** @return array<int|string, mixed> */
    private function statuses(): array
    {
        DynamicRecord::forget('anmeldungen');

        return DynamicRecord::forTableName('anmeldungen')->newQuery()->orderBy('id')->pluck('status', 'id')->all();
    }

    /** SAE-01 */
    public function test_changes_the_selected_records_after_a_preview(): void
    {
        [$first, $second, $third] = $this->enrol(3);

        $this->browse(function (Browser $browser) use ($first, $third): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/anmeldungen')->waitForText('Person 3', 15);
            $this->select($browser, $first);
            $this->select($browser, $third);
            $this->openDialog($browser)->assertSee('2 Datensätze ausgewählt');

            $this->tick($browser, 'status');
            $this->chooseStatus($browser, 'aktiv');
            $browser->waitForTextIn('[data-bulk-examples]', 'Aktiv', 15)
                ->assertSeeIn('[data-bulk-examples]', 'Warteliste')
                ->assertSee('2 Datensätze werden geändert');

            $this->clickVisibleButton($browser, '2 Datensätze ändern')
                ->waitForText('2 Datensätze geändert.', 20)
                ->waitUntilMissingText('Ausgewählte Datensätze ändern', 15);
        });

        $this->assertSame(['aktiv', 'warteliste', 'aktiv'], array_values($this->statuses()));
    }

    /** SAE-02 */
    public function test_check_only_changes_nothing_and_a_failing_check_names_the_record(): void
    {
        $records = $this->enrol(2);

        $this->browse(function (Browser $browser) use ($records): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/anmeldungen')->waitForText('Person 2', 15);
            $this->select($browser, $records[0]);
            $this->select($browser, $records[1]);
            $this->openDialog($browser);

            // „Von Woche“ 15: bei Person 1 (bis 10) verletzt das die Regel „Bis ≥ Von“, bei Person 2 (bis 20) nicht.
            $this->tick($browser, 'von');
            $browser->type('input[id="'.self::MODAL.'changes.von.von"]', '15')->keys('input[id="'.self::MODAL.'changes.von.von"]', '{tab}')->pause(800);
            $this->clickVisibleButton($browser, 'Nur prüfen')
                ->waitForText('Nichts geändert: 1 Datensatz besteht die Prüfungen nicht.', 20)
                ->assertSee('Person 1')
                ->assertSee('Ausgewählte Datensätze ändern');

            $browser->clear('input[id="'.self::MODAL.'changes.von.von"]')->type('input[id="'.self::MODAL.'changes.von.von"]', '5')->keys('input[id="'.self::MODAL.'changes.von.von"]', '{tab}')->pause(800);
            $this->clickVisibleButton($browser, 'Nur prüfen')
                ->waitForText('Geprüft: alle 2 Datensätze bestehen die Prüfungen und würden geändert.', 20)
                ->assertSee('Ausgewählte Datensätze ändern');
        });

        DynamicRecord::forget('anmeldungen');
        $this->assertSame([1, 1], array_map('intval', DynamicRecord::forTableName('anmeldungen')->newQuery()->orderBy('id')->pluck('von')->all()), 'Nichts geändert.');
    }

    /** SAE-03 */
    public function test_detail_table_offers_the_bulk_edit_without_the_master_relation(): void
    {
        $course = DynamicRecord::forTable($this->courses)->create(['titel' => 'Klavier']);
        app(DynamicSchemaService::class)->updateTable($this->courses->fresh('fields'), ['detail_field_ids' => [$this->enrolments->fields->firstWhere('name', 'kurs_id')->id]]);
        $records = $this->enrol(2, (int) $course->getKey());

        $this->browse(function (Browser $browser) use ($records): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kurse')->waitForText('Klavier', 15)
                ->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'Klavier')]//td[2]")
                ->waitForText('Person 2', 15);
            $this->select($browser, $records[0], 'Anmeldungen');
            $this->select($browser, $records[1], 'Anmeldungen');
            $this->openDialog($browser);

            $this->assertCount(0, $browser->driver->findElements(WebDriverBy::cssSelector('input[id="'.self::MODAL.'changes.kurs_id.on"]')), 'Verknüpfung zur Haupttabelle fest.');
            $this->tick($browser, 'status');
            $this->chooseStatus($browser, 'aktiv');
            $browser->waitForTextIn('[data-bulk-examples]', 'Aktiv', 15);
            $this->clickVisibleButton($browser, '2 Datensätze ändern')->waitForText('2 Datensätze geändert.', 20);
        });

        $this->assertSame(['aktiv', 'aktiv'], array_values($this->statuses()));
    }

    /** SAE-04 */
    public function test_bulk_delete_skips_blocked_records_and_restore_brings_them_back(): void
    {
        $busy = DynamicRecord::forTable($this->courses)->create(['titel' => 'Belegt']);
        $empty = DynamicRecord::forTable($this->courses)->create(['titel' => 'Leer']);
        $this->enrol(1, (int) $busy->getKey());

        $this->browse(function (Browser $browser) use ($busy, $empty): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kurse')->waitForText('Leer', 15);
            $this->select($browser, $busy);
            $this->select($browser, $empty);
            $this->clickVisibleText($browser, 'Ausgewählte löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Ausgewählte löschen')
                ->waitForText('1 Datensatz nicht gelöscht', 20)
                ->waitUntilMissingText('Leer', 15)
                ->assertSee('Belegt');

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->waitFor('select[id="tableFiltersForm.trashed.value"]', 15)->select('select[id="tableFiltersForm.trashed.value"]', '0');
            })->waitForText('Leer', 15);
            $this->select($browser, $empty);
            $this->clickVisibleText($browser, 'Ausgewählte wiederherstellen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Wiederherstellen')->waitUntilMissingText('Leer', 15);
        });

        DynamicRecord::forget('kurse');
        $this->assertSame(['Belegt', 'Leer'], DynamicRecord::forTableName('kurse')->newQuery()->orderBy('titel')->pluck('titel')->all());
    }

    /** SAE-08 */
    public function test_calc_raises_a_tariff_by_percent_rounded_to_a_multiple(): void
    {
        $this->enrolWith([['tarif' => '12.34'], ['tarif' => '100'], ['tarif' => null]]);

        $this->browse(function (Browser $browser): void {
            $this->selectAllAndOpen($browser, 'Person 3');
            $this->tick($browser, 'tarif');
            $this->chooseOp($browser, 'tarif', 'calc');
            $browser->select('select[id="'.self::MODAL.'changes.tarif.operator"]', 'percent')->pause(800);
            $this->fill($browser, 'changes.tarif.operand', '5');
            $browser->select('select[id="'.self::MODAL.'changes.tarif.round_mode"]', 'multiple')->pause(800);
            $this->fill($browser, 'changes.tarif.round_value', '0,05');

            $browser->waitFor('[data-bulk-examples]', 15)
                ->assertSee('2 Datensätze werden geändert')
                ->assertSee('1 ohne Wert bzw. ohne Treffer bleibt unverändert.');
            $this->clickVisibleButton($browser, '3 Datensätze ändern')
                ->waitForText('2 Datensätze geändert. 1 ohne Wert bzw. ohne Treffer (unverändert).', 20);
        });

        $this->assertSame(['12.95', '105.00', null], $this->column('tarif'));
    }

    /** SAE-09 */
    public function test_calc_moves_a_due_date_by_thirty_days(): void
    {
        $this->enrolWith([['faellig' => '2026-11-30'], ['faellig' => '2027-01-31']]);

        $this->browse(function (Browser $browser): void {
            $this->selectAllAndOpen($browser, 'Person 2');
            $this->tick($browser, 'faellig');
            $this->chooseOp($browser, 'faellig', 'calc');
            $browser->select('select[id="'.self::MODAL.'changes.faellig.operator"]', 'add_days')->pause(800);
            $this->fill($browser, 'changes.faellig.operand', '30');

            $browser->waitFor('[data-bulk-examples]', 15)->assertSee('2 Datensätze werden geändert');
            $this->clickVisibleButton($browser, '2 Datensätze ändern')->waitForText('2 Datensätze geändert.', 20);
        });

        $this->assertSame(['2026-12-30', '2027-03-02'], array_map(static fn (mixed $d): string => substr((string) $d, 0, 10), $this->column('faellig')));
    }

    /** SAE-10 */
    public function test_replace_with_keep_case_changes_only_records_with_a_match(): void
    {
        $this->enrolWith([['adresse' => 'Bahnhofstr. 3'], ['adresse' => 'Str. des 1. Mai'], ['adresse' => 'Seeweg 7']]);

        $this->browse(function (Browser $browser): void {
            $this->selectAllAndOpen($browser, 'Person 3');
            $this->tick($browser, 'adresse');
            $this->chooseOp($browser, 'adresse', 'replace');
            $this->fill($browser, 'changes.adresse.search', 'str.');
            $this->fill($browser, 'changes.adresse.replace', 'strasse');
            $browser->click('input[id="'.self::MODAL.'changes.adresse.keep"]')->pause(1000);

            $browser->waitForTextIn('[data-bulk-examples]', 'Strasse des 1. Mai', 15)
                ->assertSeeIn('[data-bulk-examples]', 'Bahnhofstrasse 3')
                ->assertDontSeeIn('[data-bulk-examples]', 'Seeweg')
                ->assertSee('1 ohne Wert bzw. ohne Treffer bleibt unverändert.');
            $this->clickVisibleButton($browser, '3 Datensätze ändern')->waitForText('2 Datensätze geändert.', 20);
        });

        $this->assertSame(['Bahnhofstrasse 3', 'Strasse des 1. Mai', 'Seeweg 7'], $this->column('adresse'));
    }

    /** SAE-11 */
    public function test_formula_shows_a_division_by_zero_and_the_check_fails_until_it_is_fixed(): void
    {
        $this->enrolWith([['tarif' => '10', 'von' => 2], ['tarif' => '20', 'von' => 0]]);

        $this->browse(function (Browser $browser): void {
            $this->selectAllAndOpen($browser, 'Person 2');
            $this->tick($browser, 'tarif');
            $this->chooseOp($browser, 'tarif', 'formula');
            $this->fill($browser, 'changes.tarif.formula', 'tarif / von');

            $browser->waitForText('Formel in Ordnung.', 15)
                ->waitFor('[data-bulk-error]', 15)
                ->assertSeeIn('[data-bulk-error]', 'Division durch 0.')
                ->assertSee('Bei 1 Datensatz scheitert die Rechnung');
            $this->clickVisibleButton($browser, 'Nur prüfen')
                ->waitForText('Nichts geändert: 1 Datensatz besteht die Prüfungen nicht.', 20)
                ->assertSee('Person 2');

            $this->fill($browser, 'changes.tarif.formula', 'tarif / coalesce(von');
            $browser->waitFor('[data-bulk-invalid]', 15);
            $this->fill($browser, 'changes.tarif.formula', 'tarif * 2 + von');
            $browser->waitFor('[data-bulk-examples]', 15)->assertMissing('[data-bulk-error]');
            $this->clickVisibleButton($browser, '2 Datensätze ändern')->waitForText('2 Datensätze geändert.', 20);
        });

        $this->assertSame(['22.00', '40.00'], $this->column('tarif'));
    }
}
