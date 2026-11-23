<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B-107: „App-Einstellungen“ heisst jetzt „Globale Einstellungen“ – „App“ steht für eine Fachanwendung. Nur
 * Menüeinträge mit der ausgelieferten Bezeichnung werden umbenannt; eigene Bezeichnungen bleiben.
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.pages.app-settings';

    private const array OLD = ['de' => 'App-Einstellungen', 'en' => 'App settings', 'fr' => 'Paramètres de l’application', 'it' => 'Impostazioni dell’applicazione'];

    private const array NEW = ['de' => 'Globale Einstellungen', 'en' => 'Global settings', 'fr' => 'Paramètres globaux', 'it' => 'Impostazioni globali'];

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    /**
     * @param  array<string, string>  $from
     * @param  array<string, string>  $to
     */
    private function rename(array $from, array $to): void
    {
        foreach (DB::table('menus')->where('route', self::ROUTE)->get(['id', 'label']) as $menu) {
            $label = (array) json_decode((string) $menu->label, true);

            if (($label['de'] ?? null) !== $from['de']) {
                continue;
            }

            foreach ($from as $locale => $text) {
                if (($label[$locale] ?? null) === $text) {
                    $label[$locale] = $to[$locale];
                }
            }

            DB::table('menus')->where('id', $menu->id)->update(['label' => json_encode($label, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
