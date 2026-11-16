<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\Sso\Saml\Certificates;
use DateTime;
use GuzzleHttp\Psr7\HttpFactory;
use LightSaml\Binding\BindingFactory;
use LightSaml\ClaimTypes;
use LightSaml\Context\Model\SerializationContext;
use LightSaml\Context\Profile\MessageContext;
use LightSaml\Credential\KeyHelper;
use LightSaml\Credential\X509Certificate;
use LightSaml\Helper;
use LightSaml\Model\Assertion\Assertion;
use LightSaml\Model\Assertion\Attribute;
use LightSaml\Model\Assertion\AttributeStatement;
use LightSaml\Model\Assertion\AudienceRestriction;
use LightSaml\Model\Assertion\AuthnContext;
use LightSaml\Model\Assertion\AuthnStatement;
use LightSaml\Model\Assertion\Conditions;
use LightSaml\Model\Assertion\Issuer;
use LightSaml\Model\Assertion\NameID;
use LightSaml\Model\Assertion\Subject;
use LightSaml\Model\Assertion\SubjectConfirmation;
use LightSaml\Model\Assertion\SubjectConfirmationData;
use LightSaml\Model\Protocol\LogoutRequest;
use LightSaml\Model\Protocol\LogoutResponse;
use LightSaml\Model\Protocol\Response;
use LightSaml\Model\Protocol\SamlMessage;
use LightSaml\Model\Protocol\Status;
use LightSaml\Model\Protocol\StatusCode;
use LightSaml\Model\XmlDSig\SignatureWriter;
use LightSaml\SamlConstants;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Minimaler Test-IdP: erzeugt signierte SAML-Responses mit eigenem Schlüssel.
 */
final class SamlTestIdp
{
    public const string ENTITY_ID = 'https://idp.test/saml';

    public const string SSO_URL = 'https://idp.test/sso';

    public const string SLO_URL = 'https://idp.test/slo';

    public const string GROUPS_ATTRIBUTE = 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups';

    /** @var array{certificate: string, private_key: string} */
    public readonly array $keyPair;

    public function __construct()
    {
        $this->keyPair = Certificates::generateSelfSigned('idp.test');
    }

    public function certificate(): string
    {
        return $this->keyPair['certificate'];
    }

    /**
     * @param  array{
     *     inResponseTo?: string|null, audience?: string, recipient?: string, nameId?: string,
     *     email?: string, name?: string, groups?: list<string>, notOnOrAfter?: string, issuer?: string, signingKey?: array{certificate: string, private_key: string}
     * }  $options
     */
    public function response(string $acsUrl, string $spEntityId, array $options = []): string
    {
        $notOnOrAfter = new DateTime($options['notOnOrAfter'] ?? '+5 minutes');
        $issuer = $options['issuer'] ?? self::ENTITY_ID;
        $inResponseTo = $options['inResponseTo'] ?? null;

        $confirmationData = (new SubjectConfirmationData)
            ->setNotOnOrAfter($notOnOrAfter)
            ->setRecipient($options['recipient'] ?? $acsUrl);

        if ($inResponseTo !== null) {
            $confirmationData->setInResponseTo($inResponseTo);
        }

        $assertion = (new Assertion)
            ->setId(Helper::generateID())
            ->setIssueInstant((clone $notOnOrAfter)->modify('-5 minutes'))
            ->setIssuer(new Issuer($issuer))
            ->setSubject((new Subject)
                ->setNameID(new NameID($options['nameId'] ?? 'persistent-id-123', SamlConstants::NAME_ID_FORMAT_PERSISTENT))
                ->addSubjectConfirmation((new SubjectConfirmation)
                    ->setMethod(SamlConstants::CONFIRMATION_METHOD_BEARER)
                    ->setSubjectConfirmationData($confirmationData)))
            ->setConditions((new Conditions)
                // Gültigkeitsfenster von 10 Minuten vor NotOnOrAfter (auch bei abgelaufenen Tests konsistent).
                ->setNotBefore((clone $notOnOrAfter)->modify('-10 minutes'))
                ->setNotOnOrAfter($notOnOrAfter)
                ->addItem(new AudienceRestriction([$options['audience'] ?? $spEntityId])))
            ->addItem((new AttributeStatement)
                ->addAttribute(new Attribute(ClaimTypes::EMAIL_ADDRESS, $options['email'] ?? 'max@firma.de'))
                ->addAttribute(new Attribute(ClaimTypes::NAME, $options['name'] ?? 'Max Muster'))
                ->addAttribute(new Attribute(self::GROUPS_ATTRIBUTE, $options['groups'] ?? ['Mitarbeitende'])))
            ->addItem((new AuthnStatement)
                ->setAuthnInstant((clone $notOnOrAfter)->modify('-5 minutes'))
                ->setSessionIndex('_session-'.bin2hex(random_bytes(4)))
                ->setAuthnContext((new AuthnContext)->setAuthnContextClassRef(SamlConstants::AUTHN_CONTEXT_PASSWORD_PROTECTED_TRANSPORT)));

        $signing = $options['signingKey'] ?? $this->keyPair;
        $assertion->setSignature(new SignatureWriter(
            (new X509Certificate)->loadPem($signing['certificate']),
            KeyHelper::createPrivateKey($signing['private_key'], '', false, XMLSecurityKey::RSA_SHA256),
            XMLSecurityDSig::SHA256,
        ));

        $response = (new Response)
            ->setID(Helper::generateID())
            ->setIssueInstant(new DateTime)
            ->setDestination($acsUrl)
            ->setIssuer(new Issuer($issuer))
            ->setStatus(new Status(new StatusCode(SamlConstants::STATUS_SUCCESS)))
            ->addAssertion($assertion);

        if ($inResponseTo !== null) {
            $response->setInResponseTo($inResponseTo);
        }

        $context = new SerializationContext;
        $response->serialize($context->getDocument(), $context);

        return base64_encode((string) $context->getDocument()->saveXML());
    }

    public function metadataXml(): string
    {
        $body = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n"], '', $this->certificate()));
        $entity = self::ENTITY_ID;
        $sso = self::SSO_URL;
        $slo = self::SLO_URL;

        return <<<XML
            <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="{$entity}">
              <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
                <md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>{$body}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>
                <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$slo}"/>
                <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$sso}"/>
              </md:IDPSSODescriptor>
            </md:EntityDescriptor>
            XML;
    }

    /**
     * Vom IdP ausgelöster LogoutRequest als Redirect-URL an den SLS.
     *
     * @param  array{certificate: string, private_key: string}|null  $signingKey  null = eigener Schlüssel, false-artig unsigniert via $signed
     */
    public function logoutRequestUrl(string $slsUrl, string $nameId, ?array $signingKey = null, bool $signed = true): string
    {
        $request = (new LogoutRequest)
            ->setID(Helper::generateID())
            ->setIssueInstant(new DateTime)
            ->setNotOnOrAfter(new DateTime('+5 minutes'))
            ->setDestination($slsUrl)
            ->setIssuer(new Issuer(self::ENTITY_ID))
            ->setNameID(new NameID($nameId, SamlConstants::NAME_ID_FORMAT_PERSISTENT))
            ->setSessionIndex('_session-x');

        return $this->redirectUrl($request, $signed ? ($signingKey ?? $this->keyPair) : null);
    }

    /** LogoutResponse des IdP auf unseren LogoutRequest als Redirect-URL an den SLS. */
    public function logoutResponseUrl(string $slsUrl, string $inResponseTo, ?string $relayState): string
    {
        $response = (new LogoutResponse)
            ->setStatus((new Status)->setSuccess())
            ->setID(Helper::generateID())
            ->setInResponseTo($inResponseTo)
            ->setIssueInstant(new DateTime)
            ->setDestination($slsUrl)
            ->setIssuer(new Issuer(self::ENTITY_ID));

        $response->setRelayState($relayState);

        return $this->redirectUrl($response, $this->keyPair);
    }

    /**
     * @param  array{certificate: string, private_key: string}|null  $signingKey
     */
    private function redirectUrl(SamlMessage $message, ?array $signingKey): string
    {
        if ($signingKey !== null) {
            $message->setSignature(new SignatureWriter(
                (new X509Certificate)->loadPem($signingKey['certificate']),
                KeyHelper::createPrivateKey($signingKey['private_key'], '', false, XMLSecurityKey::RSA_SHA256),
                XMLSecurityDSig::SHA256,
            ));
        }

        $context = new MessageContext;
        $context->setMessage($message);
        $psr17 = new HttpFactory;

        $response = (new BindingFactory(null, $psr17, $psr17))
            ->create(SamlConstants::BINDING_SAML2_HTTP_REDIRECT)
            ->send($context);

        return $response->getHeaderLine('Location');
    }
}
