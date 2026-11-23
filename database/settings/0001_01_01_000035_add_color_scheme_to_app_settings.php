<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('app.color_scheme', 'indigo');
    }

    public function down(): void
    {
        $this->migrator->delete('app.color_scheme');
    }
};
