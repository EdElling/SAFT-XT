<?php

declare(strict_types=1);

namespace App\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authenticator-App von Filament mit korrigierter Pflicht bei der Anmeldung: Filament macht das Codefeld zur Pflicht,
 * solange das Feld für den Wiederherstellungscode leer ist – beim Anzeigen also immer. Der Browser verweigert dann das
 * Absenden eines Wiederherstellungscodes („Fülle dieses Feld aus“). Hier gilt: Code oder – nach „Stattdessen einen
 * Wiederherstellungscode verwenden“ – Wiederherstellungscode ist Pflicht. Leer absenden geht nie durch, und jeder
 * eingegebene Wert wird weiterhin von Filament geprüft.
 */
class SaftAppAuthentication extends AppAuthentication
{
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $components = parent::getChallengeFormComponents($user);
        $recoverable = $this->isRecoverable();

        foreach ($components as $component) {
            if ($component instanceof OneTimeCodeInput && $component->getName() === 'code') {
                $component->required(fn (Get $get): bool => ! $recoverable || ! $get('useRecoveryCode'));
            }

            if ($component instanceof TextInput && $component->getName() === 'recoveryCode') {
                $component->required(fn (Get $get): bool => $recoverable && (bool) $get('useRecoveryCode') && blank($get('code')));
            }
        }

        return $components;
    }
}
