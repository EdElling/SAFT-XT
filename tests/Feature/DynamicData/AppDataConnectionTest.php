<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Ddl\DdlDialectResolver;
use Illuminate\Support\Facades\DB;

/** Namensraum app_data bei Hostern mit einem Benutzer und Server je Datenbank (z. B. Strato). */
class AppDataConnectionTest extends DynamicDataTestCase
{
    public function test_existing_database_is_not_created_again(): void
    {
        $connection = DB::connection(config('dynamic_data.connection'));

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Nur MySQL und MariaDB (eigene Datenbank als Namensraum).');
        }

        $statements = [];
        $connection->listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(DdlDialectResolver::class)->for($connection)->ensureNamespace($connection, (string) config('dynamic_data.namespace'));

        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => stripos($sql, 'create database') !== false)),
            'Vorhandene Datenbank: kein CREATE DATABASE (Strato erlaubt es nicht).');
    }

    public function test_app_data_can_use_its_own_server_and_user(): void
    {
        $keys = ['DB_CONNECTION' => 'mysql', 'DB_APP_DATA_HOST' => 'database-5022222222.webspace-host.com', 'DB_APP_DATA_PORT' => '3307', 'DB_APP_DATA_USERNAME' => 'dbu22222222', 'DB_APP_DATA_PASSWORD' => 'zwei'];
        $previous = array_map(static fn (string $key): mixed => $_ENV[$key] ?? null, array_combine(array_keys($keys), array_keys($keys)));

        try {
            foreach ($keys as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }

            $config = require base_path('config/database.php');
            $appData = $config['connections']['app_data'];

            $this->assertSame(['database-5022222222.webspace-host.com', '3307', 'dbu22222222', 'zwei', ''], [$appData['host'], $appData['port'], $appData['username'], $appData['password'], $appData['database']]);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key], $_SERVER[$key]);
                    putenv($key);
                } else {
                    $_ENV[$key] = $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}
