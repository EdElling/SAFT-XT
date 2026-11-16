<?php

declare(strict_types=1);

namespace App\Tasks;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;

/**
 * Vertrag einer Art von geplanter Aufgabe. Neue Arten (z. B. Daten an eine Schnittstelle senden, Sicherungen)
 * implementieren dieses Interface und werden in config/tasks.php eingetragen.
 */
interface TaskType
{
    /** Kennung, gespeichert in scheduled_tasks.type. */
    public function key(): string;

    public function label(): string;

    /** In der Verwaltung neu anlegbar (System-Arten legt SAFT selbst an). */
    public function creatable(): bool;

    /**
     * Formularfelder für die Parameter (Feldnamen ohne Präfix, gespeichert in scheduled_tasks.parameters).
     *
     * @return list<Component|Field>
     */
    public function parameterFields(): array;

    /** @param  array<string, mixed>  $parameters  Kurzbeschreibung, z. B. die betroffene Tabelle. */
    public function describe(array $parameters): string;

    /**
     * Führt die Aufgabe aus.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws TaskSkipped wenn sie gerade nicht laufen kann (z. B. läuft schon)
     */
    public function run(array $parameters): TaskResult;
}
