<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AiConfig;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\AppSettingsPage;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Reports\Assistant\ReportAssistant;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** KI-Anbieter in den App-Einstellungen (Schlüssel verschlüsselt, Verbindungstest) und Tageslimit je Benutzer. */
class AiSettingsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const string KEY = 'sk-ant-test-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        config(['ai.provider' => 'none', 'ai.anthropic.api_key' => null, 'ai.model' => 'claude-opus-5-5', 'ai.daily_limit' => 50]);
    }

    private function config(): AiConfig
    {
        return app(AiConfig::class);
    }

    public function test_settings_override_env_and_fall_back_per_value(): void
    {
        $this->assertFalse($this->config()->configured());
        $this->assertSame('env', $this->config()->source());

        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => 'sk-env']);
        $this->assertTrue($this->config()->configured());
        $this->assertSame(['claude-opus-5-5', 'sk-env', 50], [$this->config()->model(), $this->config()->apiKey(), $this->config()->defaultDailyLimit()]);

        $settings = app(AppSettings::class);
        $settings->ai_provider = 'bedrock';
        $settings->ai_model = 'anthropic.claude-sonnet-5-5';
        $settings->ai_daily_limit = 20;
        $settings->save();

        $this->assertSame(['bedrock', 'settings', 'anthropic.claude-sonnet-5-5', 'medium', 20], [
            $this->config()->provider(), $this->config()->source(), $this->config()->model(), $this->config()->effort(), $this->config()->defaultDailyLimit(),
        ]);

        // „Aus“ in den Einstellungen schlägt die .env.
        $settings->ai_provider = 'none';
        $settings->save();
        $this->assertFalse($this->config()->configured());
    }

    public function test_api_key_is_encrypted_never_shown_and_masked_in_the_log(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['ai_provider' => 'anthropic', 'ai_api_key_new' => self::KEY, 'ai_daily_limit' => 10])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(self::KEY, $this->config()->apiKey());
        $stored = (string) DB::table('settings')->where('group', 'app')->where('name', 'ai_api_key')->value('payload');
        $this->assertStringNotContainsString(self::KEY, $stored, 'Verschlüsselt gespeichert.');
        $this->assertStringNotContainsString(self::KEY, json_encode(Activity::query()->get()->toArray()), 'Im Protokoll maskiert.');

        // Nie im Formular; leer lassen behält den Schlüssel.
        Livewire::test(AppSettingsPage::class)
            ->assertFormSet(['ai_api_key_new' => null])
            ->assertDontSee(self::KEY)
            ->assertSee('Gespeichert – leer lassen, um ihn zu behalten')
            ->fillForm(['ai_model' => 'claude-sonnet-5-5'])
            ->call('save');
        $this->assertSame([self::KEY, 'claude-sonnet-5-5'], [$this->config()->apiKey(), $this->config()->model()]);

        // Löschen.
        Livewire::test(AppSettingsPage::class)->fillForm(['ai_api_key_remove' => true])->call('save');
        $this->assertNull(app(AppSettings::class)->ai_api_key);
    }

    public function test_connection_test_uses_the_free_models_api(): void
    {
        $port = 8097;
        $server = new Process(['php', '-S', "127.0.0.1:{$port}", base_path('tests/Fakes/anthropic-stub.php')]);
        $server->start();
        usleep(400_000);

        try {
            config(['ai.anthropic.base_url' => "http://127.0.0.1:{$port}"]);
            $this->actingAs($this->superAdmin());

            Livewire::test(AppSettingsPage::class)
                ->fillForm(['ai_provider' => 'anthropic', 'ai_api_key_new' => self::KEY, 'ai_model' => 'claude-opus-5-5'])
                ->call('testAi')
                ->assertNotified('Verbindung in Ordnung – Modell verfügbar: Claude Stub claude-opus-5-5 (claude-opus-5-5)');
            $this->assertNull(app(AppSettings::class)->ai_provider, 'Testen speichert nichts.');

            Livewire::test(AppSettingsPage::class)
                ->fillForm(['ai_provider' => 'anthropic', 'ai_api_key_new' => 'bad'])
                ->call('testAi')
                ->assertNotified('Anmeldung abgelehnt: API-Schlüssel bzw. Zugangsdaten prüfen.');

            Livewire::test(AppSettingsPage::class)
                ->fillForm(['ai_provider' => 'anthropic', 'ai_api_key_new' => self::KEY, 'ai_model' => 'claude-gibt-es-nicht'])
                ->call('testAi')
                ->assertNotified('Modell „claude-gibt-es-nicht“ nicht gefunden.');
        } finally {
            $server->stop();
        }
    }

    public function test_daily_limit_per_user(): void
    {
        config(['ai.provider' => 'anthropic']);
        $settings = app(AppSettings::class);
        $settings->ai_assistant_enabled = true;
        $settings->ai_daily_limit = 3;
        $settings->save();

        $admin = $this->superAdmin();
        $this->assertSame(3, ReportAssistant::remainingToday($admin));

        // Admin setzt für eine Person 0 (gesperrt) bzw. ein eigenes Limit.
        $person = $this->userWithPermissions(['reports.assistant']);
        $this->actingAs($admin);
        Livewire::test(EditUser::class, ['record' => $person->id])
            ->assertFormFieldIsVisible('ai_daily_limit')
            ->fillForm(['ai_daily_limit' => 0])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(0, $person->fresh()->ai_daily_limit);
        $this->assertSame(0, ReportAssistant::remainingToday($person->fresh()));

        $this->actingAs($person->fresh());
        try {
            app(ReportAssistant::class)->propose($person->fresh(), 'x', []);
            $this->fail('Gesperrt erwartet.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Für Ihr Konto sind keine KI-Anfragen freigegeben.', $e->getMessage());
        }

        $person->update(['ai_daily_limit' => 7]);
        $this->assertSame(7, ReportAssistant::remainingToday($person->fresh()));

        // Ohne Anbieter ist das Feld nicht sichtbar.
        config(['ai.provider' => 'none']);
        $this->actingAs($admin);
        Livewire::test(EditUser::class, ['record' => $person->id])->assertFormFieldIsHidden('ai_daily_limit');
    }
}
