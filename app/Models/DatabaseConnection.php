<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\ConnectionDriver;
use App\Models\Base\DatabaseConnection as BaseDatabaseConnection;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DatabaseConnection extends BaseDatabaseConnection
{
    use HasAuditLog;

    /** Name der Laravel-Verbindung zur Laufzeit; nie eine der eigenen Verbindungen. */
    public const string CONNECTION_PREFIX = 'ext_';

    /** Testergebnisse sind keine Konfigurationsänderung. */
    protected array $auditExcept = ['last_tested_at', 'last_test_successful', 'last_test_message', 'known_schemas'];

    /** Änderungen am Passwort werden protokolliert, der Wert nie. */
    protected array $auditMasked = ['password'];

    /** Spiegelt die DB-Defaults, damit das Audit-Log keine Phantom-Änderungen erzeugt. */
    protected $attributes = [
        'ssl_mode' => 'prefer',
        'connect_timeout' => 5,
        'statement_timeout' => 30,
        'is_read_only' => true,
        'is_active' => true,
    ];

    /** Verbindungsdaten: Eine Änderung macht den letzten Test ungültig. */
    private const array CONNECTION_SETTINGS = ['driver', 'host', 'port', 'database', 'username', 'password', 'default_schema', 'ssl_mode'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => ConnectionDriver::class,
            'password' => 'encrypted',
            'known_schemas' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $connection): void {
            $count = $connection->customTables()->count();

            if ($count > 0) {
                throw new BusinessRuleException(trans_choice('db_connections.errors.in_use', $count, ['count' => $count]), 'external.connection_in_use');
            }
        });

        static::saving(static function (self $connection): void {
            // TLS-Modus passend zum Datenbanktyp (MySQL kennt z. B. kein „prefer“).
            if ($connection->driver instanceof ConnectionDriver && ! in_array($connection->ssl_mode, $connection->driver->sslModes(), true)) {
                $connection->ssl_mode = $connection->driver->defaultSslMode();
            }

            if ($connection->exists && $connection->isDirty(self::CONNECTION_SETTINGS) && ! $connection->isDirty('last_test_successful')) {
                $connection->last_test_successful = null;
                $connection->last_tested_at = null;
                $connection->last_test_message = null;
            }
        });
    }

    /** @return HasMany<CustomTable, $this> */
    public function customTables(): HasMany
    {
        return $this->hasMany(CustomTable::class);
    }

    public function connectionName(): string
    {
        return self::CONNECTION_PREFIX.$this->getKey();
    }

    /** Eingetragenes Vorgabeschema, sonst das des Datenbanktyps (PostgreSQL: public, MySQL: die Datenbank). */
    public function schemaName(): string
    {
        return filled($this->default_schema) ? (string) $this->default_schema : $this->driver->defaultSchema((string) $this->database);
    }
}
