<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * E-Mail-Versand in den App-Einstellungen (B-124, D3b): SMTP-Server, Anmeldung und Absender; leer = Werte aus der .env.
 * Das Passwort wird verschlüsselt gespeichert (APP_KEY).
 */
return new class extends SettingsMigration
{
    private const array KEYS = ['mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_from_address', 'mail_from_name'];

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            $this->migrator->add('app.'.$key, null);
        }

        $this->migrator->addEncrypted('app.mail_password', null);
    }

    public function down(): void
    {
        foreach ([...self::KEYS, 'mail_password'] as $key) {
            $this->migrator->delete('app.'.$key);
        }
    }
};
