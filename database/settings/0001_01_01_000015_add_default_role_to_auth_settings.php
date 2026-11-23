<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Rollenname (stabil über Neu-Seeding hinweg); Rolle legt der RolesAndPermissionsSeeder an.
        $this->migrator->add('auth.default_role', 'benutzer');
    }
};
