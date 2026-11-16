<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\TwoFactor;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Notfall-Werkzeug: zweiten Faktor eines Kontos zurücksetzen, z. B. wenn die einzige Administratorin ihr Handy verliert. */
#[Signature('auth:two-factor-reset {email : E-Mail-Adresse des Kontos}')]
#[Description('Setzt die Zwei-Faktor-Anmeldung eines Kontos zurück')]
class TwoFactorResetCommand extends Command
{
    public function handle(TwoFactor $twoFactor): int
    {
        $email = User::normalizeEmail((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Kein Konto mit der Adresse {$email}.");

            return self::FAILURE;
        }

        if (! $twoFactor->isSetUp($user)) {
            $this->info("{$email} hat keine Zwei-Faktor-Anmeldung eingerichtet.");

            return self::SUCCESS;
        }

        $twoFactor->reset($user);
        $this->info("Zwei-Faktor-Anmeldung für {$email} zurückgesetzt. Nach der nächsten Anmeldung neu einrichten.");

        return self::SUCCESS;
    }
}
