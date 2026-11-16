<?php

declare(strict_types=1);

namespace App\Auth\Sso;

enum IdentityProviderType: string
{
    case Entra = 'entra';
    case Saml = 'saml';

    public function label(): string
    {
        return __("auth_admin.types.{$this->value}");
    }

    /**
     * Schlüssel innerhalb von `secrets` (verschlüsselt, nie im Formular vorbefüllt).
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return match ($this) {
            self::Entra => ['client_secret'],
            self::Saml => ['sp_private_key'],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t): array => [$t->value => $t->label()])->all();
    }
}
