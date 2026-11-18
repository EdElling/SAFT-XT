<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\Pages;

use App\DynamicData\DataMenu;
use App\DynamicData\Import\DataImporter;
use App\DynamicData\Import\ImportAnalysis;
use App\DynamicData\Import\ImportAnalyzer;
use App\DynamicData\Import\ImportColumn;
use App\DynamicData\Import\ImportFormat;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\IdentifierValidator;
use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\CustomTables\CustomTableResource;
use App\Filament\Support\AppSelect;
use App\Filament\Support\MenuGroupSelect;
use App\Filament\Support\TableLabelHint;
use App\Filament\Support\TableNameRule;
use App\Models\CustomTable;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Assistent „Tabelle aus Datei importieren“: Datei prüfen, Spalten anpassen, Tabelle samt
 * Menüeintrag anlegen. Die Datei bleibt im temporären Upload-Verzeichnis von Livewire
 * (räumt alte Dateien selbst auf) und wird nach dem Import gelöscht.
 */
class ImportCustomTable extends Page
{
    use LimitsFormWidth;

    protected static string $resource = CustomTableResource::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * Ergebnis der letzten Prüfung als einfache Werte (Livewire-tauglich).
     *
     * @var array{importable: bool, errors: list<string>, warnings: list<string>, rows: int, format: string|null, delimiter: string|null, encoding: string|null, sheet: string|null, sheets: list<string>, columns: list<array<string, mixed>>}|null
     */
    public ?array $report = null;

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('import', CustomTable::class) ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return __('import.wizard.title');
    }

    public function mount(): void
    {
        $appId = count($apps = AppSelect::options()) === 1 ? (int) array_key_first($apps) : null;
        $this->form->fill(['app_id' => $appId, 'menu_group' => app(DataMenu::class)->defaultGroup($appId)]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('file')
                        ->label(__('import.wizard.steps.file'))
                        ->schema([
                            FileUpload::make('file')
                                ->label(__('import.wizard.file'))
                                ->helperText(__('import.wizard.file_help', ['max' => config('dynamic_data.import.max_file_mb')]))
                                ->storeFiles(false)
                                ->maxSize((int) config('dynamic_data.import.max_file_mb') * 1024)
                                ->required()
                                ->live()
                                // Gleich nach dem Hochladen prüfen: Blattauswahl und Fehler stehen vor „Weiter“ bereit.
                                ->afterStateUpdated(function (): void {
                                    $this->data['sheet'] = null;
                                    $this->analyze();
                                }),
                            Select::make('sheet')
                                ->label(__('import.wizard.sheet'))
                                ->helperText(__('import.wizard.sheet_help'))
                                ->options(fn (): array => array_combine($this->report['sheets'] ?? [], $this->report['sheets'] ?? []))
                                ->visible(fn (): bool => count($this->report['sheets'] ?? []) > 1)
                                ->selectablePlaceholder(false)
                                ->native()
                                ->live()
                                ->afterStateUpdated(fn () => $this->analyze()),
                            $this->problemList('errors', 'danger'),
                        ])
                        ->afterValidation(function (): void {
                            // Nur nach neuer Datei bzw. neuem Blatt prüfen: Anpassungen in der Vorschau bleiben erhalten.
                            if ($this->report === null) {
                                $this->analyze();
                            }

                            if (! ($this->report['importable'] ?? false)) {
                                throw new Halt;
                            }
                        }),
                    Step::make('check')
                        ->label(__('import.wizard.steps.check'))
                        ->schema([
                            Text::make(fn (): string => $this->summary()),
                            $this->problemList('warnings', 'warning'),
                        ]),
                    Step::make('columns')
                        ->label(__('import.wizard.steps.columns'))
                        ->schema([$this->columnsRepeater()])
                        ->afterValidation(function (Get $get): void {
                            if (collect((array) $get('columns'))->where('include', true)->isEmpty()) {
                                Notification::make()->title(__('import.wizard.no_columns'))->danger()->send();

                                throw new Halt;
                            }
                        }),
                    Step::make('table')
                        ->label(__('import.wizard.steps.table'))
                        ->schema([
                            // App (B-107) mit Präfix-Vorschlag für den technischen Namen.
                            AppSelect::make()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old): void {
                                    $set('table_name', AppSelect::switchPrefix($get('table_name'), $old, $state));

                                    $set('menu_group', app(DataMenu::class)->defaultGroup(filled($state) ? (int) $state : null));
                                }),
                            Grid::make(2)->schema([
                                TableLabelHint::apply(TextInput::make('table_label'))
                                    ->label(__('import.wizard.table_label'))
                                    ->required()
                                    ->maxLength(255),
                                TableNameRule::apply(TextInput::make('table_name'), physical: true)
                                    ->label(__('import.wizard.table_name'))
                                    ->helperText(__('import.wizard.table_name_help'))
                                    ->required()
                                    ->maxLength(63)
                                    ->rule(fn (): Closure => $this->identifierRule(table: true)),
                            ]),
                            MenuGroupSelect::make(app: fn (Get $get): mixed => $get('app_id')),
                        ]),
                ])
                    ->submitAction(Action::make('import')->label(__('import.wizard.submit'))->submit('import')),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('import'),
        ]);
    }

    public function import(): void
    {
        $data = $this->form->getState();
        $file = $this->uploadedFile();
        abort_unless(static::canAccess() && $file !== null && $this->report !== null, 403);

        $meta = collect($this->report['columns'])->keyBy('index');
        $columns = collect((array) $data['columns'])
            ->where('include', true)
            ->map(fn (array $row): ImportColumn => ImportColumn::fromArray($meta[(int) $row['index']])
                ->withChoice((string) $row['name'], (string) $row['label'], FieldType::from((string) $row['type'])))
            ->values();

        try {
            $result = app(DataImporter::class)->import(
                $file->getRealPath(),
                $file->getClientOriginalName(),
                $this->report['sheet'],
                ['app_id' => $data['app_id'] ?? null, 'name' => (string) $data['table_name'], 'label' => (string) $data['table_label']],
                $columns->all(),
                filled($data['menu_group'] ?? null) ? (string) $data['menu_group'] : null,
                auth()->user() instanceof User ? auth()->user() : null,
            );
        } catch (BusinessRuleException $e) {
            Notification::make()->title(__('import.wizard.failed'))->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        @unlink($file->getRealPath());

        Notification::make()
            ->title(__('import.wizard.created', ['table' => $result->table->label, 'rows' => number_format($result->rows, 0, ',', '\'')]))
            ->success()
            ->send();

        // Mit Menüeintrag direkt zu den Daten, sonst in den Table-Designer.
        $this->redirect($result->menu !== null
            ? route(DataMenu::ROUTE, $result->menu->route_parameters ?? ['table' => $result->table->name])
            : CustomTableResource::getUrl('edit', ['record' => $result->table]));
    }

    /** Prüft die hochgeladene Datei (bzw. das gewählte Blatt) und füllt Bericht und Vorschau. */
    public function analyze(): void
    {
        $file = $this->uploadedFile();

        if ($file === null) {
            $this->report = null;

            return;
        }

        $analysis = app(ImportAnalyzer::class)->analyze($file->getRealPath(), $file->getClientOriginalName(), $this->data['sheet'] ?? null);
        $this->report = $this->toReport($analysis);
        $this->data['sheet'] = $analysis->sheet;
        $this->data['columns'] = array_map(fn (ImportColumn $column): array => [
            'index' => $column->index,
            'include' => true,
            'name' => $column->name,
            'label' => $column->header,
            'type' => $column->type->value,
        ], $analysis->columns);
        $prefix = AppSelect::prefix($this->data['app_id'] ?? null);
        $this->data['table_name'] = $prefix !== '' && ! str_starts_with($analysis->tableName, $prefix) ? $prefix.$analysis->tableName : $analysis->tableName;
        $this->data['table_label'] = $this->tableLabel($file, $analysis);
    }

    private function uploadedFile(): ?TemporaryUploadedFile
    {
        $file = collect((array) ($this->data['file'] ?? []))->first();

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }

    /** @return array<string, mixed> */
    private function toReport(ImportAnalysis $analysis): array
    {
        return [
            'importable' => $analysis->isImportable(),
            'errors' => $analysis->messages('error'),
            'warnings' => $analysis->messages('warning'),
            'rows' => $analysis->rowCount,
            'format' => $analysis->format?->value,
            'delimiter' => $analysis->delimiter,
            'encoding' => $analysis->encoding,
            'sheet' => $analysis->sheet,
            'sheets' => $analysis->sheets,
            'columns' => array_map(fn (ImportColumn $column): array => $column->toArray(), $analysis->columns),
        ];
    }

    private function tableLabel(TemporaryUploadedFile $file, ImportAnalysis $analysis): string
    {
        $base = count($analysis->sheets) > 1 && $analysis->sheet !== null
            ? $analysis->sheet
            : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return mb_substr(trim((string) preg_replace('/[_\s]+/u', ' ', $base)), 0, 255);
    }

    private function summary(): string
    {
        $report = $this->report ?? [];
        $details = match ($report['format'] ?? null) {
            ImportFormat::Csv->value => __('import.wizard.csv_details', [
                'delimiter' => __('import.wizard.delimiters.'.match ($report['delimiter'] ?? ',') {
                    ';' => 'semicolon', "\t" => 'tab', '|' => 'pipe', default => 'comma',
                }),
                'encoding' => $report['encoding'] ?? 'UTF-8',
            ]),
            ImportFormat::Xlsx->value => __('import.wizard.sheet_details', ['sheet' => $report['sheet'] ?? '']),
            default => '',
        };

        $rows = (int) ($report['rows'] ?? 0);
        $columns = count($report['columns'] ?? []);

        return trim(__('import.wizard.summary', [
            'rows' => trans_choice('import.wizard.rows', $rows, ['count' => number_format($rows, 0, ',', '\'')]),
            'columns' => trans_choice('import.wizard.columns_count', $columns, ['count' => $columns]),
        ]).' '.$details);
    }

    /** Liste der Fehler bzw. Warnungen der letzten Prüfung. */
    private function problemList(string $key, string $color): Callout
    {
        return Callout::make(__('import.wizard.'.$key))
            ->description(fn (): HtmlString => new HtmlString('<ul class="list-disc ps-5">'.collect($this->report[$key] ?? [])
                ->map(fn (string $message): string => '<li>'.e($message).'</li>')->implode('').'</ul>'))
            ->color($color)
            ->visible(fn (): bool => ($this->report[$key] ?? []) !== []);
    }

    private function columnsRepeater(): Repeater
    {
        $meta = fn (Get $get): array => collect($this->report['columns'] ?? [])->firstWhere('index', (int) $get('index')) ?? [];

        return Repeater::make('columns')
            ->hiddenLabel()
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->itemLabel(fn (array $state): string => ImportColumn::letterFor((int) ($state['index'] ?? 0)).': '
                .(collect($this->report['columns'] ?? [])->firstWhere('index', (int) ($state['index'] ?? 0))['header'] ?? ''))
            ->schema([
                Hidden::make('index'),
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    Checkbox::make('include')
                        ->label(__('import.wizard.include'))
                        ->live(),
                    TextInput::make('name')
                        ->label(__('import.wizard.field_name'))
                        ->required(fn (Get $get): bool => (bool) $get('include'))
                        ->maxLength(63)
                        ->rule(fn (Get $get): ?Closure => $get('include') ? $this->identifierRule(table: false) : null)
                        // Wie distinct(), aber nur unter den übernommenen Spalten.
                        ->rule(fn (Get $get): ?Closure => $get('include') ? static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $same = collect((array) $get('../../columns'))
                                ->filter(fn (array $row): bool => ($row['include'] ?? false) && mb_strtolower((string) ($row['name'] ?? '')) === mb_strtolower((string) $value));

                            if ($same->count() > 1) {
                                $fail(__('import.wizard.duplicate_name', ['name' => $value]));
                            }
                        } : null),
                    TextInput::make('label')
                        ->label(__('import.wizard.field_label'))
                        ->required(fn (Get $get): bool => (bool) $get('include'))
                        ->maxLength(255),
                    Select::make('type')
                        ->label(__('import.wizard.field_type'))
                        ->options(fn (Get $get): array => collect(isset($meta($get)['type']) ? ImportColumn::fromArray($meta($get))->compatibleTypes() : [])
                            ->mapWithKeys(fn (FieldType $type): array => [$type->value => $type->label()])->all())
                        ->selectablePlaceholder(false)
                        ->required(),
                ]),
                Text::make(fn (Get $get): string => implode(' ', $meta($get)['notes'] ?? []))
                    ->color('gray')
                    ->visible(fn (Get $get): bool => ($meta($get)['notes'] ?? []) !== []),
            ]);
    }

    /** Gleiche Namensregeln wie im Table-Designer (Muster, reservierte Wörter, Systemspalten). */
    private function identifierRule(bool $table): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($table): void {
            try {
                $table
                    ? app(IdentifierValidator::class)->assertValidTableName((string) $value)
                    : app(IdentifierValidator::class)->assertValidColumnName((string) $value);
            } catch (BusinessRuleException $e) {
                $fail($e->getMessage());
            }
        };
    }
}
