<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Migrationen tragen eine fortlaufende Nummer statt eines Datums (0001_01_01_000096_name.php), eindeutig über
 * database/migrations und database/settings (siehe App\Database\Migrations\NumberedMigrationCreator).
 * php artisan make:migration vergibt sie; make:settings-migration nicht – die Datei dann von Hand umbenennen.
 */
class MigrationNamingTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../database/';

    public function test_migrations_are_numbered_consecutively_without_dates(): void
    {
        $numbers = [];
        $wrong = [];

        foreach (['migrations', 'settings'] as $dir) {
            foreach (glob(self::ROOT.$dir.'/*.php') ?: [] as $file) {
                $name = basename($file);

                if (preg_match('/^0001_01_01_(\d{6})_[a-z0-9_]+\.php$/', $name, $m) !== 1) {
                    $wrong[] = "{$dir}/{$name}";

                    continue;
                }

                $numbers[(int) $m[1]][] = "{$dir}/{$name}";
            }
        }

        $this->assertSame([], $wrong, "Migrationen ohne fortlaufende Nummer (php artisan make:migration vergibt sie):\n- ".implode("\n- ", $wrong));

        $duplicates = array_filter($numbers, static fn (array $files): bool => count($files) > 1);
        $this->assertSame([], $duplicates, 'Nummer mehrfach vergeben: '.json_encode($duplicates, JSON_UNESCAPED_SLASHES));

        ksort($numbers);
        $this->assertSame(range(1, count($numbers)), array_keys($numbers), 'Lücke in der Nummernfolge: beim Löschen einer Migration die folgenden nachrücken lassen.');
    }
}
