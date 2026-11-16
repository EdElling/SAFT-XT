<?php

declare(strict_types=1);

namespace App\Auth\Sso\Testing;

use App\Models\IdentityProvider;

interface ConnectionTester
{
    public function test(IdentityProvider $provider): ConnectionTestResult;
}
