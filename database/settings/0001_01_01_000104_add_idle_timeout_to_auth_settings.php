<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Abmelden nach Inaktivität (B-162): Vorgabe 4 Stunden ohne Bedienung; „Angemeldet bleiben“ höchstens 12 Stunden.
        $this->migrator->add('auth.idle_timeout_minutes', 240);
        $this->migrator->add('auth.remember_me_enabled', true);
        $this->migrator->add('auth.remember_me_hours', 12);
    }
};
