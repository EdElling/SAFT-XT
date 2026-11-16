<?php

declare(strict_types=1);

namespace App\ExternalData;

/** CHECK-Regel einer externen Tabelle, wie die Datenbank sie meldet. */
final readonly class CheckConstraintInfo
{
    /**
     * @param  string  $clause  Ausdruck im Dialekt der Datenbank
     * @param  list<string>  $columns  betroffene Spalten
     */
    public function __construct(
        public string $name,
        public string $clause,
        public array $columns,
    ) {}
}
