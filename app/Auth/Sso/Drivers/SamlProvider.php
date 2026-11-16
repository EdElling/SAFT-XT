<?php

declare(strict_types=1);

namespace App\Auth\Sso\Drivers;

use DateTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use LightSaml\Credential\KeyHelper;
use LightSaml\Error\LightSamlSecurityException;
use LightSaml\Error\LightSamlValidationException;
use LightSaml\Helper;
use LightSaml\Model\Assertion\Issuer;
use LightSaml\Model\Assertion\NameID;
use LightSaml\Model\Metadata\KeyDescriptor;
use LightSaml\Model\Metadata\SingleLogoutService;
use LightSaml\Model\Protocol\LogoutRequest;
use LightSaml\Model\Protocol\LogoutResponse;
use LightSaml\Model\Protocol\Status;
use LightSaml\Model\XmlDSig\AbstractSignatureReader;
use LightSaml\SamlConstants;
use SocialiteProviders\Saml2\InvalidSignatureException;
use SocialiteProviders\Saml2\Provider as Saml2Provider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Härtet den SAML2-Provider des Pakets:
 * - prüft die Audience Restriction gegen die eigene Entity-ID (fehlt im Paket),
 * - Replay-Schutz atomar und mit begrenzter Laufzeit (Paket speichert IDs unbefristet),
 * - Single Logout mit NameID-Format und SessionIndex sowie geprüften Logout-Nachrichten
 *   (Paket sendet nur die NameID und prüft eingehende Nachrichten nur auf die Signatur).
 */
final class SamlProvider extends Saml2Provider
{
    private const int CLOCK_SKEW = 120;

    private const int MAX_REPLAY_TTL = 86_400;

    private const int DEFAULT_REPLAY_TTL = 3_600;

    protected function validateAssertion(): void
    {
        parent::validateAssertion();

        $restrictions = $this->getFirstAssertion()->getConditions()?->getAllAudienceRestrictions() ?? [];
        $entityId = $this->getServiceProviderEntityId();

        // Jede AudienceRestriction muss erfüllt sein; mindestens eine wird verlangt.
        if ($restrictions === []) {
            throw new LightSamlValidationException('The assertion has no audience restriction');
        }

        foreach ($restrictions as $restriction) {
            if (! $restriction->hasAudience($entityId)) {
                throw new LightSamlValidationException('The assertion audience does not match this service provider');
            }
        }
    }

    protected function validateRepeatedId(): void
    {
        $assertion = $this->getFirstAssertion();
        $key = $this->cacheKey('id_'.hash('sha256', $assertion->getIssuer()?->getValue().'|'.$assertion->getId()));

        // Cache::add ist atomar: nur der erste Request mit dieser Assertion-ID gewinnt.
        if (! Cache::add($key, true, $this->replayTtl())) {
            throw new LightSamlValidationException('The identity provider repeated an assertion id');
        }
    }

    /** So lange merken, wie die Assertion (inkl. Toleranz) gültig sein kann. */
    private function replayTtl(): int
    {
        $assertion = $this->getFirstAssertion();

        $notOnOrAfter = max(
            (int) $assertion->getConditions()?->getNotOnOrAfterTimestamp(),
            (int) $assertion->getSubject()?->getFirstSubjectConfirmation()?->getSubjectConfirmationData()?->getNotOnOrAfterTimestamp(),
        );

        if ($notOnOrAfter === 0) {
            return self::DEFAULT_REPLAY_TTL;
        }

        return max(60, min(self::MAX_REPLAY_TTL, $notOnOrAfter - time() + self::CLOCK_SKEW));
    }

    // --- Single Logout ----------------------------------------------------------------

    /**
     * Signierter LogoutRequest an den IdP (HTTP-Redirect).
     *
     * @return array{0: Response, 1: string} Weiterleitung und ID des LogoutRequest
     */
    public function singleLogoutRequest(string $nameId, ?string $nameIdFormat, ?string $sessionIndex, string $relayState): array
    {
        $request = (new LogoutRequest)
            ->setID(Helper::generateID())
            ->setIssueInstant(new DateTime)
            ->setNotOnOrAfter(new DateTime('+5 minutes'))
            ->setDestination($this->idpSingleLogoutService()->getLocation())
            ->setIssuer(new Issuer($this->getServiceProviderEntityId()))
            ->setNameID(new NameID($nameId, $nameIdFormat))
            ->setSessionIndex($sessionIndex);

        $request->setRelayState($relayState);

        return [$this->sendMessage($request, SamlConstants::BINDING_SAML2_HTTP_REDIRECT), $request->getID()];
    }

    /**
     * Empfängt und prüft eine Logout-Nachricht vom IdP (Signatur, Issuer, Destination, Gültigkeit).
     */
    public function receiveLogoutMessage(): LogoutRequest|LogoutResponse
    {
        $this->receive();
        $message = $this->messageContext->getMessage();

        if (! $message instanceof LogoutRequest && ! $message instanceof LogoutResponse) {
            throw new LightSamlValidationException('Expected a SAML LogoutRequest or LogoutResponse');
        }

        if ($message->getIssuer()?->getValue() !== $this->getIdentityProviderEntityDescriptor()->getEntityID()) {
            throw new LightSamlValidationException('The logout message issuer did not match the configured identity provider');
        }

        if ($message->getDestination() !== null && $message->getDestination() !== $this->singleLogoutServiceUrl()) {
            throw new LightSamlValidationException('The logout message destination did not match this service provider');
        }

        if ($message instanceof LogoutRequest && ($message->getNotOnOrAfterTimestamp() ?? PHP_INT_MAX) < time() - self::CLOCK_SKEW) {
            throw new LightSamlValidationException('The logout request has expired');
        }

        $this->validateLogoutSignature($message);

        return $message;
    }

    /** Signierte LogoutResponse auf einen (geprüften) LogoutRequest des IdP. */
    public function logoutResponseTo(LogoutRequest $request): Response
    {
        $service = $this->idpSingleLogoutService();

        $response = (new LogoutResponse)
            ->setStatus((new Status)->setSuccess())
            ->setID(Helper::generateID())
            ->setInResponseTo($request->getID())
            ->setIssueInstant(new DateTime)
            ->setDestination($service->getResponseLocation() ?: $service->getLocation())
            ->setIssuer(new Issuer($this->getServiceProviderEntityId()));

        $response->setRelayState($request->getRelayState());

        return $this->sendMessage($response, SamlConstants::BINDING_SAML2_HTTP_REDIRECT);
    }

    private function idpSingleLogoutService(): SingleLogoutService
    {
        return $this->getIdentityProviderEntityDescriptor()
            ->getFirstIdpSsoDescriptor()
            ->getFirstSingleLogoutService(SamlConstants::BINDING_SAML2_HTTP_REDIRECT)
            ?? throw new LightSamlValidationException('The identity provider has no HTTP-Redirect single logout service');
    }

    private function singleLogoutServiceUrl(): string
    {
        return URL::to($this->getSingleLogoutServiceRoute());
    }

    /** Logout-Nachrichten müssen immer signiert sein (bei Redirect: Query-Signatur). */
    private function validateLogoutSignature(LogoutRequest|LogoutResponse $message): void
    {
        $signature = $message->getSignature();

        if (! $signature instanceof AbstractSignatureReader) {
            throw new InvalidSignatureException('The logout message is not signed');
        }

        $keyDescriptors = array_filter(
            $this->getIdentityProviderEntityDescriptor()->getFirstIdpSsoDescriptor()->getAllKeyDescriptors() ?? [],
            static fn (KeyDescriptor $descriptor): bool => in_array($descriptor->getUse(), [KeyDescriptor::USE_SIGNING, null], true),
        );

        foreach ($keyDescriptors as $descriptor) {
            try {
                if ($signature->validate(KeyHelper::createPublicKey($descriptor->getCertificate()))) {
                    return;
                }
            } catch (LightSamlSecurityException) {
                continue;
            }
        }

        throw new InvalidSignatureException('The signature of the logout message could not be verified');
    }
}
