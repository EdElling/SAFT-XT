<?php

declare(strict_types=1);

namespace App\Auth\Sso;

/**
 * Normalisierte Identität aus einem IdP-Login (Entra, SAML, …).
 */
final readonly class SsoIdentity
{
    /**
     * @param  array<string, mixed>  $attributes  Rohdaten des IdP (Claims/Attribute)
     * @param  list<string>|null  $roleValues  Externe Werte für das Rollen-Mapping (Gruppen,
     *                                         App-Rollen, Attributwerte); null = nicht verfügbar
     *                                         (z. B. Gruppen-Überlauf) -> kein Abgleich
     */
    public function __construct(
        public string $providerUserId,
        public ?string $email,
        public ?string $name,
        public array $attributes = [],
        public ?string $token = null,
        public ?string $refreshToken = null,
        public ?\DateTimeInterface $tokenExpiresAt = null,
        public ?array $roleValues = null,
    ) {}

    public function normalizedEmail(): ?string
    {
        return $this->email === null || trim($this->email) === '' ? null : mb_strtolower(trim($this->email));
    }

    public function emailDomain(): ?string
    {
        $email = $this->normalizedEmail();

        return $email !== null && str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : null;
    }
}
