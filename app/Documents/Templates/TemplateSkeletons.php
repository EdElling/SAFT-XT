<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use Illuminate\Support\Collection;

/**
 * Grundgerüste für neue Vorlagen, gebaut aus den Feldern der Grundtabelle: Brief, Rechnung (erste Kind-Tabelle als
 * Positionen, berechnete Beträge als Summen) und Datenblatt (alle Felder). Ein Ausgangspunkt – Texte und Felder passt
 * man danach an. Überschriften in der aktuellen Sprache.
 */
final class TemplateSkeletons
{
    public const string EMPTY = 'empty';

    public const string LETTER = 'letter';

    public const string INVOICE = 'invoice';

    public const string SHEET = 'sheet';

    public const array KINDS = [self::EMPTY, self::LETTER, self::INVOICE, self::SHEET];

    /** Felder, an denen eine Adresse zu erkennen ist. */
    private const array ADDRESS_FIELDS = ['strasse', 'adresse', 'plz', 'ort'];

    /** @return array{recipient: string, body: string, file_name: string} */
    public static function make(string $kind, CustomTable $table): array
    {
        $title = self::titleField($table);
        $fileName = __('documents.templates.skeleton.file_'.($kind === self::INVOICE ? 'invoice' : 'document')).($title !== null ? '-{{'.$title->name.'}}' : '');

        return match ($kind) {
            self::LETTER => ['recipient' => self::recipient($table), 'body' => self::letter($table), 'file_name' => $fileName],
            self::INVOICE => ['recipient' => self::recipient($table), 'body' => self::invoice($table), 'file_name' => $fileName],
            self::SHEET => ['recipient' => '', 'body' => self::sheet($table), 'file_name' => $fileName],
            default => ['recipient' => '', 'body' => '', 'file_name' => $fileName],
        };
    }

    private static function letter(CustomTable $table): string
    {
        return implode("\n", [
            '<p class="rechts">{{heute | DatumLang}}</p>',
            '',
            '<h1>'.e(__('documents.templates.skeleton.subject')).'</h1>',
            '',
            '<p>'.e(__('documents.templates.skeleton.salutation')).'</p>',
            '',
            '<p>'.e(__('documents.templates.skeleton.text')).'</p>',
            '',
            '<p>'.e(__('documents.templates.skeleton.closing')).'<br>',
            '{{absender.firma}}</p>',
        ]);
    }

    private static function invoice(CustomTable $table): string
    {
        $fields = self::visible($table);
        $title = self::titleField($table);
        $info = $fields->filter(static fn (CustomField $f): bool => in_array($f->valueType(), [FieldType::Date, FieldType::String, FieldType::Select], true) && $f->type !== FieldType::Computed)->take(4);
        $sums = $fields->filter(static fn (CustomField $f): bool => $f->type === FieldType::Computed && in_array($f->valueType(), [FieldType::Decimal, FieldType::Integer], true));
        $children = TemplateFields::children($table);
        $section = array_key_first($children);
        $lines = ['<p class="rechts">{{heute | DatumLang}}</p>', '', '<h1>'.e(__('documents.templates.skeleton.invoice')).($title !== null ? ' {{'.$title->name.'}}' : '').'</h1>', ''];

        if ($info->isNotEmpty()) {
            $lines[] = '<table>';

            foreach ($info as $field) {
                $lines[] = '    <tr><td width="35%">'.e($field->label).'</td><td>{{'.$field->name.'}}</td></tr>';
            }

            $lines[] = '</table>';
            $lines[] = '';
        }

        if ($section !== null) {
            $child = $children[$section];
            $columns = self::visible($child->customTable)
                ->reject(static fn (CustomField $f): bool => $f->is($child) || in_array($f->valueType(), [FieldType::Text, FieldType::File, FieldType::Boolean], true))
                ->take(5)->values();
            $width = $columns->count() > 1 ? (int) floor(60 / ($columns->count() - 1)) : 100;
            $numeric = static fn (CustomField $f): bool => in_array($f->valueType(), [FieldType::Decimal, FieldType::Integer], true);
            $lines[] = '<table class="positionen">';
            $lines[] = '    <thead><tr>'.$columns->map(static fn (CustomField $f, int $i): string => '<th'.($numeric($f) ? ' class="rechts"' : '').' width="'.($i === 0 ? 40 : $width).'%">'.e($f->label).'</th>')->implode('').'</tr></thead>';
            $lines[] = '    <tbody>';
            $lines[] = '    {{#'.$section.'}}';
            $lines[] = '        <tr>'.$columns->map(static fn (CustomField $f, int $i): string => '<td'.($numeric($f) ? ' class="rechts"' : '').' width="'.($i === 0 ? 40 : $width).'%">{{'.$f->name.'}}</td>')->implode('').'</tr>';
            $lines[] = '    {{/'.$section.'}}';

            foreach ($sums as $sum) {
                $lines[] = '        <tr class="summe"><td colspan="'.max(1, $columns->count() - 1).'">'.e($sum->label).'</td><td class="rechts">{{'.$sum->name.' | CHF}}</td></tr>';
            }

            $lines[] = '    </tbody>';
            $lines[] = '</table>';
            $lines[] = '';
        }

        $lines[] = '<p>'.e(__('documents.templates.skeleton.payment')).'</p>';
        $lines[] = '<p class="klein">IBAN {{absender.iban}}{{#absender.mwst}} · MWST {{absender.mwst}}{{/absender.mwst}}</p>';

        return implode("\n", $lines);
    }

    private static function sheet(CustomTable $table): string
    {
        $title = self::titleField($table);
        $lines = ['<h1>'.e($table->label).($title !== null ? ' {{'.$title->name.'}}' : '').'</h1>', '', '<table class="positionen">', '    <tbody>'];

        foreach (self::visible($table)->reject(static fn (CustomField $f): bool => $f->valueType() === FieldType::File) as $field) {
            $value = $field->valueType() === FieldType::Text ? '{{'.$field->name.' | Zeilen}}' : '{{'.$field->name.'}}';
            $lines[] = '        <tr><td width="35%" class="fett">'.e($field->label).'</td><td width="65%">'.$value.'</td></tr>';
        }

        return implode("\n", [...$lines, '    </tbody>', '</table>']);
    }

    /** Empfänger aus der ersten Verknüpfung mit Adressfeldern bzw. aus den eigenen Adressfeldern. */
    private static function recipient(CustomTable $table): string
    {
        $hasAddress = static fn (CustomTable $t): bool => TemplateFields::fields($t)->pluck('name')->intersect(self::ADDRESS_FIELDS)->isNotEmpty();
        $prefix = '';
        $target = $table;

        if (! $hasAddress($table)) {
            $relation = TemplateFields::fields($table)->first(static fn (CustomField $f): bool => ($t = TemplateFields::target($f)) !== null && $hasAddress($t));

            if ($relation === null) {
                return '';
            }

            $prefix = $relation->name.'.';
            $target = TemplateFields::target($relation);
        }

        $names = TemplateFields::fields($target)->pluck('name')->all();
        $pick = static fn (array $candidates): array => array_values(array_intersect($candidates, $names));
        $name = $pick(['firma', 'vorname', 'name', 'nachname']);
        $lines = [
            implode(' ', array_map(static fn (string $n): string => '{{'.$prefix.$n.'}}', $name)),
            ...array_map(static fn (string $n): string => '{{'.$prefix.$n.' | Zeilen}}', $pick(['strasse', 'adresse'])),
            implode(' ', array_map(static fn (string $n): string => '{{'.$prefix.$n.'}}', $pick(['plz', 'ort']))),
        ];

        return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /** @return Collection<int, CustomField> */
    private static function visible(CustomTable $table): Collection
    {
        return TemplateFields::fields($table)->filter(static fn (CustomField $f): bool => $f->show_in_form || $f->show_in_table)->values();
    }

    /** Feld, das einen Datensatz bezeichnet: eindeutig, sonst das erste Pflicht-Textfeld. */
    private static function titleField(CustomTable $table): ?CustomField
    {
        $text = TemplateFields::fields($table)->filter(static fn (CustomField $f): bool => $f->valueType() === FieldType::String);

        return $text->firstWhere('is_unique', true) ?? $text->firstWhere('is_required', true);
    }
}
