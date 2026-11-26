<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

/**
 * Ergebnis der Analyse vorhandener Werte vor dem Umstellen auf „Nummernkreis“ (B-135, N2): Vorschau im Feldformular
 * und Notiz im Änderungsplan einer App.
 */
final readonly class NumberAnalysis
{
    /**
     * @param  int  $values  Werte (nicht leer), auch im Papierkorb
     * @param  int  $matching  davon passend zum Muster
     * @param  int  $empty  nicht gelöschte Datensätze ohne Wert (können nachnummeriert werden)
     * @param  array<string, int>  $highest  Schlüssel aus Gruppe und Periode (key()) => höchste passende laufende Nummer
     * @param  list<string>  $mismatches  die ersten nicht passenden Werte
     * @param  list<string>  $duplicates  die ersten doppelten Werte unter den nicht gelöschten Datensätzen
     */
    public function __construct(
        public int $values,
        public int $matching,
        public int $empty,
        public array $highest,
        public array $mismatches,
        public array $duplicates,
    ) {}

    /**
     * Schlüssel für Gruppe und Periode, z. B. „2026“ ohne Gruppe, „V 2026“ mit Kurzzeichen V (N4). Kurzzeichen und
     * Perioden enthalten keine Leerzeichen.
     */
    public static function key(string $period, string $group = NumberSequences::NO_GROUP): string
    {
        return $group === NumberSequences::NO_GROUP ? $period : $group.' '.$period;
    }

    /**
     * Gegenstück zu key().
     *
     * @return array{group: string, period: string}
     */
    public static function split(string $key): array
    {
        $parts = explode(' ', $key, 2);

        return count($parts) === 2 ? ['group' => $parts[0], 'period' => $parts[1]] : ['group' => NumberSequences::NO_GROUP, 'period' => $key];
    }

    public function hasDuplicates(): bool
    {
        return $this->duplicates !== [];
    }

    /** Eine Zeile für Vorschau und Änderungsplan, z. B. „52 Werte, 50 passen zum Muster, 2 leer · höchste: 2026: 42“. */
    public function summary(): string
    {
        $parts = [trans_choice('numbering.conversion.values', $this->values, ['count' => $this->values, 'matching' => $this->matching])];

        if ($this->empty > 0) {
            $parts[] = trans_choice('numbering.conversion.empty', $this->empty, ['count' => $this->empty]);
        }

        if ($this->highest !== []) {
            $parts[] = __('numbering.conversion.highest', ['list' => implode(', ', array_map(
                static fn (string $key, int $number): string => (($key = trim(str_replace(NumberPattern::NO_PERIOD, '', $key))) === '' ? '' : $key.': ').$number,
                array_keys($this->highest),
                $this->highest,
            ))]);
        }

        if ($this->mismatches !== []) {
            $parts[] = __('numbering.conversion.mismatches', ['list' => implode(', ', $this->mismatches)]);
        }

        if ($this->hasDuplicates()) {
            $parts[] = __('numbering.conversion.duplicates', ['list' => implode(', ', $this->duplicates)]);
        }

        return implode(' · ', $parts);
    }
}
