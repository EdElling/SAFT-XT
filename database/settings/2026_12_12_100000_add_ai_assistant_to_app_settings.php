<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** KI-Assistent für Auswertungen: Schalter (Vorgabe aus) und ob Auswahlwerte übertragen werden. */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('app.ai_assistant_enabled', false);
        $this->migrator->add('app.ai_send_choices', true);
    }

    public function down(): void
    {
        $this->migrator->delete('app.ai_assistant_enabled');
        $this->migrator->delete('app.ai_send_choices');
    }
};
