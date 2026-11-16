<?php

declare(strict_types=1);

namespace App\Auth\Sso\Saml;

use Closure;
use Symfony\Component\HttpFoundation\Response;

/**
 * Geprüfte Logout-Nachricht des IdP: Antwort auf unseren LogoutRequest oder
 * vom IdP ausgelöster LogoutRequest (mit Möglichkeit zu antworten).
 */
final readonly class SamlLogoutMessage
{
    /**
     * @param  Closure(): Response|null  $respond
     */
    private function __construct(
        public bool $isRequest,
        public ?string $nameId,
        public ?string $inResponseTo,
        public bool $successful,
        private ?Closure $respond,
    ) {}

    /**
     * @param  Closure(): Response  $respond
     */
    public static function request(string $nameId, Closure $respond): self
    {
        return new self(true, $nameId, null, true, $respond);
    }

    public static function response(?string $inResponseTo, bool $successful): self
    {
        return new self(false, null, $inResponseTo, $successful, null);
    }

    /** Signierte LogoutResponse an den IdP (nur für LogoutRequests). */
    public function respond(): Response
    {
        return ($this->respond ?? throw new \LogicException('Nur LogoutRequests können beantwortet werden.'))();
    }
}
