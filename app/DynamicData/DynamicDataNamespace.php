<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\DynamicData\Ddl\DdlDialect;
use App\DynamicData\Ddl\DdlDialectResolver;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Zugang zum Namensraum des Table-Designers: Verbindung, Dialekt, Qualifizierung.
 */
final class DynamicDataNamespace
{
    public function __construct(private readonly DdlDialectResolver $dialects) {}

    public function connection(): Connection
    {
        return DB::connection(config('dynamic_data.connection'));
    }

    public function name(): string
    {
        return (string) config('dynamic_data.namespace');
    }

    public function dialect(): DdlDialect
    {
        return $this->dialects->for($this->connection());
    }

    /** Immer explizit qualifiziert – nie allein auf den search_path verlassen. */
    public function qualify(string $table): string
    {
        return $this->dialect()->qualify($this->name(), $table);
    }

    public function reset(): void
    {
        $connection = $this->connection();
        $this->dialect()->dropNamespace($connection, $this->name());
        $this->dialect()->ensureNamespace($connection, $this->name());
    }
}
