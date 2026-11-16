<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\AppSettingsPage;
use App\Models\Activity;
use App\Providers\BrandingServiceProvider;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class AppSettingsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    public function test_default_brand_name_replaces_laravel(): void
    {
        (new BrandingServiceProvider($this->app))->boot();

        $this->assertSame('SAFT XT', config('app.name'));
        $this->assertSame('SAFT XT', config('mail.from.name'));

        $this->actingAs($this->superAdmin())
            ->get('/admin')
            ->assertOk()
            ->assertSee('SAFT XT')
            ->assertDontSee('Laravel');
    }

    public function test_page_requires_permissions(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view']));
        $this->get('/admin/app-settings')->assertForbidden();

        $this->actingAs($this->userWithPermissions(['settings.app.view']));
        $this->get('/admin/app-settings')->assertOk();
    }

    public function test_viewer_cannot_change_settings(): void
    {
        $this->actingAs($this->userWithPermissions(['settings.app.view']));

        Livewire::test(AppSettingsPage::class)->fillForm(['app_name' => 'Gehackt'])->call('save');

        $this->assertSame('SAFT XT', app(AppSettings::class)->refresh()->app_name);
    }

    public function test_change_is_saved_audited_and_applied_without_logging_out(): void
    {
        $this->actingAs($this->superAdmin());
        $cookieBefore = config('session.cookie');

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['app_name' => 'Auftragsportal'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Auftragsportal', app(AppSettings::class)->refresh()->app_name);
        $this->assertSame('Auftragsportal', config('app.name'));
        $this->assertSame($cookieBefore, config('session.cookie'), 'Session-Cookie darf sich nicht ändern.');

        $log = Activity::query()->where('log_name', 'settings.app')->latest('id')->firstOrFail();
        $this->assertSame(['app_name' => 'Auftragsportal'], $log->attribute_changes['attributes']);

        $this->get('/admin')->assertSee('Auftragsportal');
    }

    public function test_empty_name_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['app_name' => ''])
            ->call('save')
            ->assertHasFormErrors(['app_name' => 'required']);
    }

    public function test_falls_back_to_app_name_without_settings(): void
    {
        DB::table('settings')->where('group', 'app')->delete();
        $this->app->forgetInstance(AppSettings::class);
        config(['app.name' => 'Fallback']);

        (new BrandingServiceProvider($this->app))->boot();

        $this->assertSame('Fallback', config('app.name'));
    }

    public function test_root_redirects_to_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
