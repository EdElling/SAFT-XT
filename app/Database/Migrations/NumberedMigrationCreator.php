<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use Illuminate\Database\Migrations\MigrationCreator;

/**
 * Migrationen heissen 0001_01_01_000096_<name>.php: eine fortlaufende Nummer über database/migrations und
 * database/settings statt eines Datums (Fundament 2, F0). Laravel ordnet nach Dateinamen; mit Datumspräfixen
 * liefe eine neue Migration auf einer frischen Installation an anderer Stelle als auf einer bestehenden.
 * Eingebunden in AppServiceProvider; php artisan make:migration vergibt damit die nächste Nummer.
 * tests/Unit/MigrationNamingTest.php prüft die Konvention.
 */
class NumberedMigrationCreator extends MigrationCreator
{
    public const string PREFIX = '0001_01_01_';

    /** @return list<string> Verzeichnisse, die zur selben Nummernfolge gehören */
    public static function paths(): array
    {
        return [database_path('migrations'), database_path('settings')];
    }

    public static function nextPrefix(): string
    {
        $highest = 0;

        foreach (self::paths() as $path) {
            foreach (glob($path.'/'.self::PREFIX.'*.php') ?: [] as $file) {
                $highest = max($highest, (int) substr(basename($file), strlen(self::PREFIX), 6));
            }
        }

        return self::PREFIX.sprintf('%06d', $highest + 1);
    }

    protected function getDatePrefix(): string
    {
        return self::nextPrefix();
    }
}
