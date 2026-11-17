<?php

declare(strict_types=1);

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Administration in Untermenüs gliedern (B-066): Benutzer und Zugriff, Datenbank, Anwendung, Betrieb. Nur umhängen –
 * angepasste Bezeichnungen und selbst verschobene oder gelöschte Einträge bleiben, wie die Administration sie hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admin = Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->where('label->de', 'Administration')->first();

        if ($admin !== null) {
            MenuSeeder::arrangeAdministration($admin, refresh: false);
        }
    }

    public function down(): void
    {
        $admin = Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->where('label->de', 'Administration')->first();

        if ($admin === null) {
            return;
        }

        foreach (MenuSeeder::ADMIN_SUBMENUS as $submenu) {
            $parent = Menu::query()->where('parent_id', $admin->id)->whereNull('route')->where('label->de', $submenu['label']['de'])->first();

            if ($parent !== null) {
                Menu::query()->where('parent_id', $parent->id)->update(['parent_id' => $admin->id]);
                $parent->delete();
            }
        }
    }
};
