<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\IdentityProvider;
use Illuminate\Support\Str;

/**
 * Lesbare Bezeichnung der im Audit gespeicherten Anmelde-/Abmeldemethode.
 */
final class LoginMethod
{
    /** @var array<string, string>|null */
    private static ?array $providerNames = null;

    public static function label(?string $method): string
    {
        return match (true) {
            $method === null, $method === '' => '—',
            $method === 'password' => __('auth_admin.methods.password'),
            $method === 'local' => __('auth_admin.methods.local'),
            str_starts_with($method, 'sso:') => __('auth_admin.methods.sso', ['name' => self::providerName(Str::after($method, 'sso:'))]),
            str_starts_with($method, 'idp:') => __('auth_admin.methods.idp', ['name' => self::providerName(Str::after($method, 'idp:'))]),
            default => $method,
        };
    }

    private static function providerName(string $slug): string
    {
        self::$providerNames ??= IdentityProvider::query()->pluck('name', 'slug')->all();

        return self::$providerNames[$slug] ?? $slug;
    }
}
