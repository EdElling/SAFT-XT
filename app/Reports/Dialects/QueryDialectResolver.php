<?php

declare(strict_types=1);

namespace App\Reports\Dialects;

use Illuminate\Database\Connection;
use RuntimeException;

/** Wählt den Abfrage-Dialekt anhand des Treibers (config reports.dialects). */
final class QueryDialectResolver
{
    public function for(Connection $connection): QueryDialect
    {
        $driver = $connection->getDriverName();
        $class = config("reports.dialects.{$driver}");

        if (! is_string($class) || ! is_a($class, QueryDialect::class, true)) {
            throw new RuntimeException("Die Report-Engine unterstützt den Datenbanktreiber [{$driver}] (noch) nicht.");
        }

        return app($class);
    }
}
