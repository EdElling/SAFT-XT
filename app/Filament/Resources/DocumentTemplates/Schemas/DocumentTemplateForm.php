<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Schemas;

use App\Documents\LetterheadResolver;
use App\Documents\Templates\TemplateChecker;
use App\Documents\Templates\TemplateFields;
use App\Documents\Templates\TemplateSkeletons;
use App\DynamicData\Files\FileKinds;
use App\DynamicData\Schema\FieldType;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use Closure;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Bearbeiten einer Inhaltsvorlage: links Kopfdaten, Empfänger und HTML, rechts Feldauswahl und Live-Vorschau (PDF mit
 * einem Beispieldatensatz). Die Vorschau setzt die Seite (PreviewsDocumentTemplate) aus dem ungespeicherten Stand.
 */
class DocumentTemplateForm
{
    /** Verzögerung der Vorschau nach der letzten Eingabe. */
    public const int PREVIEW_DEBOUNCE_MS = 1500;

    public static function configure(Schema $schema): Schema
    {
        $table = static fn (Get $get): ?CustomTable => filled($get('custom_table_id')) ? CustomTable::query()->find((int) $get('custom_table_id')) : null;

        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'xl' => 5])->schema([
                    Section::make(__('documents.templates.sections.template'))
                        ->columnSpan(['default' => 1, 'xl' => 3])
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')->label(__('documents.templates.fields.name'))->required()->maxLength(150)
                                ->unique(ignoreRecord: true, modifyRuleUsing: static fn (Unique $rule, Get $get): Unique => $rule->where('app_id', $get('app_id'))),
                            Select::make('app_id')->label(__('documents.templates.fields.app'))
                                ->options(static fn (): array => self::appOptions())
                                ->default(static fn (): ?int => count($apps = self::appOptions()) === 1 ? (int) array_key_first($apps) : null)
                                ->required()->live()
                                ->in(static fn (): array => array_keys(self::appOptions()))
                                ->afterStateUpdated(static fn (Set $set) => $set('custom_table_id', null)),
                            Select::make('custom_table_id')->label(__('documents.templates.fields.table'))
                                ->options(static fn (Get $get): array => filled($get('app_id')) ? self::tableOptions((int) $get('app_id')) : [])
                                ->required()->live()->searchable()
                                ->helperText(__('documents.templates.fields.table_help'))
                                ->afterStateUpdated(static function (Set $set, Get $get, ?string $operation) use ($table): void {
                                    $set('language_field', null);
                                    $set('file_field', null);
                                    $set('sample_id', null);

                                    if ($operation === 'create' && ($t = $table($get)) !== null) {
                                        self::applySkeleton($set, (string) ($get('skeleton') ?? TemplateSkeletons::INVOICE), $t);
                                    }
                                }),
                            Select::make('skeleton')->label(__('documents.templates.fields.skeleton'))
                                ->options(collect(TemplateSkeletons::KINDS)->mapWithKeys(static fn (string $kind): array => [$kind => __('documents.templates.kinds.'.$kind)])->all())
                                ->default(TemplateSkeletons::INVOICE)
                                ->selectablePlaceholder(false)
                                ->helperText(__('documents.templates.fields.skeleton_help'))
                                ->dehydrated(false)->live()
                                ->visibleOn('create')
                                ->afterStateUpdated(static function (Set $set, Get $get, ?string $state) use ($table): void {
                                    if (($t = $table($get)) !== null) {
                                        self::applySkeleton($set, (string) $state, $t);
                                    }
                                }),
                            TextInput::make('file_name')->label(__('documents.templates.fields.file_name'))->maxLength(200)
                                ->helperText(__('documents.templates.fields.file_name_help'))
                                ->live(debounce: self::PREVIEW_DEBOUNCE_MS),
                            Select::make('language_field')->label(__('documents.templates.fields.language_field'))
                                ->options(static fn (Get $get): array => ($t = $table($get)) === null ? [] : TemplateFields::fields($t)
                                    ->filter(static fn (CustomField $f): bool => in_array($f->valueType(), [FieldType::String, FieldType::Select], true))
                                    ->mapWithKeys(static fn (CustomField $f): array => [$f->name => $f->label.' ('.$f->name.')'])->all())
                                ->placeholder(__('documents.templates.fields.language_none'))
                                ->helperText(__('documents.templates.fields.language_field_help'))
                                ->live(),
                            Select::make('file_field')->label(__('documents.templates.fields.file_field'))
                                ->options(static fn (Get $get): array => ($t = $table($get)) === null ? [] : TemplateFields::fields($t)
                                    ->filter(static fn (CustomField $f): bool => $f->valueType() === FieldType::File
                                        && (($kinds = (array) ($f->optionsArray()['kinds'] ?? [])) === [] || array_intersect(['pdf', FileKinds::ANY], $kinds) !== []))
                                    ->mapWithKeys(static fn (CustomField $f): array => [$f->name => $f->label.' ('.$f->name.')'])->all())
                                ->placeholder(__('documents.templates.fields.file_field_none'))
                                ->helperText(__('documents.templates.fields.file_field_help')),
                            Toggle::make('is_active')->label(__('documents.templates.fields.is_active'))->default(true)->inline(false),
                            TextInput::make('sort_order')->label(__('documents.templates.fields.sort_order'))->numeric()->integer()->default(0),
                            Textarea::make('recipient')->label(__('documents.templates.fields.recipient'))
                                ->helperText(__('documents.templates.fields.recipient_help'))
                                ->rows(5)->columnSpanFull()
                                ->extraInputAttributes(['data-template-input' => true, 'style' => 'font-family: ui-monospace, monospace; font-size: .85rem'])
                                ->live(debounce: self::PREVIEW_DEBOUNCE_MS),
                            CodeEditor::make('body')->label(__('documents.templates.fields.body'))
                                ->helperText(__('documents.templates.fields.body_help'))
                                ->language(Language::Html)
                                ->required()->columnSpanFull()
                                ->live(debounce: self::PREVIEW_DEBOUNCE_MS)
                                ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $t = filled($get('custom_table_id')) ? CustomTable::query()->find((int) $get('custom_table_id')) : null;

                                    if ($t === null) {
                                        return;
                                    }

                                    $errors = app(TemplateChecker::class)->check($t, (string) $value, (string) $get('recipient'), (string) $get('file_name'))['errors'];

                                    if ($errors !== []) {
                                        $fail(implode(' · ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : ''));
                                    }
                                }),
                        ]),
                    Section::make(__('documents.templates.sections.preview'))
                        ->columnSpan(['default' => 1, 'xl' => 2])
                        ->schema([
                            Select::make('sample_id')->label(__('documents.templates.fields.sample'))
                                ->options(static fn (Get $get): array => ($t = $table($get)) === null ? [] : self::sampleOptions($t))
                                ->placeholder(__('documents.templates.fields.sample_first'))
                                ->searchable()->dehydrated(false)->live(),
                            View::make('filament.components.template-preview'),
                            View::make('filament.components.template-picker')
                                ->viewData(static fn (Get $get): array => ['groups' => ($t = $table($get)) === null ? [] : self::pickerGroups($t)]),
                        ]),
                ]),
            ]);
    }

    /** @return array<int, string> */
    public static function appOptions(): array
    {
        $user = auth()->user();
        $ids = $user instanceof User ? DocumentTemplateResource::appIds($user) : [];

        return App::query()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->map(static fn (mixed $name): string => (string) $name)->all();
    }

    /**
     * Aktive Tabellen der App: id => Bezeichnung, sortiert.
     *
     * @return array<int, string>
     */
    public static function tableOptions(int $appId): array
    {
        return CustomTable::query()->where('app_id', $appId)->where('is_active', true)->get()
            ->mapWithKeys(static fn (CustomTable $t): array => [$t->id => (string) $t->label])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->all();
    }

    private static function applySkeleton(Set $set, string $kind, CustomTable $table): void
    {
        $skeleton = TemplateSkeletons::make($kind, $table);
        $set('recipient', $skeleton['recipient']);
        $set('body', $skeleton['body']);
        $set('file_name', $skeleton['file_name']);
    }

    /**
     * Beispieldatensätze für die Vorschau: die neuesten 100, bezeichnet mit dem eindeutigen Textfeld.
     *
     * @return array<string, string>
     */
    public static function sampleOptions(CustomTable $table): array
    {
        $model = DynamicRecord::forTableName($table->name);
        $label = TemplateFields::fields($table)->first(static fn (CustomField $f): bool => $f->valueType() === FieldType::String && $f->is_unique)
            ?? TemplateFields::fields($table)->first(static fn (CustomField $f): bool => $f->valueType() === FieldType::String);

        return $model->newQuery()->orderByDesc($model->getKeyName())->limit(100)->get()
            ->mapWithKeys(static fn (DynamicRecord $r): array => [(string) $r->getKey() => trim((string) ($label !== null ? $r->getAttribute($label->name) : '')) ?: '#'.$r->getKey()])
            ->all();
    }

    /**
     * Bausteine der Feldauswahl: Felder, Felder über Verknüpfungen, Kind-Tabellen, Absender und Datum.
     *
     * @return list<array{label: string, items: list<array{label: string, insert: string, title: string}>}>
     */
    public static function pickerGroups(CustomTable $table): array
    {
        $item = static fn (CustomField $f, string $prefix = ''): array => [
            'label' => (string) $f->label,
            'insert' => '{{'.$prefix.$f->name.($f->valueType() === FieldType::Text ? ' | Zeilen' : '').'}}',
            'title' => '{{'.$prefix.$f->name.'}} – '.__('documents.templates.types.'.$f->valueType()->value),
        ];
        $groups = [['label' => __('documents.templates.picker.fields', ['table' => $table->label]), 'items' => TemplateFields::fields($table)->map(static fn (CustomField $f): array => $item($f))->values()->all()]];

        foreach (TemplateFields::fields($table) as $field) {
            if (($target = TemplateFields::target($field)) !== null) {
                $groups[] = [
                    'label' => __('documents.templates.picker.relation', ['field' => $field->label, 'table' => $target->label]),
                    'items' => TemplateFields::fields($target)->map(static fn (CustomField $f): array => $item($f, $field->name.'.'))->values()->all(),
                ];
            }
        }

        foreach (TemplateFields::children($table) as $section => $relation) {
            $child = $relation->customTable;
            $groups[] = [
                'label' => __('documents.templates.picker.children', ['table' => $child->label]),
                'items' => [
                    ['label' => '{{#'.$section.'}} … {{/'.$section.'}}', 'insert' => "{{#{$section}}}\n\n{{/{$section}}}", 'title' => __('documents.templates.picker.section')],
                    ...TemplateFields::fields($child)->reject(static fn (CustomField $f): bool => $f->is($relation))->map(static fn (CustomField $f): array => $item($f))->values()->all(),
                ],
            ];
        }

        $sender = __('documents.settings.sender_fields');
        $groups[] = [
            'label' => __('documents.templates.picker.general'),
            'items' => [
                ...array_map(static fn (string $key): array => [
                    'label' => (string) ($sender[$key] ?? $key),
                    'insert' => '{{absender.'.$key.($key === 'adresse' ? ' | Zeilen' : '').'}}',
                    'title' => '{{absender.'.$key.'}}',
                ], LetterheadResolver::SENDER_KEYS),
                ['label' => __('documents.templates.picker.today'), 'insert' => '{{heute | DatumLang}}', 'title' => '{{heute}} · {{heute | DatumLang}}'],
            ],
        ];
        $groups[] = [
            'label' => __('documents.templates.picker.filters'),
            'items' => array_map(static fn (string $filter): array => [
                'label' => '| '.$filter,
                'insert' => ' | '.$filter,
                'title' => __('documents.templates.filters.'.str_replace(':', '_', $filter)),
            ], ['CHF', 'Zahl:2', 'Zahl:0', 'Datum', 'DatumLang', 'Zeilen', 'Roh', 'Gross']),
        ];

        return $groups;
    }

    /** Vorlage aus dem (ungespeicherten) Formularstand. */
    public static function transient(array $data, ?DocumentTemplate $record = null): ?DocumentTemplate
    {
        if (blank($data['custom_table_id'] ?? null) || blank($data['app_id'] ?? null)) {
            return null;
        }

        $template = new DocumentTemplate([
            'app_id' => (int) $data['app_id'],
            'custom_table_id' => (int) $data['custom_table_id'],
            'name' => (string) ($data['name'] ?? '') ?: ($record?->name ?? __('documents.templates.model')),
            'recipient' => (string) ($data['recipient'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'file_name' => (string) ($data['file_name'] ?? ''),
            'language_field' => filled($data['language_field'] ?? null) ? (string) $data['language_field'] : null,
        ]);

        return $template;
    }
}
