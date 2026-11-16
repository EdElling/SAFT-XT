<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DynamicData\SchemaVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app-data:verify')]
#[Description('Prüft, ob Metadaten des Table-Designers und Tabellen im Namensraum app_data übereinstimmen')]
class VerifyAppDataCommand extends Command
{
    public function handle(SchemaVerifier $verifier): int
    {
        $issues = $verifier->issues();

        if ($issues === []) {
            $this->info('Metadaten und Datenbank sind konsistent.');

            return self::SUCCESS;
        }

        $this->error(count($issues).' Abweichung(en) gefunden:');

        foreach ($issues as $issue) {
            $this->line(' - '.$issue);
        }

        return self::FAILURE;
    }
}
