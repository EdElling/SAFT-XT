<?php

declare(strict_types=1);

namespace App\DynamicData\Ddl;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Wählt den DDL-Dialekt anhand des Treibers der Verbindung (config dynamic_data.dialects).
 */
final class DdlDialectResolver
{
    public function for(Connection $connection): DdlDialect
    {
        $driver = $connection->getDriverName();
        $class = config("dynamic_data.dialects.{$driver}");

        if (! is_string($class) || ! is_a($class, DdlDialect::class, true)) {
            throw new RuntimeException("Der Table-Designer unterstützt den Datenbanktreiber [{$driver}] (noch) nicht.");
        }

        return app($class);
    }
}
