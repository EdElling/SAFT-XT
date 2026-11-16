<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

/** Ein Befund der Prüfung: Fehler verhindern den Import, Warnungen nicht. */
final readonly class ImportProblem
{
    public const string ERROR = 'error';

    public const string WARNING = 'warning';

    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
    ) {}

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }
}
