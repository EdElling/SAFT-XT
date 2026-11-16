<?php

declare(strict_types=1);

namespace App\DynamicData\Diagram;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Text für ein Mermaid-ER-Diagramm. Kennungen sind die technischen Namen (T_<name>), angezeigt
 * werden die Bezeichnungen in der aktuellen Sprache. Alle freien Texte stehen in Anführungszeichen
 * und sind maskiert; Mermaid läuft zusätzlich mit securityLevel "strict".
 *
 * Linien: durchgezogen = als Detailtabelle eingerichtet, gestrichelt = sonstige Verknüpfung.
 * Kardinalität: Pflichtfeld = genau ein Stammsatz, sonst keiner oder einer.
 */
final class MermaidErRenderer
{
    public const string CLASS_OUTSIDE = 'outside';

    public const string CLASS_EXTERNAL = 'external';

    public function render(DataModelGraph $graph, bool $withFields): string
    {
        $lines = ['erDiagram', '    direction LR'];

        foreach ($graph->tables as $table) {
            $lines = [...$lines, ...$this->entity($table, $withFields)];
        }

        foreach ($graph->outside as $table) {
            $lines[] = '    '.self::id($table->name).'["'.self::text($this->title($table)).'"]';
        }

        foreach ($graph->edges as $edge) {
            $line = $edge['detail'] ? '--' : '..';
            $parent = $edge['required'] ? '||' : 'o|';
            // Tabellenverknüpfung: ihre Bezeichnung mit „⇄“ davor (Linienart bleibt: Detail ja/nein).
            $label = ($edge['link'] ?? null) === null ? (string) $edge['field']->label : '⇄ '.$edge['link'];
            $lines[] = '    '.self::id($edge['from']).' }o'.$line.$parent.' '.self::id($edge['to']).' : "'.self::text($label).'"';
        }

        $external = array_keys(array_filter($graph->tables, fn (CustomTable $t): bool => $t->isExternal()));

        if ($graph->outside !== []) {
            $lines[] = '    classDef '.self::CLASS_OUTSIDE.' stroke-dasharray:5,stroke:#9ca3af';
            $lines[] = '    class '.implode(',', array_map(self::id(...), array_keys($graph->outside))).' '.self::CLASS_OUTSIDE;
        }

        if ($external !== []) {
            $lines[] = '    classDef '.self::CLASS_EXTERNAL.' stroke:#d97706,stroke-width:2px';
            $lines[] = '    class '.implode(',', array_map(self::id(...), $external)).' '.self::CLASS_EXTERNAL;
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function entity(CustomTable $table, bool $withFields): array
    {
        $head = '    '.self::id($table->name).'["'.self::text($this->title($table)).'"]';

        if (! $withFields) {
            return [$head];
        }

        $keys = $table->keyColumns();
        $lines = [$head.' {'];

        if (! $table->isExternal()) {
            $lines[] = '        integer id PK "ID"';
        }

        foreach ($table->fields as $field) {
            $lines[] = '        '.$this->attribute($field, in_array($field->name, $keys, true));
        }

        $lines[] = '    }';

        return $lines;
    }

    private function attribute(CustomField $field, bool $key): string
    {
        $markers = array_filter([
            $key ? 'PK' : null,
            $field->type === FieldType::Relation ? 'FK' : null,
            ! $key && $field->is_unique ? 'UK' : null,
        ]);
        $type = $field->type instanceof FieldType ? $field->type->value : (string) $field->type;

        return trim($type.' '.self::name($field->name).' '.implode(', ', $markers))
            .' "'.self::text((string) $field->label.($field->is_required ? ' *' : '')).'"';
    }

    /** Bezeichnung; bei angebundenen Tabellen mit Verbindung. */
    private function title(CustomTable $table): string
    {
        $label = (string) $table->label;

        return $table->isExternal() && $table->databaseConnection !== null
            ? $label.' ('.$table->databaseConnection->name.')'
            : $label;
    }

    public static function id(string $table): string
    {
        return 'T_'.self::name($table);
    }

    /** Bezeichner für Mermaid: Buchstaben, Ziffern, Unterstrich; beginnt mit einem Buchstaben. */
    private static function name(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);

        return preg_match('/^[A-Za-z]/', $name) === 1 ? $name : 'c_'.$name;
    }

    /** Freier Text in Anführungszeichen: Mermaid-Entitäten statt # und ", keine Zeilenumbrüche. */
    public static function text(string $text): string
    {
        $text = str_replace(['#', '"'], ['#35;', '#quot;'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
