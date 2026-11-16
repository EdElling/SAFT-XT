<?php

declare(strict_types=1);

namespace App\Auth\Sso;

/**
 * SSO-Kontext einer Anmeldung (in der Session gespeichert) – Grundlage für Single Logout.
 */
final readonly class SsoSession
{
    public const string SESSION_KEY = 'sso_session';

    public function __construct(
        public int $identityProviderId,
        public string $identityProviderSlug,
        public string $nameId,
        public ?string $nameIdFormat = null,
        public ?string $sessionIndex = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! isset($data['identity_provider_id'], $data['identity_provider_slug'], $data['name_id'])) {
            return null;
        }

        return new self(
            (int) $data['identity_provider_id'],
            (string) $data['identity_provider_slug'],
            (string) $data['name_id'],
            isset($data['name_id_format']) ? (string) $data['name_id_format'] : null,
            isset($data['session_index']) ? (string) $data['session_index'] : null,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'identity_provider_id' => $this->identityProviderId,
            'identity_provider_slug' => $this->identityProviderSlug,
            'name_id' => $this->nameId,
            'name_id_format' => $this->nameIdFormat,
            'session_index' => $this->sessionIndex,
        ];
    }
}
