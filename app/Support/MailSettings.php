<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\AppSettings;
use Illuminate\Mail\MailManager;

/**
 * E-Mail-Versand aus den App-Einstellungen (B-124, D3b): Ist ein SMTP-Server eingetragen, gilt er für alle E-Mails
 * (Passwort zurücksetzen, Dokumente) statt MAIL_MAILER der .env; leere Felder fallen auf die .env zurück. Absender aus
 * den Einstellungen, sonst MAIL_FROM_ADDRESS bzw. der Anzeigename.
 */
final class MailSettings
{
    public const array ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** Herkunft: 'settings' (Oberfläche) oder 'env'. */
    public static function source(AppSettings $settings): string
    {
        return filled($settings->mail_host) ? 'settings' : 'env';
    }

    /** Einstellungen in die Mail-Konfiguration übernehmen; bereits erzeugte Mailer werden verworfen. */
    public static function apply(AppSettings $settings, ?string $password = null): void
    {
        if (filled($settings->mail_from_address)) {
            config(['mail.from.address' => trim((string) $settings->mail_from_address)]);
        }

        if (filled($settings->mail_from_name)) {
            config(['mail.from.name' => trim((string) $settings->mail_from_name)]);
        }

        if (filled($settings->mail_host)) {
            $encryption = (string) ($settings->mail_encryption ?? '');
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.url' => null,
                'mail.mailers.smtp.host' => trim((string) $settings->mail_host),
                'mail.mailers.smtp.port' => $settings->mail_port ?? ($encryption === 'ssl' ? 465 : 587),
                // ssl: direkt verschlüsselt (smtps); tls und automatisch: STARTTLS, sobald der Server es anbietet.
                'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.auto_tls' => $encryption !== 'none',
                // tls: ohne STARTTLS keine Verbindung (Passwort nie unverschlüsselt).
                'mail.mailers.smtp.require_tls' => $encryption === 'tls',
                'mail.mailers.smtp.timeout' => config('mail.mailers.smtp.timeout') ?? 20,
                'mail.mailers.smtp.username' => filled($settings->mail_username) ? (string) $settings->mail_username : config('mail.mailers.smtp.username'),
                'mail.mailers.smtp.password' => $password ?? (filled($settings->mail_password) ? (string) $settings->mail_password : config('mail.mailers.smtp.password')),
            ]);
        }

        if (app()->resolved('mail.manager')) {
            app(MailManager::class)->forgetMailers();
        }
    }

    /** Kurzbeschreibung des aktiven Versands für die Einstellungsseite. */
    public static function status(): string
    {
        $mailer = (string) config('mail.default');

        return $mailer === 'smtp'
            ? __('settings.mail.status_smtp', ['host' => (string) config('mail.mailers.smtp.host'), 'port' => (string) config('mail.mailers.smtp.port'), 'from' => (string) config('mail.from.address')])
            : __(in_array($mailer, ['log', 'array'], true) ? 'settings.mail.status_log' : 'settings.mail.status_other', ['mailer' => $mailer, 'from' => (string) config('mail.from.address')]);
    }
}
