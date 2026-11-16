<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Sso\Saml\Certificates;
use App\Auth\Sso\Saml\IdpMetadata;
use InvalidArgumentException;
use Tests\TestCase;

class IdpMetadataTest extends TestCase
{
    public function test_parses_entity_sso_url_and_certificate(): void
    {
        $xml = <<<'XML'
            <EntitiesDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata">
              <EntityDescriptor entityID="https://idp.example.com" xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
                <IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
                  <KeyDescriptor use="encryption"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>ENC</ds:X509Certificate></ds:X509Data></ds:KeyInfo></KeyDescriptor>
                  <KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>SIGN</ds:X509Certificate></ds:X509Data></ds:KeyInfo></KeyDescriptor>
                  <SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="https://idp.example.com/post"/>
                  <SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example.com/redirect"/>
                </IDPSSODescriptor>
              </EntityDescriptor>
            </EntitiesDescriptor>
            XML;

        $metadata = IdpMetadata::parse($xml);

        $this->assertSame('https://idp.example.com', $metadata->entityId);
        $this->assertSame('https://idp.example.com/redirect', $metadata->ssoUrl);
        $this->assertStringContainsString('SIGN', $metadata->certificate);
    }

    public function test_rejects_external_entities(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>';

        $this->expectException(InvalidArgumentException::class);
        IdpMetadata::parse($xml);
    }

    public function test_certificate_helpers(): void
    {
        $pair = Certificates::generateSelfSigned('sp.example.com');

        $this->assertTrue(Certificates::validTo($pair['certificate'])?->isFuture());
        $this->assertTrue(Certificates::matchesPrivateKey($pair['certificate'], $pair['private_key']));

        $body = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $pair['certificate']));
        $this->assertNotNull(Certificates::validTo($body), 'Rohes Base64 muss akzeptiert werden.');
        $this->assertNull(Certificates::validTo('kein zertifikat'));
    }
}
