<?php

declare(strict_types=1);

namespace App\Auth\Sso\Saml;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Hilfsfunktionen für X.509-Zertifikate (IdP-Zertifikat prüfen, SP-Schlüsselpaar erzeugen).
 */
final class Certificates
{
    /** Akzeptiert PEM oder rohes Base64 (wie in SAML-Metadaten) und liefert PEM. */
    public static function toPem(string $certificate): string
    {
        $certificate = trim($certificate);

        if (str_contains($certificate, '-----BEGIN CERTIFICATE-----')) {
            return $certificate;
        }

        $body = preg_replace('/\s+/', '', $certificate) ?? '';

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n").'-----END CERTIFICATE-----';
    }

    /** Ablaufdatum oder null, wenn das Zertifikat nicht lesbar ist. */
    public static function validTo(string $certificate): ?CarbonImmutable
    {
        $parsed = @openssl_x509_parse(self::toPem($certificate));

        return is_array($parsed) && isset($parsed['validTo_time_t'])
            ? CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t'])
            : null;
    }

    public static function matchesPrivateKey(string $certificate, string $privateKey): bool
    {
        return @openssl_x509_check_private_key(self::toPem($certificate), $privateKey) === true;
    }

    /**
     * Selbstsigniertes SP-Zertifikat (RSA 2048, SHA-256) zum Signieren von AuthnRequests.
     *
     * @return array{certificate: string, private_key: string}
     */
    public static function generateSelfSigned(string $commonName, int $validDays = 3650): array
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

        $key = openssl_pkey_new($config);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => mb_substr($commonName, 0, 64)], $key, $config);
        $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, $validDays, $config, random_int(1, PHP_INT_MAX));

        if ($key === false || $cert === false || ! openssl_x509_export($cert, $certificatePem) || ! openssl_pkey_export($key, $privateKeyPem)) {
            throw new RuntimeException('SP-Zertifikat konnte nicht erzeugt werden: '.openssl_error_string());
        }

        return ['certificate' => $certificatePem, 'private_key' => $privateKeyPem];
    }
}
