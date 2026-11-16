<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Französische und italienische Bezeichnungen für die mitgelieferten Menüeinträge, erkannt an
 * ihrer unveränderten deutschen Bezeichnung. Vorhandene Übersetzungen bleiben unberührt.
 */
return new class extends Migration
{
    /** @var array<string, array{fr: string, it: string}> deutsche Bezeichnung => Übersetzungen */
    private const array LABELS = [
        'Dashboard' => ['fr' => 'Tableau de bord', 'it' => 'Dashboard'],
        'Administration' => ['fr' => 'Administration', 'it' => 'Amministrazione'],
        'Benutzer' => ['fr' => 'Utilisateurs', 'it' => 'Utenti'],
        'Rollen' => ['fr' => 'Rôles', 'it' => 'Ruoli'],
        'Menüs' => ['fr' => 'Menus', 'it' => 'Menu'],
        'Änderungsprotokoll' => ['fr' => 'Journal des modifications', 'it' => 'Registro modifiche'],
        'Authentifizierung' => ['fr' => 'Authentification', 'it' => 'Autenticazione'],
        'Identity Provider' => ['fr' => 'Fournisseurs d’identité', 'it' => 'Provider di identità'],
        'App-Einstellungen' => ['fr' => 'Paramètres de l’application', 'it' => 'Impostazioni dell’applicazione'],
        'Table-Designer' => ['fr' => 'Concepteur de tables', 'it' => 'Designer di tabelle'],
        'Daten' => ['fr' => 'Données', 'it' => 'Dati'],
    ];

    /** Mitgelieferte Einträge sind Gruppen ohne Seite oder zeigen auf diese Seiten. */
    private const array SEEDED_ROUTES = [
        'filament.admin.pages.dashboard',
        'filament.admin.resources.users.index',
        'filament.admin.resources.roles.index',
        'filament.admin.resources.menus.index',
        'filament.admin.resources.activity-log.index',
        'filament.admin.pages.auth-settings',
        'filament.admin.resources.identity-providers.index',
        'filament.admin.pages.app-settings',
        'filament.admin.resources.table-designer.index',
    ];

    public function up(): void
    {
        foreach (DB::table('menus')->get(['id', 'label', 'route']) as $menu) {
            if ($menu->route !== null && ! in_array($menu->route, self::SEEDED_ROUTES, true)) {
                continue;
            }

            $labels = (array) json_decode((string) $menu->label, true);
            $added = self::LABELS[$labels['de'] ?? ''] ?? null;

            if ($added === null) {
                continue;
            }

            DB::table('menus')->where('id', $menu->id)->update([
                'label' => json_encode([...$added, ...$labels], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('menus')->get(['id', 'label']) as $menu) {
            $labels = (array) json_decode((string) $menu->label, true);
            $added = self::LABELS[$labels['de'] ?? ''] ?? null;

            if ($added === null) {
                continue;
            }

            foreach ($added as $locale => $text) {
                if (($labels[$locale] ?? null) === $text) {
                    unset($labels[$locale]);
                }
            }

            DB::table('menus')->where('id', $menu->id)->update(['label' => json_encode($labels, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
