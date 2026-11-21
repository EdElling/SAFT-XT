<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Plugins\PluginRegistry;

/**
 * Prüft eine Formel gegen ihre Tabelle (Felder, Funktionen, Anzahl der Argumente, Kind-Tabellen) und
 * ermittelt Abhängigkeiten, die Rechenreihenfolge und Kreisbezüge.
 *
 * Aggregate über Kind-Datensätze werden zu ['agg' => 'sum', 'table' => …, 'field' => …|null oder
 * 'expr' => Ausdruck je Kind-Datensatz (Felder der Kind-Tabelle), 'via' => Verknüpfungsfeld der Kind-Tabelle,
 * 'explicit' => bool (Verknüpfung in der Formel genannt)].
 */
final class FormulaAnalyzer
{
    /** Funktion => [mindestens, höchstens] Argumente (null = beliebig viele). */
    public const array FUNCTIONS = [
        'round' => [1, 2],
        'abs' => [1, 1],
        'min' => [2, null],
        'max' => [2, null],
        'coalesce' => [2, null],
    ];

    /** Aggregate über Kind-Datensätze. */
    public const array AGGREGATES = ['sum', 'count', 'min', 'max', 'avg'];

    /**
     * Reservierte Namen: if(bedingung, dann[, sonst]) sowie and/or. Nicht in FUNCTIONS, weil die Formeln der
     * Auswertungen (ReportExpression) jene Liste mitbenutzen und if() dort (noch) nicht kennen.
     */
    public const array KEYWORDS = ['if', 'and', 'or', ...self::TEXT_FUNCTIONS];

    /** Unscharfe Textvergleiche (ohne Gross-/Kleinschreibung), Ergebnis 1 oder 0: contains(text, teil) usw. */
    public const array TEXT_FUNCTIONS = ['contains', 'startswith', 'endswith'];

    /**
     * Eingebaute Funktionen und die Formelfunktionen der Plugins: Name => [mindestens, höchstens] Argumente.
     *
     * @return array<string, array{0: int, 1: int|null}>
     */
    public static function functions(): array
    {
        $functions = self::FUNCTIONS;

        foreach (app(PluginRegistry::class)->formulaFunctions() as $name => $function) {
            $functions[$name] = [$function->minArguments, $function->maxArguments];
        }

        return $functions;
    }

    /**
     * Kind-Tabellen eines Entwurfs (KI-App-Designer, B-107 Etappe 7a), die es in der Datenbank noch nicht gibt; null =
     * aus der Datenbank. Nur während withChildren() gesetzt.
     *
     * @var array<string, array<string, array{table: CustomTable, relations: list<string>}>>|null
     */
    private static ?array $virtualChildren = null;

    /**
     * Formeln gegen nicht gespeicherte Tabellen prüfen: children() liefert während $check die angegebenen Kind-Tabellen.
     *
     * @template T
     *
     * @param  array<string, array<string, array{table: CustomTable, relations: list<string>}>>  $children  Tabelle => Kind-Tabellen
     * @param  \Closure(): T  $check
     * @return T
     */
    public static function withChildren(array $children, \Closure $check): mixed
    {
        $before = self::$virtualChildren;
        self::$virtualChildren = $children;

        try {
            return $check();
        } finally {
            self::$virtualChildren = $before;
        }
    }

    public function __construct(private readonly Parser $parser) {}

    /**
     * Formel lesen und prüfen.
     *
     * @param  array<string, CustomField>  $fields  Felder der Tabelle nach Name
     * @param  string|null  $table  Name der Tabelle (für Summen über Kind-Datensätze)
     * @return array{ast: array<string, mixed>, fields: list<string>}
     */
    public function compile(string $formula, array $fields, string $self, ?string $table = null): array
    {
        $ast = $this->check($this->parser->parse($formula), $fields, $self, $table);

        return ['ast' => self::strip($ast), 'fields' => self::fields($ast)];
    }

    /**
     * Felder desselben Datensatzes, von denen ein Formelbaum abhängt.
     *
     * @param  array<string, mixed>  $ast
     * @return list<string>
     */
    public static function fields(array $ast): array
    {
        $found = [];
        Printer::map($ast, static function (array $node) use (&$found): array {
            if (isset($node['field']) && ! isset($node['agg'])) {
                $found[] = (string) $node['field'];
            }

            return $node;
        });

        return array_values(array_unique($found));
    }

    /**
     * Aggregate eines Formelbaums.
     *
     * @param  array<string, mixed>  $ast
     * @return list<array{agg: string, table: string, field: string|null, fields: list<string>, via: string}>
     */
    public static function aggregates(array $ast): array
    {
        $found = [];
        Printer::map($ast, static function (array $node) use (&$found): array {
            if (isset($node['agg'])) {
                $found[] = [
                    'agg' => (string) $node['agg'],
                    'table' => (string) $node['table'],
                    'field' => isset($node['field']) ? (string) $node['field'] : null,
                    // Alle Felder der Kind-Tabelle, die summiert werden (Feld oder Ausdruck).
                    'fields' => isset($node['expr']) ? self::fields((array) $node['expr']) : array_values(array_filter([isset($node['field']) ? (string) $node['field'] : null])),
                    'via' => (string) $node['via'],
                ];
            }

            return $node;
        });

        return $found;
    }

    /**
     * Kreisbezug, wenn das Feld $self die Formel $ast bekäme (die übrigen berechneten Felder wie gespeichert).
     *
     * @param  array<string, CustomField>  $fields
     * @param  array<string, mixed>  $ast
     *
     * @throws FormulaException bei Kreisbezug
     */
    public static function assertNoCycle(array $fields, string $self, array $ast): void
    {
        $field = new CustomField(['name' => $self, 'type' => FieldType::Computed->value, 'is_active' => true, 'options' => ['ast' => $ast]]);
        self::order([...array_filter($fields, static fn (CustomField $f): bool => $f->name !== $self), $field]);
    }

    /**
     * Berechnete Felder einer Tabelle in Rechenreihenfolge (abhängige nach ihren Quellen).
     *
     * @param  iterable<CustomField>  $fields
     * @return list<CustomField>
     *
     * @throws FormulaException bei Kreisbezug
     */
    public static function order(iterable $fields): array
    {
        $computed = [];

        foreach ($fields as $field) {
            if ($field->type === FieldType::Computed && $field->is_active && is_array($field->optionsArray()['ast'] ?? null)) {
                $computed[$field->name] = $field;
            }
        }

        $ordered = [];
        $state = [];
        $visit = static function (string $name, array $trail) use (&$visit, &$ordered, &$state, $computed): void {
            if (($state[$name] ?? null) === 'done') {
                return;
            }

            if (($state[$name] ?? null) === 'busy') {
                // Mit den Formeln der beteiligten Felder: So ist sichtbar, welche Formel den Kreis schliesst.
                $cycle = array_slice([...$trail, $name], (int) array_search($name, $trail, true));
                $formulas = array_map(
                    static fn (string $field): string => $field.' = '.Printer::print((array) $computed[$field]->optionsArray()['ast']),
                    array_values(array_unique(array_slice($cycle, 0, -1))),
                );

                throw FormulaException::general(__('tables.formula.errors.cycle', ['fields' => implode(' → ', $cycle), 'formulas' => implode('; ', $formulas)]));
            }

            $state[$name] = 'busy';

            foreach (self::fields((array) $computed[$name]->optionsArray()['ast']) as $dependency) {
                if (isset($computed[$dependency])) {
                    $visit($dependency, [...$trail, $name]);
                }
            }

            $state[$name] = 'done';
            $ordered[] = $computed[$name];
        };

        foreach (array_keys($computed) as $name) {
            $visit($name, []);
        }

        return $ordered;
    }

    /**
     * Kind-Tabellen: Designer-Tabellen mit Verknüpfungen auf diese Tabelle.
     *
     * @return array<string, array{table: CustomTable, relations: list<string>}>
     */
    public static function children(string $table): array
    {
        if (self::$virtualChildren !== null) {
            return self::$virtualChildren[$table] ?? [];
        }

        $children = [];

        foreach (CustomField::query()->with('custom_table.fields')->where('type', FieldType::Relation->value)->where('options->table', $table)->orderBy('id')->get() as $relation) {
            $child = $relation->custom_table;

            if ($child instanceof CustomTable && ! $child->isExternal() && $relation->is_active) {
                $children[$child->name] ??= ['table' => $child, 'relations' => []];
                $children[$child->name]['relations'][] = $relation->name;
            }
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, CustomField>  $fields
     * @return array<string, mixed>
     */
    private function check(array $node, array $fields, string $self, ?string $table, bool $comparable = false): array
    {
        $pos = (int) ($node['pos'] ?? 1);

        if (isset($node['str'])) {
            return $comparable ? $node : throw FormulaException::at($pos, __('tables.formula.errors.text_outside_comparison'));
        }

        if (isset($node['field'])) {
            $name = (string) $node['field'];
            $field = $fields[$name] ?? null;

            if ($name === $self) {
                throw FormulaException::at($pos, __('tables.formula.errors.self', ['field' => $name]));
            }

            if ($field === null || ! $field->is_active) {
                throw FormulaException::at($pos, __('tables.formula.errors.unknown_field', ['field' => $name]));
            }

            self::assertType($field, $name, $pos, $comparable);

            return $node;
        }

        if (isset($node['path'])) {
            throw FormulaException::at($pos, __('tables.formula.errors.path_outside_aggregate', ['path' => implode('.', $node['path'])]));
        }

        if (isset($node['call'])) {
            $name = (string) $node['call'];
            $args = (array) $node['args'];

            if ($this->isAggregate($name, $args, $fields)) {
                return $this->aggregate($name, $args, $pos, $self, $table);
            }

            if ($name === 'if') {
                $node['args'] = array_map(fn (array $arg): array => $this->check($arg, $fields, $self, $table), self::ifArguments($args, $pos));

                return $node;
            }

            if (in_array($name, self::TEXT_FUNCTIONS, true)) {
                $node['args'] = array_map(fn (array $arg): array => $this->check($arg, $fields, $self, $table, true), self::textArguments($name, $args, $pos));

                return $node;
            }

            [$min, $max] = self::functions()[$name] ?? throw FormulaException::at($pos, __('tables.formula.errors.unknown_function', ['function' => $name]));

            if (count($args) < $min || ($max !== null && count($args) > $max)) {
                throw FormulaException::at($pos, __('tables.formula.errors.arguments', ['function' => $name]));
            }

            // Nachkommastellen von round: feste ganze Zahl 0–10.
            if ($name === 'round' && isset($args[1]) && ! (isset($args[1]['num']) && ctype_digit((string) $args[1]['num']) && (int) $args[1]['num'] <= 10)) {
                throw FormulaException::at((int) ($args[1]['pos'] ?? $pos), __('tables.formula.errors.round_digits'));
            }

            $node['args'] = array_map(fn (array $arg): array => $this->check($arg, $fields, $self, $table), $args);

            return $node;
        }

        // Die Seiten eines Vergleichs dürfen auch Text und Felder ohne Zahl sein (Auswahl, Text, Datum, Ja/Nein).
        $sides = self::isComparison($node);

        foreach (['left', 'right', 'neg'] as $key) {
            if (isset($node[$key])) {
                $node[$key] = $this->check($node[$key], $fields, $self, $table, $sides);
            }
        }

        return $node;
    }

    /** @param  array<string, mixed>  $node */
    private static function isComparison(array $node): bool
    {
        return isset($node['op']) && in_array($node['op'], Parser::COMPARISONS, true);
    }

    /**
     * Zahlenfeld zum Rechnen (Ja/Nein zählt als 1 oder 0, z. B. if(bezahlt, betrag, 0)); in Vergleichen jedes Feld
     * ausser Dateien.
     */
    private static function assertType(CustomField $field, string $label, int $pos, bool $comparable): void
    {
        if ($comparable ? $field->valueType() === FieldType::File : ! in_array($field->valueType(), [FieldType::Integer, FieldType::Decimal, FieldType::Boolean], true)) {
            throw FormulaException::at($pos, __($comparable ? 'tables.formula.errors.not_comparable' : 'tables.formula.errors.not_number', ['field' => $label]));
        }
    }

    /**
     * contains/startswith/endswith(text, teil): genau zwei Werte; beide dürfen Text oder Felder ohne Zahl sein.
     *
     * @param  list<array<string, mixed>>  $args
     * @return list<array<string, mixed>>
     */
    private static function textArguments(string $name, array $args, int $pos): array
    {
        if (count($args) !== 2) {
            throw FormulaException::at($pos, __('tables.formula.errors.arguments', ['function' => $name]));
        }

        return $args;
    }

    /**
     * if(bedingung, dann[, sonst]): zwei oder drei Werte.
     *
     * @param  list<array<string, mixed>>  $args
     * @return list<array<string, mixed>>
     */
    private static function ifArguments(array $args, int $pos): array
    {
        if (count($args) < 2 || count($args) > 3) {
            throw FormulaException::at($pos, __('tables.formula.errors.arguments', ['function' => 'if']));
        }

        return $args;
    }

    /**
     * sum/avg/min/max(kind.feld[, verknüpfung]) oder count(kind[, verknüpfung]).
     *
     * @param  list<array<string, mixed>>  $args
     * @param  array<string, CustomField>  $fields
     */
    private function isAggregate(string $name, array $args, array $fields): bool
    {
        if (! in_array($name, self::AGGREGATES, true) || $args === [] || count($args) > 2 || (isset($args[1]) && ! isset($args[1]['field']))) {
            return false;
        }

        // min/max mit mehreren Werten sind die normalen Funktionen. Aggregat: ein Pfad oder ein Ausdruck mit
        // Pfaden (tabelle.feld), oder ein einzelner Name, der kein Feld dieser Tabelle ist (count(positionen)).
        return self::paths($args[0]) !== [] || (isset($args[0]['field']) && ! isset($fields[$args[0]['field']]));
    }

    /**
     * Pfade (tabelle.feld) in einem Ausdruck.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function paths(array $node): array
    {
        $paths = [];
        Printer::map($node, static function (array $n) use (&$paths): array {
            if (isset($n['path'])) {
                $paths[] = $n;
            }

            return $n;
        });

        return $paths;
    }

    /**
     * sum/avg/min/max(kind.feld) – oder über einen Ausdruck je Kind-Datensatz, z. B.
     * sum(positionen.menge * positionen.einzelpreis) – sowie count(kind); optional mit Verknüpfung.
     *
     * @param  list<array<string, mixed>>  $args
     * @return array<string, mixed>
     */
    private function aggregate(string $name, array $args, int $pos, string $self, ?string $table): array
    {
        $paths = self::paths($args[0]);
        $childName = $paths === [] ? (string) $args[0]['field'] : (string) $paths[0]['path'][0];
        $children = $table === null ? [] : self::children($table);
        $child = $children[$childName] ?? throw FormulaException::at($pos, __('tables.formula.errors.unknown_child', ['table' => $childName]));

        if ($name !== 'count' && $paths === []) {
            throw FormulaException::at($pos, __('tables.formula.errors.aggregate_field', ['function' => $name]));
        }

        if ($name === 'count' && $paths !== []) {
            throw FormulaException::at($pos, __('tables.formula.errors.count_table'));
        }

        // Ein einzelnes Feld rechnet die Datenbank zusammen, ein Ausdruck wird je Kind-Datensatz berechnet.
        $simple = isset($args[0]['path']);
        $expression = $paths === [] ? null : $this->childExpression($args[0], $child['table'], $childName, $self, $table);

        $via = isset($args[1]) ? (string) $args[1]['field'] : null;

        if ($via === null && count($child['relations']) > 1) {
            throw FormulaException::at($pos, __('tables.formula.errors.via_needed', ['table' => $childName, 'relations' => implode(', ', $child['relations'])]));
        }

        if ($via !== null && ! in_array($via, $child['relations'], true)) {
            throw FormulaException::at((int) ($args[1]['pos'] ?? $pos), __('tables.formula.errors.unknown_via', ['field' => $via, 'table' => $childName]));
        }

        return array_filter([
            'agg' => $name,
            'table' => $childName,
            'field' => $simple ? (string) $args[0]['path'][1] : null,
            'expr' => ! $simple && $expression !== null ? self::strip($expression) : null,
            'via' => $via ?? $child['relations'][0],
            'explicit' => $via !== null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Ausdruck über Felder einer Kind-Tabelle: Pfade werden zu Feldern des Kind-Datensatzes. Felder des
     * Master-Datensatzes, andere Tabellen und verschachtelte Zusammenfassungen sind nicht erlaubt.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function childExpression(array $node, CustomTable $child, string $childName, string $self, ?string $table, bool $comparable = false): array
    {
        $pos = (int) ($node['pos'] ?? 1);

        if (isset($node['str'])) {
            return $comparable ? $node : throw FormulaException::at($pos, __('tables.formula.errors.text_outside_comparison'));
        }

        if (isset($node['path'])) {
            [$tableName, $fieldName] = $node['path'];

            if ($tableName !== $childName) {
                throw FormulaException::at($pos, __('tables.formula.errors.aggregate_one_table', ['table' => $childName]));
            }

            $field = $child->fields->firstWhere('name', $fieldName);

            if (! $field instanceof CustomField || ! $field->is_active) {
                throw FormulaException::at($pos, __('tables.formula.errors.unknown_child_field', ['field' => $fieldName, 'table' => $childName]));
            }

            self::assertType($field, $childName.'.'.$fieldName, $pos, $comparable);

            // Über die eigene Tabelle (Baum): keine berechneten Felder, sonst rechnete es im Kreis.
            if ($childName === $table && ($field->type === FieldType::Computed || $fieldName === $self)) {
                throw FormulaException::at($pos, __('tables.formula.errors.self_aggregate'));
            }

            return ['field' => $fieldName, 'pos' => $pos];
        }

        if (isset($node['field'])) {
            throw FormulaException::at($pos, __('tables.formula.errors.aggregate_parent_field', ['field' => $node['field'], 'table' => $childName]));
        }

        if (isset($node['call']) && $node['call'] === 'if') {
            $node['args'] = array_map(fn (array $arg): array => $this->childExpression($arg, $child, $childName, $self, $table), self::ifArguments((array) $node['args'], $pos));

            return $node;
        }

        if (isset($node['call']) && in_array($node['call'], self::TEXT_FUNCTIONS, true)) {
            $node['args'] = array_map(fn (array $arg): array => $this->childExpression($arg, $child, $childName, $self, $table, true), self::textArguments((string) $node['call'], (array) $node['args'], $pos));

            return $node;
        }

        if (isset($node['call'])) {
            $function = (string) $node['call'];
            $args = (array) $node['args'];
            [$min, $max] = self::functions()[$function] ?? throw FormulaException::at($pos, in_array($function, self::AGGREGATES, true)
                ? __('tables.formula.errors.aggregate_nested')
                : __('tables.formula.errors.unknown_function', ['function' => $function]));

            if (count($args) < $min || ($max !== null && count($args) > $max)) {
                throw FormulaException::at($pos, __('tables.formula.errors.arguments', ['function' => $function]));
            }

            if ($function === 'round' && isset($args[1]) && ! (isset($args[1]['num']) && ctype_digit((string) $args[1]['num']) && (int) $args[1]['num'] <= 10)) {
                throw FormulaException::at((int) ($args[1]['pos'] ?? $pos), __('tables.formula.errors.round_digits'));
            }

            $node['args'] = array_map(fn (array $arg): array => $this->childExpression($arg, $child, $childName, $self, $table), $args);

            return $node;
        }

        $sides = self::isComparison($node);

        foreach (['left', 'right', 'neg'] as $key) {
            if (isset($node[$key])) {
                $node[$key] = $this->childExpression($node[$key], $child, $childName, $self, $table, $sides);
            }
        }

        return $node;
    }

    /**
     * Positionen entfernen (nur für Meldungen beim Lesen nötig).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function strip(array $node): array
    {
        return Printer::map($node, static function (array $n): array {
            unset($n['pos']);

            return $n;
        });
    }
}
