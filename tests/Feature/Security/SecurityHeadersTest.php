<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use Tests\TestCase;

/** Sicherheits-Header auf allen Antworten; HSTS nur über HTTPS und abschaltbar. */
class SecurityHeadersTest extends TestCase
{
    public function test_login_page_has_security_headers_but_no_hsts_over_http(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_over_https_and_switchable(): void
    {
        $this->get('https://localhost/admin/login')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);

        config(['saft.hsts' => false]);
        $this->get('https://localhost/admin/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_error_pages_and_guest_redirects_have_headers_too(): void
    {
        $this->get('/gibt-es-nicht')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/admin')->assertRedirect()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
