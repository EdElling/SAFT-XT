<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportLinks\Schemas;

use App\Filament\Support\TranslatableInputs;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\ReportLink;
use App\Reports\Links\ReportLinks;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Formular einer Tabellenverknüpfung: Quelle (Tabelle, Feld) → Ziel (Tabelle, eindeutiges Feld).
 * Die Auswahl zeigt nur passende Felder; „Treffer“ prüft live, wie viele Werte im Ziel gefunden werden.
 */
class ReportLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        $links = app(ReportLinks::class);
        $table = fn (mixed $id): ?CustomTable => filled($id) ? CustomTable::query()->find((int) $id) : null;
        $field = fn (mixed $id): ?CustomField => filled($id) ? CustomField::query()->find((int) $id) : null;

        return $schema
            ->columns(1)
            ->components([
                // Erklärung in jedem Sprachreiter (der Rückfall-Hinweis steht als Platzhalter im Feld).
                TranslatableInputs::make('label', __('report_links.label'), required: true, configure: static fn ($input) => $input->helperText(__('report_links.label_help'))),
                Section::make(__('report_links.source'))
                    ->columns(2)
                    ->schema([
                        Select::make('source_table_id')
                            ->label(__('report_links.table'))
                            ->options(fn (): array => self::tableOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('source_field_id', null)),
                        Select::make('source_field_id')
                            ->label(__('report_links.source_field'))
                            ->helperText(__('report_links.source_field_help'))
                            ->options(fn (Get $get): array => ($t = $table($get('source_table_id'))) === null ? [] : $links->sourceOptions($t))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('target_field', null))
                            ->rule(fn (Get $get, ?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $duplicate = ReportLink::query()
                                    ->where('source_field_id', (int) $value)
                                    ->where('target_table_id', (int) $get('target_table_id'))
                                    ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($duplicate) {
                                    $fail(__('report_links.errors.duplicate'));
                                }
                            }),
                    ]),
                Section::make(__('report_links.target'))
                    ->columns(2)
                    ->schema([
                        Select::make('target_table_id')
                            ->label(__('report_links.table'))
                            ->options(fn (): array => self::tableOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('target_field', null)),
                        Select::make('target_field')
                            ->label(__('report_links.target_field'))
                            ->helperText(__('report_links.target_field_help'))
                            ->options(function (Get $get) use ($links, $table, $field): array {
                                $target = $table($get('target_table_id'));
                                $source = $field($get('source_field_id'));

                                return $target === null ? [] : $links->targetOptions($target, $source === null ? null : ReportLinks::kind($source));
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $links, $table, $field): void {
                                $problem = $links->problem($table($get('source_table_id')), $field($get('source_field_id')), $table($get('target_table_id')), $value);

                                if ($problem !== null) {
                                    $fail($problem);
                                }
                            }),
                    ]),
                Section::make(__('report_links.coverage_label'))
                    ->visible(fn (Get $get): bool => filled($get('source_field_id')) && filled($get('target_field')))
                    ->schema([
                        Text::make(function (Get $get) use ($links, $table, $field): string {
                            $target = $table($get('target_table_id'));
                            $targetField = (string) $get('target_field');
                            $column = $targetField === ReportLinks::ID ? 'id' : (string) $field($targetField)?->name;

                            return (string) $links->coverageText([$table($get('source_table_id')), $field($get('source_field_id')), $target, $column]);
                        })->extraAttributes(['data-link-coverage' => true]),
                    ]),
            ]);
    }

    /** @return array<int, string> alle aktiven Tabellen mit Herkunft */
    public static function tableOptions(): array
    {
        return CustomTable::query()->with('databaseConnection')->where('is_active', true)->get()
            ->sortBy(fn (CustomTable $t): string => mb_strtolower((string) $t->label))
            ->mapWithKeys(fn (CustomTable $t): array => [$t->id => $t->label.' · '.($t->isExternal()
                ? __('report_links.external', ['connection' => (string) $t->databaseConnection?->name])
                : __('report_links.designer')).' ('.$t->name.')'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toForm(array $data, Model $record): array
    {
        return [...$data, 'target_field' => $record instanceof ReportLink && $record->target_field_id !== null ? (string) $record->target_field_id : ReportLinks::ID];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toRecord(array $data): array
    {
        $target = (string) ($data['target_field'] ?? '');
        unset($data['target_field']);

        return [...$data, 'target_field_id' => $target === ReportLinks::ID || $target === '' ? null : (int) $target];
    }
}
