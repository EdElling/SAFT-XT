<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menübezeichnungen je Sprache (spatie/laravel-translatable): {"de": "…", "en": "…"}.
 * Vorhandene Bezeichnungen gelten als Deutsch. Englisch erhalten nur die mitgelieferten
 * Einträge, erkannt an ihrer Seite bzw. bei Gruppen an ihrer bisherigen Bezeichnung.
 */
return new class extends Migration
{
    /** @var array<string, string> Route => englische Bezeichnung */
    private const array ROUTES = [
        'filament.admin.pages.dashboard' => 'Dashboard',
        'filament.admin.resources.users.index' => 'Users',
        'filament.admin.resources.roles.index' => 'Roles',
        'filament.admin.resources.menus.index' => 'Menus',
        'filament.admin.resources.activity-log.index' => 'Activity log',
        'filament.admin.pages.auth-settings' => 'Authentication',
        'filament.admin.resources.identity-providers.index' => 'Identity providers',
        'filament.admin.pages.app-settings' => 'App settings',
        'filament.admin.resources.table-designer.index' => 'Table designer',
    ];

    /** @var array<string, string> Gruppe (deutsch) => englische Bezeichnung */
    private const array GROUPS = [
        'Administration' => 'Administration',
        'Daten' => 'Data',
    ];

    /** Nur wenn die Bezeichnung noch die mitgelieferte ist; umbenannte Einträge bleiben unberührt. */
    private const array SEEDED_GERMAN = [
        'filament.admin.pages.dashboard' => 'Dashboard',
        'filament.admin.resources.users.index' => 'Benutzer',
        'filament.admin.resources.roles.index' => 'Rollen',
        'filament.admin.resources.menus.index' => 'Menüs',
        'filament.admin.resources.activity-log.index' => 'Änderungsprotokoll',
        'filament.admin.pages.auth-settings' => 'Authentifizierung',
        'filament.admin.resources.identity-providers.index' => 'Identity Provider',
        'filament.admin.pages.app-settings' => 'App-Einstellungen',
        'filament.admin.resources.table-designer.index' => 'Table-Designer',
    ];

    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->json('label_translations')->nullable();
        });

        foreach (DB::table('menus')->get(['id', 'label', 'route']) as $menu) {
            $translations = ['de' => (string) $menu->label];
            $route = (string) $menu->route;

            if (isset(self::ROUTES[$route]) && self::SEEDED_GERMAN[$route] === $menu->label) {
                $translations['en'] = self::ROUTES[$route];
            } elseif ($menu->route === null && isset(self::GROUPS[$menu->label])) {
                $translations['en'] = self::GROUPS[$menu->label];
            }

            DB::table('menus')->where('id', $menu->id)->update(['label_translations' => json_encode($translations, JSON_UNESCAPED_UNICODE)]);
        }

        Schema::table('menus', function (Blueprint $table): void {
            $table->dropColumn('label');
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->renameColumn('label_translations', 'label');
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->json('label')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->string('label_plain')->nullable();
        });

        foreach (DB::table('menus')->get(['id', 'label']) as $menu) {
            $translations = (array) json_decode((string) $menu->label, true);
            DB::table('menus')->where('id', $menu->id)->update(['label_plain' => (string) ($translations['de'] ?? reset($translations) ?: '')]);
        }

        Schema::table('menus', function (Blueprint $table): void {
            $table->dropColumn('label');
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->renameColumn('label_plain', 'label');
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->string('label')->nullable(false)->change();
        });
    }
};
