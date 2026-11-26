<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\ServerTiming;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Header „Server-Timing“ nur mit SERVER_TIMING=true, auf Seiten und Livewire-Anfragen. */
class ServerTimingTest extends TestCase
{
    public function test_no_header_when_switched_off(): void
    {
        config(['saft.server_timing' => false]);

        $this->get('/admin/login')->assertOk()->assertHeaderMissing('Server-Timing');
    }

    public function test_header_lists_database_and_application_time(): void
    {
        config(['saft.server_timing' => true]);
        $this->actingAs(User::factory()->create());

        $header = (string) $this->get('/admin')->assertOk()->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/(^|, )db;dur=[\d.]+;desc="\d+ quer(y|ies)"/', $header);
        $this->assertMatchesRegularExpression('/(^|, )app;dur=[\d.]+/', $header);
        $this->assertMatchesRegularExpression('/(^|, )total;dur=[\d.]+/', $header);
        $this->assertGreaterThan(0, ServerTiming::queries(), 'Session, Benutzer und Menü brauchen Abfragen');
        $this->assertStringContainsString('desc="'.ServerTiming::queries().' quer', $header);
    }

    public function test_counts_the_queries_of_each_request_separately(): void
    {
        config(['saft.server_timing' => true]);
        $this->app['router']->get('/_server-timing-probe', function () {
            DB::select('select 1');
            DB::select('select 2');

            return 'ok';
        });

        $this->get('/_server-timing-probe')->assertOk()->assertHeader('Server-Timing');
        $this->assertSame(2, ServerTiming::queries());

        $this->get('/_server-timing-probe')->assertOk();
        $this->assertSame(2, ServerTiming::queries(), 'Zähler beginnt je Request bei null');
    }

    public function test_livewire_requests_get_the_header(): void
    {
        config(['saft.server_timing' => true]);

        $response = $this->postJson(Livewire::getUpdateUri(), ['components' => []], ['X-Livewire' => '1']);

        $this->assertTrue($response->headers->has('Server-Timing'), 'Status '.$response->getStatusCode());
    }
}
