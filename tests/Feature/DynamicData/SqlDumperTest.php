<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Database\SqlDumper;
use App\Models\DynamicRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Sicherung in PHP (DB_BACKUP_METHOD=php) für MySQL und MariaDB: Rundreise mit unsichtbarer Hilfsspalte. */
class SqlDumperTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Nur MySQL und MariaDB.');
        }
    }

    public function test_dump_restores_records_and_unique_rule(): void
    {
        $table = $this->service()->createTable(['name' => 'dump_artikel', 'label' => 'Artikel'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_unique' => true],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'text'],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
        foreach ([['A-1', "Schraube „M4“; 'kurz'\nzweite Zeile", '0.15'], ['A-2', 'Grüße – ß, é, 漢字', null], ['A-3', null, '12.50']] as [$nummer, $text, $preis]) {
            DynamicRecord::forTable($table)->create(['nummer' => $nummer, 'bezeichnung' => $text, 'preis' => $preis]);
        }
        $before = DynamicRecord::forTable($table)->newQuery()->orderBy('id')->get(['id', 'nummer', 'bezeichnung', 'preis'])->toArray();

        $namespace = (string) config('dynamic_data.namespace');
        $connection = DB::connection((string) config('dynamic_data.connection'));
        $stream = fopen('php://memory', 'w+b');
        app(SqlDumper::class)->dump($connection, $namespace, $stream);
        rewind($stream);
        $sql = (string) stream_get_contents($stream);

        $this->assertStringContainsString('CREATE TABLE `dump_artikel`', $sql);
        $this->assertMatchesRegularExpression('/INSERT INTO `dump_artikel` \(`id`, `nummer`/', $sql);
        if ($connection->getDriverName() === 'mariadb') {
            $this->assertStringContainsString('INVISIBLE', $sql, 'Hilfsspalte für die Eindeutigkeit im Aufbau …');
        }
        $this->assertDoesNotMatchRegularExpression('/INSERT INTO `dump_artikel` \([^)]*_v`/', $sql, '… aber nicht in den Daten.');

        // Tabelle weg, Sicherung einspielen.
        $connection->statement('DROP TABLE '.$namespace.'.dump_artikel');
        $connection->unprepared($sql);

        $this->assertSame($before, DynamicRecord::forTable($table->fresh())->newQuery()->orderBy('id')->get(['id', 'nummer', 'bezeichnung', 'preis'])->toArray());

        $this->expectException(QueryException::class);
        $connection->table($namespace.'.dump_artikel')->insert(['nummer' => 'A-1']);
    }

    public function test_backup_command_with_php_method(): void
    {
        $directory = sys_get_temp_dir().'/saft-php-backup-'.uniqid();

        try {
            $this->artisan('db:backup', ['--path' => $directory, '--php' => true])
                ->expectsOutputToContain('Sicherung erstellt')
                ->assertSuccessful();

            $files = File::files($directory);
            $this->assertCount(1, $files);
            $sql = File::get($files[0]->getPathname());
            $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `'.config('database.connections.'.config('database.default').'.database').'`', $sql);
            $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `'.config('dynamic_data.namespace').'`', $sql);
            $this->assertStringContainsString('CREATE TABLE `users`', $sql);
            $this->assertSame('600', substr(sprintf('%o', $files[0]->getPerms()), -3));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
