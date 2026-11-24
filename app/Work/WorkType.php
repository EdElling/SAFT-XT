<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Vertrag einer Art von Arbeit (Fundament 2, F3a): eine lange Arbeit in kurzen, wiederaufnehmbaren Schritten. Neue
 * Arten werden in config/work.php eingetragen – Plugins liefern sie über SaftPlugin::workTypes(). Die Klasse wird über
 * den Container erzeugt.
 *
 * Ablauf: prepare() bestimmt den Startzustand und die Menge; danach ruft ein Treiber (Browser der startenden Person,
 * Scheduler, Konsole) step() so oft, bis die Art „fertig“ meldet. Zwischen zwei Schritten kann der Prozess wechseln:
 * Alles, was der nächste Schritt braucht, steht im Zustand (JSON-fähig). Jeder Schritt läuft als die startende Person
 * in der App der Arbeit und soll nach wenigen Sekunden zurückkehren (WorkStep::remaining()).
 *
 * Schreibt die Art Datensätze, implementiert sie zusätzlich WritesRecords: Dann sind ein Schritt und sein Fortschritt
 * eine Transaktion.
 *
 * @api
 */
interface WorkType
{
    /** Kennung, gespeichert in work_items.type. */
    public function key(): string;

    public function label(): string;

    /** @param  array<string, mixed>  $parameters  Kurzbeschreibung, z. B. die betroffene Tabelle. */
    public function describe(array $parameters): string;

    /**
     * Startzustand und Menge (Zeilen, Datensätze …) bestimmen; läuft im ersten Schritt.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function prepare(array $parameters): WorkPlan;

    /** Einen Block abarbeiten und den neuen Zustand bzw. das Ergebnis liefern. */
    public function step(WorkStep $step): StepOutcome;
}
