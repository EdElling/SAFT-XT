<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Str;

/**
 * Protokolliert fehlgeschlagene Anmeldungen (versuchte E-Mail und IP).
 * Das Passwort wird bewusst nie übernommen – nur der Schlüssel "email".
 */
class RecordFailedLogin
{
    public function handle(Failed $event): void
    {
        $email = Str::limit(mb_strtolower(trim((string) ($event->credentials['email'] ?? ''))), 255, '');

        $logger = activity('auth')
            ->event('login_failed')
            ->withProperties([
                'email' => $email !== '' ? $email : null,
                'ip' => request()->ip(),
            ]);

        if ($event->user instanceof User) {
            $logger->performedOn($event->user);
        }

        $logger->log('login_failed');
    }
}
