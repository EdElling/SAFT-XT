<?php

declare(strict_types=1);

namespace App\Auth\Sso\Drivers;

use App\Auth\Sso\Testing\EntraConnectionTester;
use LogicException;
use SocialiteProviders\Azure\Provider as AzureProvider;

/**
 * Azure-Provider mit konfigurierbarem Authority-Host (nationale Clouds) und ohne
 * stillen Fallback auf den mandantenübergreifenden Endpunkt "common".
 */
final class EntraProvider extends AzureProvider
{
    /** @var array<string, mixed> */
    private array $tokenResponse = [];

    /**
     * Merkt sich die Token-Antwort (enthält das ID-Token mit Rollen-/Gruppen-Claims),
     * die das Paket sonst verwirft.
     */
    public function getAccessTokenResponse($code)
    {
        $response = parent::getAccessTokenResponse($code);
        $this->tokenResponse = is_array($response) ? $response : [];

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastTokenResponse(): array
    {
        return $this->tokenResponse;
    }

    protected function getBaseUrl(): string
    {
        $tenant = (string) $this->getConfig('tenant', '');

        if ($tenant === '') {
            throw new LogicException('Entra-Tenant ist nicht konfiguriert.');
        }

        $host = (string) $this->getConfig('authority_host', EntraConnectionTester::DEFAULT_AUTHORITY);

        return 'https://'.$host.'/'.rawurlencode($tenant);
    }

    public static function additionalConfigKeys(): array
    {
        return [...parent::additionalConfigKeys(), 'authority_host'];
    }
}
