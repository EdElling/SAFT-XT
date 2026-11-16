<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\AuthMode;
use App\Settings\AuthSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Notfall-Werkzeug: Anmeldemodus per Konsole setzen (z. B. nach IdP-Fehlkonfiguration).
 */
#[Signature('auth:mode {mode? : local, sso oder hybrid}')]
#[Description('Zeigt oder setzt den Authentifizierungsmodus')]
class AuthModeCommand extends Command
{
    public function handle(AuthSettings $settings): int
    {
        $value = $this->argument('mode');

        if ($value === null) {
            $this->info("Aktueller Modus: {$settings->mode->value}");

            return self::SUCCESS;
        }

        $mode = AuthMode::tryFrom((string) $value);

        if ($mode === null) {
            $this->error('Ungültiger Modus. Erlaubt: '.implode(', ', array_column(AuthMode::cases(), 'value')));

            return self::INVALID;
        }

        $settings->mode = $mode;
        $settings->save();

        $this->info("Modus gesetzt: {$mode->value}");

        return self::SUCCESS;
    }
}
