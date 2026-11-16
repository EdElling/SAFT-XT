<?php

declare(strict_types=1);

namespace App\Reports;

use App\Exceptions\BusinessRuleException;

/** Auswertung passt nicht (mehr) zum Datenmodell oder zu den Rechten; nennt alle Gründe. */
final class InvalidReportException extends BusinessRuleException
{
    /** @param  list<string>  $errors */
    public function __construct(public readonly array $errors, string $code = 'report.invalid')
    {
        parent::__construct(implode("\n", $errors), $code);
    }
}
