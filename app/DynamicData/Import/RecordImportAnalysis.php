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
     * @param  int  $rows  Datenzeilen (ohne Kopf- und Leerzeilen)
     * @param  list<string>  $messages  Fehler mit Zeile und Spalte (höchstens RecordImporter::MAX_MESSAGES)
     * @param  bool  $checked  alle Zeilen geprüft; sonst nur gezählt, geprüft wird in der Arbeit
     */
    public function __construct(
        public array $columns,
        public array $ignored,
        public array $missingRequired,
        public int $rows,
        public array $messages,
        public int $errorCount,
        public bool $checked = true,
    ) {}

    /** Nichts spricht gegen den Import – bei checked = false nur, soweit geprüft wurde. */
    public function isValid(): bool
    {
        return $this->errorCount === 0 && $this->missingRequired === [] && $this->rows > 0;
    }
}
