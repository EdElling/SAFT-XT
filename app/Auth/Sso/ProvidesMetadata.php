<?php

declare(strict_types=1);

namespace App\Auth\Sso;

use App\Models\IdentityProvider;
use Symfony\Component\HttpFoundation\Response;

/** Treiber, die SP-Metadaten bereitstellen (SAML). */
interface ProvidesMetadata
{
    public function metadata(IdentityProvider $provider): Response;
}
