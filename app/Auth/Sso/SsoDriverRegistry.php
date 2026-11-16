<?php

declare(strict_types=1);

namespace App\Auth\Sso;

use Illuminate\Contracts\Container\Container;

/**
 * Zuordnung IdP-Typ -> Treiberklasse. Treiber werden im Service-Provider registriert.
 */
final class SsoDriverRegistry
{
    /** @var array<string, class-string<SsoDriver>> */
    private array $drivers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<SsoDriver>  $driver
     */
    public function register(IdentityProviderType $type, string $driver): void
    {
        $this->drivers[$type->value] = $driver;
    }

    public function has(IdentityProviderType $type): bool
    {
        return isset($this->drivers[$type->value]);
    }

    public function for(IdentityProviderType $type): ?SsoDriver
    {
        return $this->has($type) ? $this->container->make($this->drivers[$type->value]) : null;
    }
}
