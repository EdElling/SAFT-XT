<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use App\DynamicData\DataMenu;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;

/** Kunden → Bestellungen → Positionen mit Details-Konfiguration und Menüeinträgen. */
trait CreatesOrderData
{
    protected CustomTable $kunden;

    protected CustomTable $bestellungen;

    protected CustomTable $positionen;

    protected DynamicRecord $acme;

    protected DynamicRecord $beta;

    protected DynamicRecord $orderAcme;

    protected DynamicRecord $orderBeta;

    protected function createOrderData(bool $secondRelation = false, int $extraCustomers = 0): void
    {
        $schema = app(DynamicSchemaService::class);

        $this->kunden = $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string'],
        ]);
        $this->bestellungen = $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ...($secondRelation ? [['name' => 'rechnung_an_id', 'label' => 'Rechnung an', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']]] : []),
        ]);
        $this->positionen = $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string', 'is_required' => true],
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
        ]);

        $schema->updateTable($this->kunden, ['detail_field_ids' => [
            $this->fieldId($this->bestellungen, 'kunde_id'),
            ...($secondRelation ? [$this->fieldId($this->bestellungen, 'rechnung_an_id')] : []),
        ]]);
        $schema->updateTable($this->bestellungen, ['detail_field_ids' => [$this->fieldId($this->positionen, 'bestellung_id')]]);

        $this->acme = DynamicRecord::forTable($this->kunden)->create(['firma' => 'ACME GmbH', 'ort' => 'Berlin']);
        $this->beta = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Beta AG', 'ort' => 'Hamburg']);

        for ($number = 1; $number <= $extraCustomers; $number++) {
            DynamicRecord::forTable($this->kunden)->create(['firma' => sprintf('Zusatz %02d', $number), 'ort' => 'Köln']);
        }

        $this->orderAcme = DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-100', 'kunde_id' => $this->acme->id]);
        $this->orderBeta = DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-200', 'kunde_id' => $this->beta->id]);
        DynamicRecord::forTable($this->positionen)->create(['artikel' => 'Schraube M8', 'bestellung_id' => $this->orderAcme->id]);
        DynamicRecord::forTable($this->positionen)->create(['artikel' => 'Mutter M8', 'bestellung_id' => $this->orderBeta->id]);

        $menu = app(DataMenu::class);

        foreach ([$this->kunden, $this->bestellungen, $this->positionen] as $table) {
            $menu->ensure($table);
        }
    }

    protected function fieldId(CustomTable $table, string $name): int
    {
        return (int) $table->fields()->where('name', $name)->value('id');
    }
}
