<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

use App\Exceptions\BusinessRuleException;

/** Formel nicht lesbar oder nicht zulässig; mit Stelle (Zeichen, ab 1) für die Meldung. */
final class FormulaException extends BusinessRuleException
{
    public static function at(int $position, string $message): self
    {
        return new self(__('tables.formula.errors.at', ['position' => $position, 'message' => $message]), 'dynamic.formula');
    }

    public static function general(string $message): self
    {
        return new self($message, 'dynamic.formula');
    }
}
