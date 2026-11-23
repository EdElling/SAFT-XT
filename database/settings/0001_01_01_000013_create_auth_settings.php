<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('auth.mode', 'local');
        $this->migrator->add('auth.break_glass_enabled', true);
        $this->migrator->add('auth.password_reset_enabled', false);
    }
};
