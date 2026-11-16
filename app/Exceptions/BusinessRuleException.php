<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Fachlicher Regelverstoß mit einer für Endnutzer bestimmten Meldung.
 *
 * Wird als erwarteter Fehler behandelt: Meldung wird angezeigt, aber nicht als
 * Fehler protokolliert.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule',
        public readonly ?string $title = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
