<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

/**
 * Liest eine Formel wie `round(menge * einzelpreis, 2)` oder `sum(positionen.betrag)` in einen
 * JSON-fähigen Baum:
 *   ['num' => '1.5'] · ['field' => 'menge'] · ['op' => '+', 'left' => …, 'right' => …]
 *   ['neg' => …] · ['call' => 'round', 'args' => […]] · ['path' => ['positionen', 'betrag']]
 *
 * Grammatik: expr = term (('+'|'-') term)* · term = unary (('*'|'/') unary)* ·
 * unary = '-' unary | primary · primary = Zahl | Name ['.' Name] | Name '(' [expr (',' expr)*] ')' | '(' expr ')'
 */
final class Parser
{
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
            } elseif (str_contains('+-*/(),.', $char)) {
                $tokens[] = ['type' => $char, 'value' => $char, 'pos' => $i + 1];
                $i++;
            } else {
                throw FormulaException::at($i + 1, __('tables.formula.errors.character', ['char' => $char]));
            }
        }

        $tokens[] = ['type' => 'end', 'value' => '', 'pos' => $length + 1];

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function expression(): array
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
