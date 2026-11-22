<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Schemas;

use App\Documents\Assistant\TemplateAssistant;
use App\Documents\LetterheadResolver;
use App\Documents\QrBill\QrBillFactory;
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
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
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
                            // App zuoberst über die volle Breite der Kachel, über dem KI-Assistenten; vorgewählt die App des
                            // Reiters der Übersicht (?app=…), sonst die einzige.
                            Select::make('app_id')->label(__('documents.templates.fields.app'))
                                ->options(static fn (): array => self::appOptions())
                                ->default(static fn (): ?int => self::defaultApp())
                                ->required()->live()->columnSpanFull()
                                ->in(static fn (): array => array_keys(self::appOptions()))
                                ->afterStateUpdated(static fn (Set $set) => $set('custom_table_id', null)),
                            View::make('filament.components.template-assistant')->columnSpanFull()
                                ->visible(static fn (Get $get): bool => TemplateAssistant::availableFor(
                                    ($user = auth()->user()) instanceof User ? $user : null,
                                    filled($get('app_id')) ? (int) $get('app_id') : null,
                                )),
                            TextInput::make('name')->label(__('documents.templates.fields.name'))->required()->maxLength(150)
                                ->unique(ignoreRecord: true, modifyRuleUsing: static fn (Unique $rule, Get $get): Unique => $rule->where('app_id', $get('app_id'))),
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
                            // Formate (D4b): Word-Fassung aus demselben Inhalt, nur zum Herunterladen.
                            Select::make('formats')->label(__('documents.templates.fields.formats'))
                                ->options(array_combine(DocumentTemplate::FORMATS, array_map(static fn (string $f): string => __('documents.formats.'.$f), DocumentTemplate::FORMATS)))
                                ->default(DocumentTemplate::FORMAT_PDF)->required()->selectablePlaceholder(false)
                                ->helperText(__('documents.templates.fields.formats_help')),
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

                                    $errors = app(TemplateChecker::class)->check($t, (string) $value, (string) $get('recipient'), (string) $get('file_name'), [
                                        'email_to' => $get('email_to'), 'email_subject' => $get('email_subject'), 'email_body' => $get('email_body'), 'email_reply_to' => $get('email_reply_to'),
                                    ], (array) ($get('qr_bill') ?? []))['errors'];

                                    if ($errors !== []) {
                                        $fail(implode(' · ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : ''));
                                    }
                                }),
                            // Versand per E-Mail (B-124, D3b): Platzhalter wie im Empfänger; ohne „An“ kein Versand.
                            Fieldset::make(__('documents.templates.sections.mail'))->columnSpanFull()->columns(2)->schema([
                                TextInput::make('email_to')->label(__('documents.templates.fields.email_to'))->maxLength(500)
                                    ->helperText(__('documents.templates.fields.email_to_help'))
                                    ->extraInputAttributes(['data-template-input' => true])
                                    ->live(debounce: self::PREVIEW_DEBOUNCE_MS),
                                TextInput::make('email_reply_to')->label(__('documents.templates.fields.email_reply_to'))->maxLength(300)
                                    ->helperText(__('documents.templates.fields.email_reply_to_help'))
                                    ->extraInputAttributes(['data-template-input' => true]),
                                TextInput::make('email_subject')->label(__('documents.templates.fields.email_subject'))->maxLength(300)->columnSpanFull()
                                    ->extraInputAttributes(['data-template-input' => true])
                                    ->live(debounce: self::PREVIEW_DEBOUNCE_MS),
                                Textarea::make('email_body')->label(__('documents.templates.fields.email_body'))->rows(6)->columnSpanFull()
                                    ->helperText(__('documents.templates.fields.email_body_help'))
                                    ->extraInputAttributes(['data-template-input' => true, 'style' => 'font-family: ui-monospace, monospace; font-size: .85rem'])
                                    ->live(debounce: self::PREVIEW_DEBOUNCE_MS),
                            ]),
                            self::qrBillFieldset(),
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

    /** App aus der Übersicht (?app=…), sonst die einzige erlaubte. */
    public static function defaultApp(): ?int
    {
        $apps = self::appOptions();
        $requested = (int) request()->query('app', '0');

        return match (true) {
            array_key_exists($requested, $apps) => $requested,
            count($apps) === 1 => (int) array_key_first($apps),
            default => null,
        };
    }

    /**
     * QR-Rechnung (B-124, D4a): Zahlteil mit QR-Code; Zahlungsempfänger aus dem Briefpapier, das Übrige mit Platzhaltern.
     */
    private static function qrBillFieldset(): Fieldset
    {
        $enabled = static fn (Get $get): bool => (bool) $get('qr_bill.enabled');
        $text = static fn (string $key, int $max = 200): TextInput => TextInput::make('qr_bill.'.$key)->label(__('documents.qr.fields.'.$key))
            ->maxLength($max)->extraInputAttributes(['data-template-input' => true])
            ->live(debounce: self::PREVIEW_DEBOUNCE_MS)->visible($enabled);

        return Fieldset::make(__('documents.templates.sections.qr'))->columnSpanFull()->columns(2)->schema([
            Toggle::make('qr_bill.enabled')->label(__('documents.qr.fields.enabled'))->helperText(__('documents.qr.fields.enabled_help'))
                ->live()->columnSpanFull(),
            Radio::make('qr_bill.position')->label(__('documents.qr.fields.position'))
                ->options([QrBillFactory::PAGE => __('documents.qr.positions.page'), QrBillFactory::BOTTOM => __('documents.qr.positions.bottom')])
                ->default(QrBillFactory::PAGE)->live()->columnSpanFull()->visible($enabled),
            $text('amount')->helperText(__('documents.qr.fields.amount_help')),
            Select::make('qr_bill.currency')->label(__('documents.qr.fields.currency'))
                ->options(array_combine(QrBillFactory::CURRENCIES, QrBillFactory::CURRENCIES))->default('CHF')->selectablePlaceholder(false)
                ->live()->visible($enabled),
            $text('reference', 100)->helperText(__('documents.qr.fields.reference_help')),
            $text('message', 300)->helperText(__('documents.qr.fields.message_help')),
            $text('debtor_name', 300)->helperText(__('documents.qr.fields.debtor_help')),
            $text('debtor_street', 300),
            $text('debtor_building_number', 100),
            $text('debtor_postal_code'),
            $text('debtor_city', 300),
            $text('debtor_country')->helperText(__('documents.qr.fields.country_help')),
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
            'email_to' => (string) ($data['email_to'] ?? ''),
            'email_subject' => (string) ($data['email_subject'] ?? ''),
            'email_body' => (string) ($data['email_body'] ?? ''),
            'email_reply_to' => (string) ($data['email_reply_to'] ?? ''),
            'qr_bill' => is_array($data['qr_bill'] ?? null) ? $data['qr_bill'] : null,
        ]);

        return $template;
    }
}
