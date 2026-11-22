<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Documents\DocumentMailer;
use App\Filament\Pages\AppSettingsPage;
use App\Settings\AppSettings;
use App\Support\MailConnectionTester;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** E-Mail-Versand in den globalen Einstellungen (B-124, D3b): SMTP statt .env, Passwort verschlüsselt, Verbindung prüfen. */
class MailSettingsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const string PASSWORD = 'smtp-geheim-4711';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        config(['mail.default' => 'log', 'mail.from.address' => 'env@example.ch']);
    }

    public function test_settings_switch_to_smtp_and_fall_back_to_env_per_value(): void
    {
        $settings = app(AppSettings::class);
        MailSettings::apply($settings);
        $this->assertSame(['log', 'env', 'env@example.ch'], [config('mail.default'), MailSettings::source($settings), config('mail.from.address')]);

        $settings->mail_host = 'smtp.example.ch';
        $settings->mail_encryption = 'ssl';
        $settings->mail_username = 'saft';
        $settings->mail_password = self::PASSWORD;
        $settings->mail_from_address = 'rechnung@example.ch';
        $settings->save();
        MailSettings::apply(app(AppSettings::class));

        $this->assertSame(['smtp', 'smtp.example.ch', 465, 'smtps', 'saft', self::PASSWORD, 'rechnung@example.ch'], [
            config('mail.default'), config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.scheme'),
            config('mail.mailers.smtp.username'), config('mail.mailers.smtp.password'), config('mail.from.address'),
        ]);
        $this->assertSame('settings', MailSettings::source(app(AppSettings::class)));
    }

    public function test_password_is_encrypted_never_shown_kept_and_masked_in_the_log(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['mail_host' => 'smtp.example.ch', 'mail_port' => 587, 'mail_encryption' => 'tls', 'mail_username' => 'saft', 'mail_password_new' => self::PASSWORD])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::PASSWORD, app(AppSettings::class)->mail_password);
        $stored = (string) DB::table('settings')->where('group', 'app')->where('name', 'mail_password')->value('payload');
        $this->assertStringNotContainsString(self::PASSWORD, $stored, 'Verschlüsselt gespeichert.');
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(Activity::query()->get()->toArray()), 'Im Protokoll maskiert.');

        // Nie wieder im Formular; leer speichern behält es, „löschen“ entfernt es.
        Livewire::test(AppSettingsPage::class)
            ->assertFormSet(['mail_password_new' => null, 'mail_host' => 'smtp.example.ch'])
            ->call('save');
        $this->assertSame(self::PASSWORD, app(AppSettings::class)->mail_password);

        Livewire::test(AppSettingsPage::class)->fillForm(['mail_password_remove' => true])->call('save');
        $this->assertNull(app(AppSettings::class)->mail_password);
    }

    public function test_connection_check_uses_the_unsaved_form_values_and_names_the_cause(): void
    {
        $this->actingAs($this->superAdmin());
        $tester = app(MailConnectionTester::class);

        $this->assertSame(MailConnectionTester::NO_SMTP, $tester->test(app(AppSettings::class))['kind'], 'Ohne Server gilt die .env (log).');

        // Ein lokaler Port, an dem niemand antwortet: nicht erreichbar – ohne Netz nach aussen.
        Livewire::test(AppSettingsPage::class)
            ->fillForm(['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_encryption' => 'none'])
            ->call('checkMail')
            ->assertNotified(__('settings.mail.check.unreachable', ['host' => '127.0.0.1', 'port' => '1']));

        $this->assertSame(['log', 'env@example.ch'], [config('mail.default'), config('mail.from.address')], 'Konfiguration wiederhergestellt.');
        $this->assertNull(app(AppSettings::class)->mail_host, 'Nicht gespeichert.');

        // Abgelehnte Absenderadresse (GMX: nur die Adresse des Kontos) – beim Prüfen (MAIL FROM) wie beim Versand.
        $gmx = "Expected response code \"250\" but got code \"550\", with message \"550-Requested action not taken: mailbox unavailable\n550-Sender address is not allowed.\"";
        $this->assertSame(MailConnectionTester::SENDER, MailConnectionTester::classify($gmx));
        $this->assertStringContainsString('lehnt die Absenderadresse env@example.ch ab', DocumentMailer::failure(new \RuntimeException($gmx)));
        $this->assertStringContainsString('konnte nicht gesendet werden', DocumentMailer::failure(new \RuntimeException('Timeout')));

        $this->assertSame([MailConnectionTester::AUTH, MailConnectionTester::TLS, MailConnectionTester::UNREACHABLE], [
            MailConnectionTester::classify('Failed to authenticate on SMTP server with username "saft" … got code "535".'),
            MailConnectionTester::classify('Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed'),
            MailConnectionTester::classify('Connection could not be established with host "smtp.example.ch:587"'),
        ]);
    }
}
