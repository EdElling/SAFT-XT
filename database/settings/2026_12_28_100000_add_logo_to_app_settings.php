<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Eigenes Logo (B-126): Pfad der hochgeladenen Datei (leer = SAFT-Logo) und ob es auch im Browser-Tab erscheint. */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('app.logo_path', null);
        $this->migrator->add('app.logo_as_favicon', false);
    }

    public function down(): void
    {
        $this->migrator->delete('app.logo_path');
        $this->migrator->delete('app.logo_as_favicon');
    }
};
