<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

/** Ergebnis der Prüfung einer Datei für den Datenimport in eine bestehende Tabelle. */
final readonly class RecordImportAnalysis
{
    /**
     * @param  list<string>  $columns  erkannte Spalten (Bezeichnungen der Felder)
     * @param  list<string>  $ignored  Überschriften ohne passendes Feld
     * @param  list<string>  $missingRequired  Pflichtfelder ohne Spalte in der Datei
     * @param  array<int, array<string, mixed>>  $rows  Zeilennummer der Datei => Werte je Feld
     * @param  list<string>  $messages  Fehler mit Zeile und Spalte (höchstens RecordImporter::MAX_MESSAGES)
     */
    public function __construct(
        public array $columns,
        public array $ignored,
        public array $missingRequired,
        public array $rows,
        public array $messages,
        public int $errorCount,
    ) {}

    public function isValid(): bool
    {
        return $this->errorCount === 0 && $this->missingRequired === [] && $this->rows !== [];
    }
}
