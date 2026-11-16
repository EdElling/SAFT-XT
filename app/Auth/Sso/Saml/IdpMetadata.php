<?php

declare(strict_types=1);

namespace App\Auth\Sso\Saml;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

/**
 * Minimaler, sicherer Parser für SAML-IdP-Metadaten (EntityDescriptor).
 */
final readonly class IdpMetadata
{
    private const string MD = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const string DS = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(
        public string $entityId,
        public string $ssoUrl,
        public string $certificate,
        public ?string $sloUrl = null,
    ) {}

    public static function parse(string $xml): self
    {
        $dom = new DOMDocument;

        // Kein Netzwerkzugriff, keine externen Entities (XXE).
        if (trim($xml) === '' || ! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new InvalidArgumentException(__('idp.metadata.invalid_xml'));
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('md', self::MD);
        $xpath->registerNamespace('ds', self::DS);

        $entity = $xpath->query('//md:EntityDescriptor')->item(0);
        $idp = $xpath->query('//md:EntityDescriptor/md:IDPSSODescriptor')->item(0);

        if ($entity === null || $idp === null) {
            throw new InvalidArgumentException(__('idp.metadata.missing_descriptor'));
        }

        $sso = self::location($xpath, 'md:SingleSignOnService', $idp);
        $cert = $xpath->query('md:KeyDescriptor[not(@use) or @use="signing"]//ds:X509Certificate', $idp)->item(0)?->textContent;

        if ($sso === null || $cert === null || trim($cert) === '') {
            throw new InvalidArgumentException(__('idp.metadata.missing_sso'));
        }

        return new self(
            entityId: (string) $entity->attributes?->getNamedItem('entityID')?->nodeValue,
            ssoUrl: $sso,
            certificate: Certificates::toPem($cert),
            sloUrl: self::location($xpath, 'md:SingleLogoutService', $idp),
        );
    }

    /** Bevorzugt HTTP-Redirect-Binding, sonst den ersten Eintrag. */
    private static function location(DOMXPath $xpath, string $element, \DOMNode $context): ?string
    {
        $nodes = $xpath->query($element, $context);
        $fallback = null;

        foreach ($nodes as $node) {
            $location = $node->attributes?->getNamedItem('Location')?->nodeValue;
            $binding = $node->attributes?->getNamedItem('Binding')?->nodeValue;

            if ($binding === 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect') {
                return $location;
            }

            $fallback ??= $location;
        }

        return $fallback;
    }
}
