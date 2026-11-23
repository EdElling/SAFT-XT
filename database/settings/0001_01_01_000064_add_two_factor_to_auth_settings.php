<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Vorgabe: Pflicht für Konten mit Administrationsrechten (B-057).
        $this->migrator->add('auth.two_factor', 'administration');
    }
};
