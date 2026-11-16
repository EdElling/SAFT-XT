<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Hilfe-Assistent: Schalter (Vorgabe aus) und ob das Datenmodell der Person mitgeht (Vorgabe aus). */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('app.ai_help_enabled', false);
        $this->migrator->add('app.ai_help_schema', false);
    }

    public function down(): void
    {
        $this->migrator->delete('app.ai_help_enabled');
        $this->migrator->delete('app.ai_help_schema');
    }
};
