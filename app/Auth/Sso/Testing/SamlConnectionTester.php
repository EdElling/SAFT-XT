<?php

declare(strict_types=1);

namespace App\Auth\Sso\Testing;

use App\Auth\Sso\Saml\Certificates;
use App\Auth\Sso\Saml\IdpMetadata;
use App\Models\IdentityProvider;
use App\Support\AppLocale;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Prüft IdP-Metadaten (URL) bzw. manuelle IdP-Angaben, Zertifikatsgültigkeit
 * und die Zusammengehörigkeit von SP-Zertifikat und SP-Schlüssel.
 */
final class SamlConnectionTester implements ConnectionTester
{
    public function test(IdentityProvider $provider): ConnectionTestResult
    {
        $spCheck = $this->checkSpKeyPair($provider);

        if ($spCheck !== null) {
            return $spCheck;
        }

        $metadataUrl = $provider->setting('idp_metadata_url');

        return filled($metadataUrl)
            ? $this->testMetadataUrl((string) $metadataUrl)
            : $this->testManual($provider);
    }

    private function testMetadataUrl(string $url): ConnectionTestResult
    {
        try {
            $response = Http::timeout(10)->get($url);
        } catch (ConnectionException $e) {
            return ConnectionTestResult::failed(__('auth_admin.test.unreachable', ['message' => $e->getMessage()]));
        }

        if (! $response->successful()) {
            return ConnectionTestResult::failed(__('auth_admin.test.http_error', ['status' => $response->status()]));
        }

        try {
            $metadata = IdpMetadata::parse($response->body());
        } catch (InvalidArgumentException $e) {
            return ConnectionTestResult::failed(__('auth_admin.test.saml_invalid_metadata', ['message' => $e->getMessage()]));
        }

        return $this->checkCertificate($metadata->certificate)
            ?? ConnectionTestResult::ok(__('auth_admin.test.saml_metadata_ok', [
                'entity' => $metadata->entityId,
                'until' => AppLocale::date(Certificates::validTo($metadata->certificate)),
            ]));
    }

    private function testManual(IdentityProvider $provider): ConnectionTestResult
    {
        $certificate = (string) $provider->setting('idp_certificate');

        if (blank($provider->setting('idp_entity_id')) || blank($provider->setting('idp_sso_url')) || $certificate === '') {
            return ConnectionTestResult::failed(__('auth_admin.test.saml_missing'));
        }

        return $this->checkCertificate($certificate)
            ?? ConnectionTestResult::ok(__('auth_admin.test.saml_manual_ok', [
                'until' => AppLocale::date(Certificates::validTo($certificate)),
            ]));
    }

    private function checkCertificate(string $certificate): ?ConnectionTestResult
    {
        $validTo = Certificates::validTo($certificate);

        if ($validTo === null) {
            return ConnectionTestResult::failed(__('auth_admin.test.saml_cert_invalid'));
        }

        if ($validTo->isPast()) {
            return ConnectionTestResult::failed(__('auth_admin.test.saml_cert_expired', ['date' => AppLocale::date($validTo)]));
        }

        return null;
    }

    private function checkSpKeyPair(IdentityProvider $provider): ?ConnectionTestResult
    {
        $certificate = $provider->setting('sp_certificate');
        $key = $provider->secret('sp_private_key');

        if (! $provider->setting('sign_requests') || blank($certificate) || $key === null) {
            return null;
        }

        return Certificates::matchesPrivateKey((string) $certificate, $key)
            ? null
            : ConnectionTestResult::failed(__('auth_admin.test.saml_sp_key_mismatch'));
    }
}
