<?php

declare(strict_types=1);

namespace App\Auth;

/** Wer die Zwei-Faktor-Anmeldung einrichten muss (AuthSettings::$two_factor). */
enum TwoFactorMode: string
{
    case Optional = 'optional';
    case Administration = 'administration';
    case Everyone = 'everyone';

    public function label(): string
    {
        return __("auth_admin.two_factor_modes.{$this->value}.label");
    }

    public function description(): string
    {
        return __("auth_admin.two_factor_modes.{$this->value}.description");
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])->all();
    }
}
