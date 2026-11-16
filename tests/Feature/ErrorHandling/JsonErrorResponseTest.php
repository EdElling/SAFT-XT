<?php

declare(strict_types=1);

namespace Tests\Feature\ErrorHandling;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ErrorPresenter;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class JsonErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->app->forgetInstance(ErrorPresenter::class);

        Route::prefix('api/test')->group(function (): void {
            Route::get('not-found', fn () => User::query()->findOrFail(PHP_INT_MAX));
            Route::get('forbidden', fn () => throw new AuthorizationException);
            Route::post('validation', fn () => request()->validate(['email' => ['required', 'email']]));
            Route::get('business', fn () => throw new BusinessRuleException('Regel verletzt.', 'test.rule'));
            Route::get('fk', fn () => DB::transaction(fn () => Menu::create(['label' => 'x', 'parent_id' => 999_999])));
            Route::get('unique', function () {
                $email = User::factory()->create()->email;

                return DB::transaction(fn () => User::factory()->create(['email' => $email]));
            });
            Route::get('boom', fn () => throw new RuntimeException('SELECT secret FROM vault'));
        });

        Route::get('test/boom-html', fn () => throw new RuntimeException('SELECT secret FROM vault'));
    }

    public function test_not_found(): void
    {
        $this->getJson('/api/test/not-found')
            ->assertNotFound()
            ->assertExactJson(['error' => [
                'code' => 'http.404',
                'title' => __('errors.titles.404'),
                'message' => __('errors.http.404'),
                'status' => 404,
            ]]);
    }

    public function test_forbidden(): void
    {
        $this->getJson('/api/test/forbidden')->assertForbidden()->assertJsonPath('error.code', 'http.403');
    }

    public function test_validation_uses_standard_structure_with_fields(): void
    {
        $this->postJson('/api/test/validation', ['email' => 'kein-email'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation')
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_business_rule(): void
    {
        $this->getJson('/api/test/business')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'test.rule')
            ->assertJsonPath('error.message', 'Regel verletzt.')
            ->assertJsonMissingPath('error.reference');
    }

    public function test_foreign_key_violation_is_conflict_with_reference(): void
    {
        $this->getJson('/api/test/fk')
            ->assertConflict()
            ->assertJsonPath('error.code', 'db.foreign_key_violation')
            ->assertJsonStructure(['error' => ['reference']]);
    }

    public function test_unique_violation_names_field(): void
    {
        $this->getJson('/api/test/unique')
            ->assertConflict()
            ->assertJsonPath('error.code', 'db.unique_violation')
            ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, '„E-Mail“'));
    }

    public function test_unexpected_error_hides_details_and_reference_matches_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        $response = $this->getJson('/api/test/boom')->assertStatus(500);

        $reference = $response->json('error.reference');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', (string) $reference);
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $response->assertJsonMissingPath('error.debug');

        $errorLog = collect($logged)->firstWhere('level', 'error');
        $this->assertNotNull($errorLog);
        $this->assertSame($reference, $errorLog->context['error_reference'] ?? null);
    }

    public function test_expected_db_conflicts_are_logged_as_warning(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->level;
        });

        $this->getJson('/api/test/fk')->assertConflict();

        $this->assertContains('warning', $logged);
        $this->assertNotContains('error', $logged);
    }

    public function test_debug_mode_adds_technical_details(): void
    {
        config(['app.debug' => true]);
        $this->app->forgetInstance(ErrorPresenter::class);

        $this->getJson('/api/test/boom')
            ->assertStatus(500)
            ->assertJsonPath('error.debug.exception', RuntimeException::class);
    }

    public function test_html_error_page_is_german_and_shows_reference(): void
    {
        $this->get('/test/boom-html')
            ->assertStatus(500)
            ->assertSee(__('errors.titles.500'))
            ->assertSee('Referenz:')
            ->assertDontSee('secret');
    }
}
