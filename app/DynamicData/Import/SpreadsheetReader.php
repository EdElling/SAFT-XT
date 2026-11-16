<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Sheet as XlsxSheet;

/**
 * Liest CSV- und Excel-Dateien zeilenweise (streamend). Werte kommen typisiert: Text, int,
 * float, bool, DateTimeImmutable, DateInterval, null für leere Zellen und CellError für
 * Excel-Fehler. Formeln liefern ihr gespeichertes Ergebnis.
 *
 * CSV: Zeichensatz (UTF-8, sonst Windows-1252) und Trennzeichen werden erkannt.
 */
final class SpreadsheetReader
{
    /** Kandidaten in der Reihenfolge, die bei Gleichstand gewinnt (Semikolon: deutsches Excel). */
    private const array DELIMITERS = [';', ',', "\t", '|'];

    private const int SAMPLE_ROWS = 50;

    public readonly ?string $delimiter;

    public readonly ?string $encoding;

    public function __construct(public readonly string $path, public readonly ImportFormat $format)
    {
        if ($format === ImportFormat::Csv) {
            $this->encoding = mb_check_encoding((string) file_get_contents($path), 'UTF-8') ? 'UTF-8' : 'Windows-1252';
            $this->delimiter = $this->detectDelimiter();
        } else {
            $this->encoding = null;
            $this->delimiter = null;
        }
    }

    /**
     * Blätter einer Excel-Datei (sichtbare zuerst nicht nötig: Reihenfolge wie in der Datei).
     *
     * @return list<array{name: string, visible: bool}>
     */
    public function sheets(): array
    {
        if ($this->format !== ImportFormat::Xlsx) {
            return [];
        }

        return $this->withReader(function (ReaderInterface $reader): array {
            $sheets = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[] = ['name' => $sheet->getName(), 'visible' => ! $sheet instanceof XlsxSheet || $sheet->isVisible()];
            }

            return $sheets;
        });
    }

    /** Gewähltes Blatt, sonst das erste sichtbare. */
    public function resolveSheet(?string $sheet): ?string
    {
        $sheets = $this->sheets();

        if ($sheets === []) {
            return null;
        }

        foreach ($sheets as $candidate) {
            if ($candidate['name'] === $sheet) {
                return $sheet;
            }
        }

        foreach ($sheets as $candidate) {
            if ($candidate['visible']) {
                return $candidate['name'];
            }
        }

        return $sheets[0]['name'];
    }

    /**
     * Zeilen mit Zeilennummer (1 = Kopfzeile); leere Zeilen werden mitgeliefert.
     *
     * @return Generator<int, list<mixed>>
     */
    public function rows(?string $sheet = null): Generator
    {
        $reader = $this->open();

        try {
            $selected = $this->sheet($reader, $sheet);

            if ($selected === null) {
                return;
            }

            foreach ($selected->getRowIterator() as $number => $row) {
                yield (int) $number => array_map($this->value(...), $row->getCells());
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Verbundene Zellbereiche (nur Excel), z. B. ["A1:C1"].
     *
     * @return list<string>
     */
    public function mergedCells(?string $sheet = null): array
    {
        if ($this->format !== ImportFormat::Xlsx) {
            return [];
        }

        return $this->withReader(function (ReaderInterface $reader) use ($sheet): array {
            $selected = $this->sheet($reader, $sheet);

            return $selected instanceof XlsxSheet ? array_values($selected->getMergeCells()) : [];
        });
    }

    private function value(Cell $cell): mixed
    {
        return match (true) {
            $cell instanceof Cell\FormulaCell => $cell->getComputedValue() ?? new CellError($cell->getValue()),
            $cell instanceof Cell\ErrorCell => new CellError((string) $cell->getRawValue()),
            $cell instanceof Cell\EmptyCell => null,
            default => $cell->getValue(),
        };
    }

    private function sheet(ReaderInterface $reader, ?string $name): ?SheetInterface
    {
        $name = $this->format === ImportFormat::Xlsx ? $this->resolveSheet($name) : null;

        foreach ($reader->getSheetIterator() as $sheet) {
            if ($name === null || $sheet->getName() === $name) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(ReaderInterface): T  $callback
     * @return T
     */
    private function withReader(callable $callback): mixed
    {
        $reader = $this->open();

        try {
            return $callback($reader);
        } finally {
            $reader->close();
        }
    }

    private function open(?string $delimiter = null): ReaderInterface
    {
        if ($this->format === ImportFormat::Xlsx) {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $options->SHOULD_LOAD_MERGE_CELLS = true;
            $reader = new XlsxReader($options);
        } else {
            $options = new CsvOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $options->FIELD_DELIMITER = $delimiter ?? $this->delimiter ?? ',';
            $options->ENCODING = $this->encoding ?? 'UTF-8';
            $reader = new CsvReader($options);
        }

        $reader->open($this->path);

        return $reader;
    }

    /** Das Trennzeichen, das in den ersten Zeilen die meisten und gleichmäßigsten Spalten ergibt. */
    private function detectDelimiter(): string
    {
        $best = [self::DELIMITERS[1], 0.0, 0];

        foreach (self::DELIMITERS as $delimiter) {
            $counts = [];
            $reader = $this->open($delimiter);

            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        if ($row->isEmpty()) {
                            continue;
                        }

                        $counts[] = $row->getNumCells();

                        if (count($counts) >= self::SAMPLE_ROWS) {
                            break;
                        }
                    }

                    break;
                }
            } finally {
                $reader->close();
            }

            $header = $counts[0] ?? 0;

            if ($header < 2) {
                continue;
            }

            $consistency = count(array_filter($counts, fn (int $n): bool => $n === $header)) / count($counts);

            if ($consistency > $best[1] || ($consistency === $best[1] && $header > $best[2])) {
                $best = [$delimiter, $consistency, $header];
            }
        }

        return $best[0];
    }
}
