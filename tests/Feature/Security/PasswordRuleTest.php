<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Tests\TestCase;

/** B-056: Passwörter mit mindestens 12 Zeichen, auf Wunsch ohne Treffer in bekannten Datenlecks. */
class PasswordRuleTest extends TestCase
{
    use RefreshDatabase;

    private function passes(string $password): bool
    {
        return Validator::make(['password' => $password], ['password' => Password::defaults()])->passes();
    }

    public function test_at_least_twelve_characters(): void
    {
        $this->assertFalse($this->passes('Kurz-123456'), '11 Zeichen');
        $this->assertTrue($this->passes('Lang-1234567'), '12 Zeichen');
    }

    public function test_leaked_passwords_are_rejected_when_switched_on(): void
    {
        config(['saft.password_check_leaked' => true]);
        $leaked = 'password1234';
        // Die API bekommt nur die ersten 5 Zeichen des SHA-1-Hashes und liefert passende Enden mit Anzahl.
        $hash = strtoupper(sha1($leaked));
        Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":4242\r\n0000000000000000000000000000000000A:1")]);

        $this->assertFalse($this->passes($leaked));
        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), $leaked) && str_ends_with($request->url(), '/range/'.substr($hash, 0, 5)));
    }

    public function test_leak_check_is_off_when_switched_off(): void
    {
        config(['saft.password_check_leaked' => false]);
        Http::fake();

        $this->assertTrue($this->passes('password1234'));
        Http::assertNothingSent();
    }

    public function test_weak_admin_password_in_env_stops_the_seeder(): void
    {
        $before = [$_SERVER['ADMIN_PASSWORD'] ?? null, $_ENV['ADMIN_PASSWORD'] ?? null];
        $_SERVER['ADMIN_PASSWORD'] = $_ENV['ADMIN_PASSWORD'] = 'geheim';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/^ADMIN_PASSWORD: /');
            $this->seed(DatabaseSeeder::class);
        } finally {
            [$_SERVER['ADMIN_PASSWORD'], $_ENV['ADMIN_PASSWORD']] = $before;
        }
    }
}
