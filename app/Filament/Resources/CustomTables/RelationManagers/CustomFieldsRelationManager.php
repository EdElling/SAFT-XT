<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\RelationManagers;

use App\Apps\AppInterfaces;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Ui\DynamicRecordActions;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Filament\Support\RowIcons;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Services\DynamicSchemaService;
use App\Support\Translations;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Feld-Editor einer Designer-Tabelle. Alle Änderungen laufen über den DynamicSchemaService.
 */
class CustomFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tables.fields.title');
    }

    protected static function getModelLabel(): ?string
    {
        return __('tables.fields.model');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('tables.fields.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var CustomTable $owner */
        $owner = $this->getOwnerRecord();

        return $schema->components(FieldForm::components($owner));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('label')
                    ->label(__('common.label'))
                    // Puzzle-Symbol: ein installiertes Plugin braucht dieses Feld.
                    ->icon(fn (CustomField $record): ?string => FieldForm::pluginHint($record) !== null ? 'heroicon-o-puzzle-piece' : null)
                    ->iconPosition(IconPosition::After)
                    ->tooltip(fn (CustomField $record): ?string => FieldForm::pluginHint($record)),
                TextColumn::make('name')->label(__('tables.fields.column'))->fontFamily('mono'),
                TextColumn::make('type')
                    ->label(__('common.type'))
                    ->badge()
                    ->formatStateUsing(fn (FieldType $state): string => $state->label())
                    ->description(fn (CustomField $record): ?string => self::typeDetails($record)),
                IconColumn::make('is_required')->label(__('tables.fields.required_short'))->boolean(),
                IconColumn::make('is_unique')->label(__('tables.fields.unique'))->boolean(),
                IconColumn::make('show_in_table')->label(__('tables.fields.in_table'))->boolean(),
                IconColumn::make('is_active')->label(__('common.active'))->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('tables.fields.add'))
                    ->modalWidth(Width::SevenExtraLarge)
                    ->using(function (array $data): CustomField {
                        $service = app(DynamicSchemaService::class);
                        $field = $service->addField($this->ownerTable(), FieldForm::toServiceData($data));
                        self::reportRecompute($service->lastRecompute());

                        return $field;
                    }),
            ])
            ->recordActions(RowIcons::of([
                // Breiter als die Vorgabe (4xl): Feldformular mit Optionen, Regeln und Verknüpfung.
                EditAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mutateRecordDataUsing(fn (CustomField $record): array => FieldForm::fillData($record))
                    ->using(function (CustomField $record, array $data): CustomField {
                        $previousOptions = (array) ($record->optionsArray()['options'] ?? []);
                        $service = app(DynamicSchemaService::class);
                        $field = $service->updateField($record, FieldForm::toServiceData($data));
                        self::warnAboutRemovedOptionsInUse($field, $previousOptions);
                        self::reportRecompute($service->lastRecompute());

                        return $field;
                    }),
                // Verlauf des Felds (B-113): wer wann was geändert hat.
                DynamicRecordActions::modelHistory(),
                Action::make('toggleActive')
                    ->label(fn (CustomField $record): string => $record->is_active ? __('tables.fields.deactivate') : __('tables.fields.activate'))
                    // Das Symbol zeigt den Zustand (eingeblendet: Auge, ausgeblendet: durchgestrichen), die Bezeichnung die Aktion.
                    ->icon(fn (CustomField $record): string => $record->is_active ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                    ->color('gray')
                    ->authorize(fn (CustomField $record): bool => auth()->user()?->can('update', $record) ?? false)
                    // Gesperrt, wenn ein Plugin oder eine andere App (Schnittstelle) das Feld braucht – mit Grund im Tooltip.
                    ->disabled(fn (CustomField $record): bool => $record->is_active && self::deactivateHint($record) !== null)
                    ->tooltip(fn (CustomField $record): ?string => $record->is_active ? self::deactivateHint($record) : null)
                    ->requiresConfirmation(fn (CustomField $record): bool => $record->is_active)
                    ->modalDescription(fn (CustomField $record): ?string => $record->is_active
                        ? __('tables.fields.deactivate_help')
                        : null)
                    ->action(function (CustomField $record): void {
                        $service = app(DynamicSchemaService::class);
                        $service->updateField($record, ['is_active' => ! $record->is_active]);
                        self::reportRecompute($service->lastRecompute());
                    }),
                Action::make('dropField')
                    ->label(__('common.delete_permanently'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    // Als Symbol, damit die Feldliste schmal bleibt; Bezeichnung als Tooltip und für Screenreader.
                    ->iconButton()
                    ->disabled(fn (CustomField $record): bool => $record->is_active || FieldForm::pluginHint($record) !== null)
                    ->tooltip(fn (CustomField $record): string => FieldForm::pluginHint($record)
                        ?? ($record->is_active ? __('tables.fields.drop_deactivate_first') : __('common.delete_permanently')))
                    ->authorize(fn (CustomField $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->modalHeading(__('tables.fields.drop_heading'))
                    ->modalDescription(fn (CustomField $record): string => __('tables.fields.drop_description', ['name' => $record->name]))
                    ->schema([
                        // Dateien des Felds werden mitgelöscht (B-021).
                        Callout::make(fn (CustomField $record): ?string => RecordFiles::dropWarning($record->custom_table, $record->name))
                            ->danger()
                            ->visible(fn (CustomField $record): bool => $record->type === FieldType::File && RecordFiles::dropWarning($record->custom_table, $record->name) !== null),
                        TextInput::make('confirmation')
                            ->label(fn (CustomField $record): string => __('tables.confirm_name', ['name' => $record->name]))
                            ->required(),
                    ])
                    ->action(function (CustomField $record, array $data): void {
                        app(DynamicSchemaService::class)->dropField($record, (string) $data['confirmation']);
                        Notification::make()->title(__('tables.fields.dropped', ['name' => $record->name]))->success()->send();
                    }),
            ]));
    }

    /**
     * Nachberechnung nach einer Formeländerung (Fundament 2, F3b): Ist sie nach dem kurzen Mitarbeiten im Request noch
     * offen, läuft sie im Hintergrund weiter – Hinweis mit Link auf die Arbeit; ist sie gescheitert, die Meldung.
     */
    private static function reportRecompute(?WorkItem $item): void
    {
        if ($item === null || $item->status === WorkItem::DONE) {
            return;
        }

        $notification = Notification::make()->actions([
            Action::make('open')->label(__('work.notifications.open'))->url(WorkItemResource::getUrl('view', ['record' => $item])),
        ]);

        if ($item->isOpen()) {
            $notification->info()->title(__('tables.formula.recompute_background'))->send();
        } else {
            $notification->danger()->title((string) $item->message)->persistent()->send();
        }
    }

    private function ownerTable(): CustomTable
    {
        /** @var CustomTable $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }

    /**
     * Daten bleiben beim Entfernen eines Auswahlwerts erhalten, daher nur Warnung statt Sperre.
     *
     * @param  array<int, array{value: string, label?: string}>  $previousOptions
     */
    private static function warnAboutRemovedOptionsInUse(CustomField $field, array $previousOptions): void
    {
        if ($field->type !== FieldType::Select) {
            return;
        }

        $remaining = array_column((array) ($field->optionsArray()['options'] ?? []), 'value');
        $affected = [];

        foreach ($previousOptions as $option) {
            if (in_array($option['value'], $remaining, true)) {
                continue;
            }

            $count = DynamicRecord::forTable($field->customTable)->newQuery()->withTrashed()
                ->where($field->name, $option['value'])
                ->count();

            if ($count > 0) {
                $affected[] = trans_choice('tables.fields.option_usage', $count, ['option' => Translations::pick($option['label'] ?? null, (string) $option['value'])]);
            }
        }

        if ($affected === []) {
            return;
        }

        Notification::make()
            ->title(__('tables.fields.option_removed'))
            ->body(__('tables.fields.option_removed_body', ['usage' => implode(', ', $affected)]))
            ->warning()
            ->persistent()
            ->send();
    }

    private static function typeDetails(CustomField $field): ?string
    {
        $o = $field->optionsArray();

        return match ($field->type) {
            FieldType::String => __('tables.fields.details_length', ['length' => $o['length'] ?? '?']),
            FieldType::Decimal => __('tables.fields.details_decimal', ['precision' => $o['precision'] ?? '?', 'scale' => $o['scale'] ?? '?']),
            FieldType::Select => __('tables.fields.details_options', ['count' => count((array) ($o['options'] ?? []))]),
            FieldType::Relation => RelationDisplay::describe($o),
            FieldType::Computed => '= '.($o['formula'] ?? ''),
            default => null,
        };
    }

    /** Warum sich ein Feld nicht ausblenden lässt (Plugin braucht es, andere App nutzt es), sonst null. */
    private static function deactivateHint(CustomField $field): ?string
    {
        if (($plugin = FieldForm::pluginHint($field)) !== null) {
            return $plugin;
        }

        $table = $field->custom_table;
        $usages = $table === null ? [] : app(AppInterfaces::class)->usages($table, $field->name);

        return $usages === [] ? null : __('apps.errors.interface_field_used', ['name' => $field->name, 'usages' => implode(', ', $usages)]);
    }
}
