<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * „Verknüpfungen für Auswertungen“ heißen jetzt „Tabellenverknüpfungen“ (auch für die Master-Detail-Ansicht).
 * Nur der unveränderte Standard-Menüeintrag wird umbenannt; eigene Bezeichnungen bleiben.
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.resources.report-links.index';

    private const array OLD = ['de' => 'Verknüpfungen für Auswertungen', 'en' => 'Report links', 'fr' => 'Liaisons pour rapports', 'it' => 'Collegamenti per report'];

    private const array NEW = ['de' => 'Tabellenverknüpfungen', 'en' => 'Table links', 'fr' => 'Liaisons de tables', 'it' => 'Collegamenti tra tabelle'];

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
        DB::table('menus')->where('route', self::ROUTE)->where('label->de', $from['de'])->get(['id', 'label'])
            ->each(function (object $menu) use ($from, $to): void {
                $label = (array) json_decode((string) $menu->label, true);

                foreach ($to as $locale => $text) {
                    // Nur Sprachen ersetzen, die noch den alten Standardtext tragen.
                    if (($label[$locale] ?? null) === null || $label[$locale] === $from[$locale]) {
                        $label[$locale] = $text;
                    }
                }

                DB::table('menus')->where('id', $menu->id)->update(['label' => json_encode($label, JSON_UNESCAPED_UNICODE)]);
            });
    }
};
