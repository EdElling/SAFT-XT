<?php

declare(strict_types=1);

namespace App\DynamicData\Cascade;

/**
 * Auswahlpfad der Master-Detail-Kaskade, als URL-Parameter ?sel=…
 *
 *   "5"            Datensatz 5 der Liste ausgewählt
 *   "5,18:7"       darunter über Verknüpfungsfeld 18 Datensatz 7 ausgewählt
 *   "5,18:7,23"    darunter Reiter (Verknüpfungsfeld) 23 aktiv, nichts ausgewählt
 *   "s.UC0wMQ"     Text- oder UUID-Schlüssel der Liste (angebundene externe Tabellen), Base64url
 *
 * Ebene 0 = Liste, Ebene n = n-te Detailebene (Segment n-1).
 */
final readonly class CascadePath
{
    /** Liste + 3 Detailebenen. */
    public const int MAX_LEVELS = 4;

    /** Präfix für Schlüssel, die keine Zahl sind; sie dürfen beliebige Zeichen enthalten. */
    private const string STRING_KEY = 's.';

    /**
     * @param  list<array{field: int, record: int|string|null}>  $segments
     */
    public function __construct(
        public int|string|null $root = null,
        public array $segments = [],
    ) {}

    public static function parse(?string $value): self
    {
        $parts = array_values(array_filter(explode(',', (string) $value), static fn (string $p): bool => $p !== ''));

        $root = $parts === [] ? null : self::parseKey($parts[0]);

        if ($root === null) {
            return new self;
        }

        $segments = [];

        foreach (array_slice($parts, 1, self::MAX_LEVELS - 1) as $part) {
            // Negative Nummer: Tabellenverknüpfung statt Verknüpfungsfeld (siehe DetailSource).
            if (preg_match('/^(-?\d+)(?::(\d+|s\.[A-Za-z0-9_-]+))?$/', $part, $m) !== 1) {
                break;
            }

            $record = isset($m[2]) ? self::parseKey($m[2]) : null;

            if (isset($m[2]) && $record === null) {
                break;
            }

            $segments[] = ['field' => (int) $m[1], 'record' => $record];
        }

        return new self($root, $segments);
    }

    private static function parseKey(string $value): int|string|null
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (! str_starts_with($value, self::STRING_KEY)) {
            return null;
        }

        $decoded = base64_decode(strtr(substr($value, strlen(self::STRING_KEY)), '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    public static function formatKey(int|string $key): string
    {
        return is_int($key) ? (string) $key : self::STRING_KEY.rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    public function toString(): ?string
    {
        if ($this->root === null) {
            return null;
        }

        return implode(',', [
            self::formatKey($this->root),
            ...array_map(static fn (array $s): string => $s['record'] === null ? (string) $s['field'] : $s['field'].':'.self::formatKey($s['record']), $this->segments),
        ]);
    }

    /** Auswahl in der Liste; erneuter Klick auf dieselbe Zeile hebt sie auf. */
    public function selectRoot(int|string $key): self
    {
        return $this->root === $key ? new self : new self($key);
    }

    /**
     * Auswahl auf Detailebene $level (>= 1) im Reiter $field; tiefere Ebenen entfallen.
     * Erneuter Klick auf dieselbe Zeile hebt die Auswahl auf (Reiter bleibt aktiv).
     */
    public function select(int $level, int $field, int|string $record): self
    {
        if ($this->root === null || $level < 1 || $level >= self::MAX_LEVELS || $level - 1 > count($this->segments)) {
            return $this;
        }

        $current = $this->segments[$level - 1] ?? null;
        $same = $current !== null && $current['field'] === $field && $current['record'] === $record;

        return new self($this->root, [
            ...array_slice($this->segments, 0, $level - 1),
            ['field' => $field, 'record' => $same ? null : $record],
        ]);
    }

    public function selectedAt(int $level): int|string|null
    {
        return $level === 0 ? $this->root : ($this->segments[$level - 1]['record'] ?? null);
    }

    public function fieldAt(int $level): ?int
    {
        return $this->segments[$level - 1]['field'] ?? null;
    }

    /** Nur die ersten $levels Ebenen behalten (0 = keine Auswahl). */
    public function truncate(int $levels): self
    {
        if ($levels <= 0) {
            return new self;
        }

        return new self($this->root, array_slice($this->segments, 0, $levels - 1));
    }

    /** Segment ohne Auswahl behalten (Reiter aktiv), Auswahl verwerfen. */
    public function withoutRecordAt(int $level): self
    {
        $segments = array_slice($this->segments, 0, $level);
        $segments[$level - 1]['record'] = null;

        return new self($this->root, $segments);
    }
}
