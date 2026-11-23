<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Inhaltsvorlagen für Dokumente (B-124, D1b): HTML mit Mustache-Platzhaltern je App und Grundtabelle, dazu der
 * Empfänger fürs Adressfeld und das Muster des Dateinamens. Menüeintrag „Dokumentvorlagen“ unter Administration ›
 * Anwendung (Recht documents.manage, je App).
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.resources.document-templates.index';

    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('custom_table_id')->constrained('custom_tables')->cascadeOnDelete();
            $table->string('name', 150);
            // Empfänger fürs Adressfeld: eine Zeile je Adresszeile, mit Platzhaltern; leer = kein Adressfeld.
            $table->text('recipient')->nullable();
            $table->longText('body');
            // Muster des Dateinamens ohne .pdf, z. B. Rechnung-{{belegnummer}}.
            $table->string('file_name', 200)->nullable();
            // Feld der Grundtabelle mit der Sprache des Dokuments (de, en, fr, it); leer = Sprache der Person.
            $table->string('language_field', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['app_id', 'name']);
        });

        // Der Menüeintrag der Briefpapier-Seite hiess bis D1b „Dokumente“.
        DB::table('menus')->where('route', 'filament.admin.pages.document-settings')->where('label->de', 'Dokumente')->update([
            'label' => json_encode(['de' => 'Briefpapier', 'en' => 'Letterhead', 'fr' => 'Papier à lettres', 'it' => 'Carta intestata'], JSON_UNESCAPED_UNICODE),
        ]);

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');
        $parent = $admin === null ? null : DB::table('menus')->where('parent_id', $admin)->whereNull('route')->where('label->de', 'Anwendung')->value('id');

        if (($parent ?? $admin) === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent ?? $admin,
            'label' => json_encode(['de' => 'Dokumentvorlagen', 'en' => 'Document templates', 'fr' => 'Modèles de documents', 'it' => 'Modelli di documenti'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-document-duplicate',
            'permission_name' => 'documents.manage',
            'sort_order' => 26,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Schema::dropIfExists('document_templates');
    }
};
