<?php

declare(strict_types=1);

/*
 * Hilfsprozess für NumberSequenceConcurrencyTest (B-135): legt in einer Tabelle mit Nummernkreis-Feld Datensätze an,
 * gleichzeitig mit anderen Prozessen. Startet erst zum gemeinsamen Zeitpunkt, damit sich die Prozesse überlappen.
 * Aufruf: php create-numbered-records.php <tabelle> <anzahl> <start-unix-zeit-mit-bruchteil>
 * Die Umgebung (Testdatenbank) erbt der Prozess vom Test.
 */

use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestDatabaseGuard;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Nie eine andere als eine Testdatenbank anfassen (die Umgebung kommt vom Test).
TestDatabaseGuard::check();

[, $table, $count, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

$writer = app(RecordWriter::class);

try {
    for ($i = 1; $i <= (int) $count; $i++) {
        // Ohne Protokoll und Plugin-Ereignisse: nur die Vergabe zählt.
        $writer->create($table, ['titel' => getmypid().'-'.$i], WriteOptions::seed());
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}

echo 'ok '.DB::connection()->getDatabaseName().' '.config('dynamic_data.namespace');
