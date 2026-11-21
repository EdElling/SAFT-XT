<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

/**
 * Liest eine Formel wie `round(menge * einzelpreis, 2)`, `sum(positionen.betrag)` oder
 * `if(typ = 'Ausgabe', -betrag, betrag)` in einen JSON-fähigen Baum:
 *   ['num' => '1.5'] · ['str' => 'Ausgabe'] · ['field' => 'menge'] · ['op' => '+', 'left' => …, 'right' => …]
 *   ['neg' => …] · ['call' => 'round', 'args' => […]] · ['path' => ['positionen', 'betrag']]
 * Vergleiche (= <> < <= > >=) und and/or sind ebenfalls 'op'-Knoten.
 *
 * Grammatik: expr = and ('or' and)* · and = compare ('and' compare)* · compare = sum [vergleich sum] ·
 * sum = term (('+'|'-') term)* · term = unary (('*'|'/') unary)* · unary = '-' unary | primary ·
 * primary = Zahl | 'Text' | Name ['.' Name] | Name '(' [expr (',' expr)*] ')' | '(' expr ')'
 * Werte in Funktionen trennt ein Komma; ein Semikolon gilt ebenso (wie in den Formeln der Auswertungen).
 */
final class Parser
{
    /** Vergleichszeichen; die zweistelligen zuerst, damit „<=“ nicht als „<“ gelesen wird. */
    public const array COMPARISONS = ['<=', '>=', '<>', '=', '<', '>'];

    /** @var list<array{type: string, value: string, pos: int}> */
    private array $tokens = [];

    private int $index = 0;

    /**
     * @return array<string, mixed>
     */
    public function parse(string $formula): array
    {
        if (trim($formula) === '') {
            throw FormulaException::general(__('tables.formula.errors.empty'));
        }

        $this->tokens = $this->tokenize($formula);
        $this->index = 0;
        $node = $this->expression();

        if ($this->peek()['type'] !== 'end') {
            throw FormulaException::at($this->peek()['pos'], __('tables.formula.errors.unexpected', ['token' => $this->peek()['value']]));
        }

        return $node;
    }

    /**
     * @return list<array{type: string, value: string, pos: int}>
     */
    private function tokenize(string $formula): array
    {
        $tokens = [];
        $length = strlen($formula);

        for ($i = 0; $i < $length;) {
            $char = $formula[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (preg_match('/\G(\d+(?:\.\d+)?)/', $formula, $m, 0, $i) === 1) {
                $tokens[] = ['type' => 'num', 'value' => $m[1], 'pos' => $i + 1];
                $i += strlen($m[1]);
            } elseif (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/', $formula, $m, 0, $i) === 1) {
                $tokens[] = ['type' => 'name', 'value' => strtolower($m[1]), 'pos' => $i + 1];
                $i += strlen($m[1]);
            } elseif ($char === "'") {
                // Text in einfachen Anführungszeichen; ein Anführungszeichen im Text wird verdoppelt ('l''Ourson').
                if (preg_match("/\\G'((?:[^']|'')*)'/", $formula, $m, 0, $i) !== 1) {
                    throw FormulaException::at($i + 1, __('tables.formula.errors.unterminated_text'));
                }

                $tokens[] = ['type' => 'str', 'value' => str_replace("''", "'", $m[1]), 'pos' => $i + 1];
                $i += strlen($m[0]);
            } elseif (($comparison = $this->comparisonAt($formula, $i)) !== null) {
                $tokens[] = ['type' => 'cmp', 'value' => $comparison, 'pos' => $i + 1];
                $i += strlen($comparison);
            } elseif (str_contains('+-*/(),.;', $char)) {
                $tokens[] = ['type' => $char === ';' ? ',' : $char, 'value' => $char, 'pos' => $i + 1];
                $i++;
            } else {
                throw FormulaException::at($i + 1, __('tables.formula.errors.character', ['char' => $char]));
            }
        }

        $tokens[] = ['type' => 'end', 'value' => '', 'pos' => $length + 1];

        return $tokens;
    }

    private function comparisonAt(string $formula, int $i): ?string
    {
        foreach (self::COMPARISONS as $comparison) {
            if (substr($formula, $i, strlen($comparison)) === $comparison) {
                return $comparison;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function expression(): array
    {
        $node = $this->conjunction();

        while ($this->acceptKeyword('or')) {
            $node = ['op' => 'or', 'left' => $node, 'right' => $this->conjunction()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function conjunction(): array
    {
        $node = $this->comparison();

        while ($this->acceptKeyword('and')) {
            $node = ['op' => 'and', 'left' => $node, 'right' => $this->comparison()];
        }

        return $node;
    }

    /**
     * Höchstens ein Vergleich je Stufe: „a < b < c“ ist ein Fehler, „a < b and b < c“ richtig.
     *
     * @return array<string, mixed>
     */
    private function comparison(): array
    {
        $node = $this->sum();

        if ($this->peek()['type'] === 'cmp') {
            $token = $this->next();
            $node = ['op' => $token['value'], 'left' => $node, 'right' => $this->sum(), 'pos' => $token['pos']];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function sum(): array
    {
        $node = $this->term();

        while (in_array($this->peek()['type'], ['+', '-'], true)) {
            $op = $this->next()['type'];
            $node = ['op' => $op, 'left' => $node, 'right' => $this->term()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function term(): array
    {
        $node = $this->unary();

        while (in_array($this->peek()['type'], ['*', '/'], true)) {
            $op = $this->next()['type'];
            $node = ['op' => $op, 'left' => $node, 'right' => $this->unary()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function unary(): array
    {
        if ($this->peek()['type'] === '-') {
            $this->next();

            return ['neg' => $this->unary()];
        }

        return $this->primary();
    }

    /**
     * @return array<string, mixed>
     */
    private function primary(): array
    {
        $token = $this->next();

        return match ($token['type']) {
            'num' => ['num' => $token['value'], 'pos' => $token['pos']],
            'str' => ['str' => $token['value'], 'pos' => $token['pos']],
            'name' => $this->name($token),
            '(' => $this->group(),
            default => throw FormulaException::at($token['pos'], $token['type'] === 'end'
                ? __('tables.formula.errors.incomplete')
                : __('tables.formula.errors.unexpected', ['token' => $token['value']])),
        };
    }

    /**
     * @param  array{type: string, value: string, pos: int}  $token
     * @return array<string, mixed>
     */
    private function name(array $token): array
    {
        if ($this->peek()['type'] === '(') {
            $this->next();
            $args = [];

            if ($this->peek()['type'] !== ')') {
                do {
                    $args[] = $this->expression();
                } while ($this->accept(','));
            }

            $this->expect(')');

            return ['call' => $token['value'], 'args' => $args, 'pos' => $token['pos']];
        }

        if ($this->accept('.')) {
            $field = $this->next();

            if ($field['type'] !== 'name') {
                throw FormulaException::at($field['pos'], __('tables.formula.errors.field_expected'));
            }

            return ['path' => [$token['value'], $field['value']], 'pos' => $token['pos']];
        }

        return ['field' => $token['value'], 'pos' => $token['pos']];
    }

    /**
     * @return array<string, mixed>
     */
    private function group(): array
    {
        $node = $this->expression();
        $this->expect(')');

        return $node;
    }

    /**
     * @return array{type: string, value: string, pos: int}
     */
    private function peek(): array
    {
        return $this->tokens[$this->index];
    }

    /**
     * @return array{type: string, value: string, pos: int}
     */
    private function next(): array
    {
        $token = $this->tokens[$this->index];

        if ($token['type'] !== 'end') {
            $this->index++;
        }

        return $token;
    }

    /** and/or: Namen, die nur an der Stelle eines Rechenzeichens als Verknüpfung gelten. */
    private function acceptKeyword(string $keyword): bool
    {
        if ($this->peek()['type'] !== 'name' || $this->peek()['value'] !== $keyword) {
            return false;
        }

        $this->next();

        return true;
    }

    private function accept(string $type): bool
    {
        if ($this->peek()['type'] !== $type) {
            return false;
        }

        $this->next();

        return true;
    }

    private function expect(string $type): void
    {
        if (! $this->accept($type)) {
            throw FormulaException::at($this->peek()['pos'], __('tables.formula.errors.expected', ['token' => $type]));
        }
    }
}
