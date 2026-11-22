<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B-124, Etappe D1a: Briefpapier für Dokumente – eine Zeile für die Installation (app_id leer) und auf Wunsch je App
 * eine mit Abweichungen (leere Werte erben von der Installation). Dazu die App-Rechte documents.manage (Briefpapier der
 * App und Vorlagen) und documents.generate (Dokumente erzeugen) und der Menüeintrag Administration › Anwendung ›
 * Dokumente.
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.pages.document-settings';

    public function up(): void
    {
        Schema::create('document_letterheads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->nullable()->unique()->constrained('apps')->cascadeOnDelete();
            // Briefpapier als PDF (privater Speicher): erste Seite und Folgeseiten; ohne Folgeseiten gilt Seite 2 der
            // ersten Datei, wenn es sie gibt.
            $table->string('first_page_path')->nullable();
            $table->string('next_pages_path')->nullable();
            $table->string('logo_path')->nullable();
            // Höhe des Logos im Kopf (mm); die Breite folgt aus dem Seitenverhältnis, höchstens Letterhead::LOGO_MAX_WIDTH.
            $table->decimal('logo_height', 5, 1)->nullable();
            // Hausschrift als TTF: normal, fett, kursiv.
            $table->string('font_regular_path')->nullable();
            $table->string('font_bold_path')->nullable();
            $table->string('font_italic_path')->nullable();
            $table->string('color_primary', 7)->nullable();
            $table->string('color_secondary', 7)->nullable();
            $table->string('color_text', 7)->nullable();
            // Ränder in mm je Seitentyp: {"first": {"top": 45, …}, "next": {…}}
            $table->json('margins')->nullable();
            $table->string('address_position', 10)->nullable();
            $table->string('sender_line', 200)->nullable();
            // Absenderdaten: firma, adresse, mwst, iban, kontakt
            $table->json('sender')->nullable();
            $table->boolean('pdfa')->nullable();
            $table->timestamps();
        });

        foreach (['documents.manage', 'documents.generate'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Wer globale Einstellungen ändern darf, verwaltet auch Dokumente; wer Auswertungen sehen darf, darf Dokumente
        // erzeugen (je App, wie die Rechte der Auswertungen).
        $this->grant('settings.app.update', 'documents.manage');
        $this->grant('reports.view', 'documents.generate');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');
        $parent = $admin === null ? null : DB::table('menus')->where('parent_id', $admin)->whereNull('route')->where('label->de', 'Anwendung')->value('id');

        if (($parent ?? $admin) === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent ?? $admin,
            'label' => json_encode(['de' => 'Briefpapier', 'en' => 'Letterhead', 'fr' => 'Papier à lettres', 'it' => 'Carta intestata'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-document-text',
            'permission_name' => 'documents.manage',
            'sort_order' => 25,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grant(string $existing, string $new): void
    {
        $from = DB::table('permissions')->where('name', $existing)->where('guard_name', 'web')->value('id');
        $to = DB::table('permissions')->where('name', $new)->where('guard_name', 'web')->value('id');

        if ($from === null || $to === null) {
            return;
        }

        foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $to, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Schema::dropIfExists('document_letterheads');
        Permission::query()->whereIn('name', ['documents.manage', 'documents.generate'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
