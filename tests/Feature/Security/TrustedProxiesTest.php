<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Tests\TestCase;

/** HTTPS endet beim Proxy des Hosters: Links nur dann mit https://, wenn SAFT dem Proxy vertraut. */
class TrustedProxiesTest extends TestCase
{
    private const array FORWARDED = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '203.0.113.5',
        'X-Forwarded-Host' => 'saft.example.ch',
        'X-Forwarded-Port' => '443',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Symfony merkt sich vertrauenswürdige Proxys statisch; im Betrieb ist jede Anfrage ein neuer Prozess.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    private function redirectFromProxy(string $proxy): string
    {
        return (string) $this->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeaders(self::FORWARDED)
            ->get('/admin')
            ->headers->get('Location');
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertStringStartsWith('http://localhost', $this->redirectFromProxy('10.0.0.5'));
    }

    public function test_configured_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8, 192.168.0.0/16']);

        $this->assertSame('https://saft.example.ch/admin/login', $this->redirectFromProxy('10.0.0.5'));
    }

    public function test_other_senders_are_not_treated_as_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8, 192.168.0.0/16']);

        $this->assertStringStartsWith('http://localhost', $this->redirectFromProxy('198.51.100.7'));
    }

    public function test_wildcard_trusts_the_calling_machine(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->assertSame('https://saft.example.ch/admin/login', $this->redirectFromProxy('172.16.4.2'));
    }

    public function test_environment_value_is_parsed(): void
    {
        foreach (['' => null, '  ' => null, '10.0.0.5' => '10.0.0.5', '*' => '*'] as $value => $expected) {
            putenv("TRUSTED_PROXIES={$value}");
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;

            $this->assertSame($expected, (require config_path('trustedproxy.php'))['proxies'], "'{$value}'");
        }

        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    }
}
