<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bezeichnungen und Beschreibungen des Table-Designers je Sprache: {"de": "…"}.
 * Vorhandene Texte gelten als Deutsch. Auswahlwerte (options) bleiben JSON; ihre Bezeichnung
 * darf dort Text oder ein Objekt je Sprache sein.
 */
return new class extends Migration
{
    /** @var list<array{string, string, bool}> Tabelle, Spalte, Pflicht */
    private const array COLUMNS = [
        ['custom_tables', 'label', true],
        ['custom_tables', 'description', false],
        ['custom_fields', 'label', true],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column, $required]) {
            $this->convert($table, $column, $required, static fn (?string $value): ?string => $value === null || $value === ''
                ? null
                : json_encode(['de' => $value], JSON_UNESCAPED_UNICODE), 'json');
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column, $required]) {
            $this->convert($table, $column, $required, static function (?string $value): ?string {
                $translations = (array) json_decode((string) $value, true);
                $text = $translations['de'] ?? reset($translations);

                return $text === false || $text === null ? null : (string) $text;
            }, $column === 'description' ? 'text' : 'string');
        }
    }

    /**
     * Über eine Hilfsspalte, damit der Typwechsel ohne dialektspezifisches SQL auskommt.
     *
     * @param  Closure(?string): ?string  $map
     */
    private function convert(string $table, string $column, bool $required, Closure $map, string $type): void
    {
        $temporary = $column.'_converted';

        Schema::table($table, static function (Blueprint $blueprint) use ($temporary, $type): void {
            $blueprint->{$type}($temporary)->nullable();
        });

        foreach (DB::table($table)->get(['id', $column]) as $row) {
            DB::table($table)->where('id', $row->id)->update([$temporary => $map($row->{$column})]);
        }

        Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
        Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->renameColumn($temporary, $column));

        if ($required) {
            Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->{$type}($column)->nullable(false)->change());
        }
    }
};
