<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\AppSettings;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Verbindung zum SMTP-Server prüfen (B-124, D3b), ohne etwas zu versenden: verbinden, Verschlüsselung (STARTTLS bzw.
 * SSL/TLS), Anmeldung und Absenderadresse (MAIL FROM, danach RSET – es wird nichts übertragen). Fehler nach Ursache:
 * nicht erreichbar, Verschlüsselung, Anmeldung abgelehnt, Absender abgelehnt, anderes.
 */
final class MailConnectionTester
{
    public const string OK = 'ok';

    public const string NO_SMTP = 'no_smtp';

    public const string UNREACHABLE = 'unreachable';

    public const string TLS = 'tls';

    public const string AUTH = 'auth';

    /** Absenderadresse vom Server abgelehnt (z. B. GMX, Gmail: nur die Adresse des Kontos oder erlaubte Aliasse). */
    public const string SENDER = 'sender';

    public const string OTHER = 'other';

    /**
     * Mit den übergebenen (auch ungespeicherten) Einstellungen; die Konfiguration wird danach wiederhergestellt.
     *
     * @return array{ok: bool, kind: string, message: string, detail: ?string}
     */
    public function test(AppSettings $settings, ?string $password = null): array
    {
        $original = config('mail');

        try {
            MailSettings::apply($settings, $password);
            $config = (array) config('mail.mailers.smtp');

            if (config('mail.default') !== 'smtp') {
                return $this->result(self::NO_SMTP, null, ['mailer' => (string) config('mail.default')]);
            }

            $transport = app(MailManager::class)->createSymfonyTransport($config);

            if (! $transport instanceof SmtpTransport) {
                return $this->result(self::OTHER, get_debug_type($transport));
            }

            $from = (string) config('mail.from.address');

            try {
                $transport->start();

                try {
                    $transport->executeCommand(sprintf("MAIL FROM:<%s>\r\n", $from), [250]);
                } catch (Throwable $exception) {
                    return $this->result(self::SENDER, $exception->getMessage(), ['from' => $from, 'host' => (string) $config['host']]);
                }

                $transport->executeCommand("RSET\r\n", [250]);
            } finally {
                $transport->stop();
            }

            return $this->result(self::OK, null, ['host' => (string) $config['host'], 'port' => (string) $config['port'], 'from' => $from]);
        } catch (Throwable $exception) {
            return $this->result(self::classify($exception->getMessage()), $exception->getMessage(), [
                'host' => (string) config('mail.mailers.smtp.host'), 'port' => (string) config('mail.mailers.smtp.port'),
            ]);
        } finally {
            config(['mail' => $original]);
            app(MailManager::class)->forgetMailers();
        }
    }

    /** Ursache aus der Meldung des Mailers (Symfony Mailer). */
    public static function classify(string $message): string
    {
        return match (true) {
            self::senderRejected($message) => self::SENDER,
            str_contains($message, 'Connection could not be established'), str_contains($message, 'Connection refused'),
            str_contains($message, 'getaddrinfo'), str_contains($message, 'timed out'), str_contains($message, 'Connection to') => self::UNREACHABLE,
            stripos($message, 'authenticat') !== false, str_contains($message, '535'), str_contains($message, '534') => self::AUTH,
            stripos($message, 'TLS') !== false, stripos($message, 'SSL') !== false, stripos($message, 'certificate') !== false => self::TLS,
            default => self::OTHER,
        };
    }

    /** Hat der Server die Absenderadresse abgelehnt (Antwort beim Versand oder bei MAIL FROM)? */
    public static function senderRejected(string $message): bool
    {
        return preg_match('/sender address|sender .*not (allowed|permitted)|not authori[sz]ed to send|5\.7\.1|553/i', $message) === 1;
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{ok: bool, kind: string, message: string, detail: ?string}
     */
    private function result(string $kind, ?string $detail, array $replace = []): array
    {
        return ['ok' => $kind === self::OK, 'kind' => $kind, 'message' => __('settings.mail.check.'.$kind, $replace), 'detail' => $detail];
    }
}
