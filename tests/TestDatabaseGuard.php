<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bricht Feature-Tests ab, bevor sie eine Datenbank leeren, die keine Testdatenbank ist.
 *
 * Hintergrund: Bei gecachter Konfiguration (php artisan optimize / config:cache) ignoriert Laravel die
 * <env>-Werte aus phpunit*.xml, und RefreshDatabase würde die Datenbank aus der .env neu aufsetzen.
 */
final class TestDatabaseGuard
{
    /** Externe Testdatenbanken (Table-Connector), werden von den Tests geleert. */
    private const array EXTERNAL = ['EXT_DB_DATABASE', 'EXT_MYSQL_DB_DATABASE', 'EXT_MARIADB_DB_DATABASE', 'EXT_MSSQL_DB_DATABASE'];

    public static function check(): void
    {
        self::assertSafe(app()->configurationIsCached(), self::targets());
    }

    /**
     * @param  array<string, string>  $targets  Bezeichnung => Datenbank- bzw. Schemaname
     */
    public static function assertSafe(bool $configurationCached, array $targets): void
    {
        if ($configurationCached) {
            throw new RuntimeException(
                'Tests abgebrochen: Die Konfiguration ist gecacht (bootstrap/cache/config.php), die Testdatenbanken aus phpunit*.xml '
                .'würden ignoriert. Zuerst „php artisan optimize:clear“ ausführen.'
            );
        }

        foreach ($targets as $label => $database) {
            if (preg_match('/_test(_app_data)?$/', $database) !== 1) {
                throw new RuntimeException("Tests abgebrochen: {$label} ist „{$database}“, erwartet wird eine Testdatenbank (Endung _test).");
            }
        }
    }

    /** @return array<string, string> */
    private static function targets(): array
    {
        $targets = ['Standardverbindung' => (string) DB::connection()->getDatabaseName()];

        // PostgreSQL: Schema app_data in derselben Datenbank; MySQL/MariaDB: eigene Datenbank <DB>_app_data.
        $targets['app_data'] = DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::connection((string) config('dynamic_data.connection'))->getDatabaseName()
            : (string) config('dynamic_data.namespace');

        foreach (self::EXTERNAL as $variable) {
            $value = env($variable);

            if (is_string($value) && $value !== '') {
                $targets[$variable] = $value;
            }
        }

        return $targets;
    }
}
