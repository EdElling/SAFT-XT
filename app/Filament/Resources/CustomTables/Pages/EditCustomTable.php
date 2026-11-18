<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\Pages;

use App\DynamicData\Assistant\TableAssistant;
use App\DynamicData\Assistant\TableAssistantResult;
use App\DynamicData\DataAccess;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Rules\TableRule;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\DynamicData\Usages\TableUsages;
use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\CustomTableResource;
use App\Filament\Support\CreateMenuEntryAction;
use App\Models\CustomTable;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Callout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class EditCustomTable extends EditRecord
{
    use LimitsFormWidth;

    protected static string $resource = CustomTableResource::class;

    /**
     * KI-Assistent: Verlauf (Nachfragen) und der zuletzt vorgeschlagene, noch nicht übernommene Satz von Änderungen.
     *
     * @var list<array{role: string, content: string}>
     */
    public array $aiHistory = [];

    /** @var array{add: list<array<string, mixed>>, change: list<array{name: string, data: array<string, mixed>}>}|null */
    public ?array $aiChanges = null;

    /** @var list<string> */
    public array $aiSummary = [];

    /** „Mit KI anpassen“: Beschreibung → Vorschau der Änderungen (aiApply). */
    public function aiAdjustAction(): Action
    {
        return Action::make('aiAdjust')
            ->label(__('tables_assistant.edit_action'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn (): bool => TableAssistant::availableFor(self::user()) && (self::user()?->can('custom_tables.update') ?? false))
            ->modalHeading(__('tables_assistant.edit_heading'))
            ->modalDescription(__('tables_assistant.edit_help'))
            ->schema([
                Textarea::make('prompt')
                    ->label(__('tables_assistant.edit_prompt'))
                    ->placeholder(__('tables_assistant.edit_placeholder'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('assistant.submit'))
            ->action(function (array $data, Action $action): void {
                /** @var CustomTable $table */
                $table = $this->getRecord();

                try {
                    $result = app(TableAssistant::class)->proposeChanges(self::user(), $table, (string) $data['prompt'], $this->aiHistory);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                if ($result->status === TableAssistantResult::ERROR) {
                    Notification::make()->danger()->title((string) $result->message)->send();
                    $action->halt();

                    return;
                }

                $this->aiHistory = $result->history;

                if ($result->status === TableAssistantResult::QUESTION) {
                    Notification::make()->warning()->title(__('assistant.question'))->body((string) $result->message)->persistent()->send();

                    return;
                }

                $this->aiChanges = $result->proposal;
                $this->aiSummary = $result->summary;
                $this->replaceMountedAction('aiApply');
            });
    }

    public function aiApplyAction(): Action
    {
        return Action::make('aiApply')
            ->modalHeading(__('tables_assistant.preview_heading'))
            ->modalDescription(fn (): HtmlString => new HtmlString(e(__('tables_assistant.preview_intro'))
                .'<ul data-table-assistant-preview style="list-style: disc; padding-inline-start: 1.25rem; text-align: start">'
                .implode('', array_map(fn (string $line): string => '<li>'.e($line).'</li>', $this->aiSummary)).'</ul>'))
            ->modalSubmitActionLabel(__('tables_assistant.apply'))
            ->action(function (): void {
                /** @var CustomTable $table */
                $table = $this->getRecord();
                abort_unless(self::user()?->can('custom_tables.update') ?? false, 403);
                $results = app(TableAssistant::class)->apply($table, $this->aiChanges ?? ['add' => [], 'change' => []]);
                $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
                $this->aiChanges = null;

                $failed === []
                    ? Notification::make()->success()->title(__('tables_assistant.applied_all'))->body(implode("\n", array_column($results, 'text')))->send()
                    : Notification::make()->warning()->title(__('tables_assistant.applied_some'))->body(implode("\n", array_column($failed, 'text')))->persistent()->send();

                $this->redirect(static::getUrl(['record' => $table]));
            });
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CustomTable $record */
        $record = $this->getRecord();

        return [...$data, 'validation_rules' => TableRuleNormalizer::toRows($record->loadMissing('fields'))];
    }

    /** @param CustomTable $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(DynamicSchemaService::class)->updateTable($record, Arr::only($data, ['label', 'description', 'icon', 'is_active', 'export_enabled', 'import_enabled', 'detail_field_ids', 'validation_rules']));
    }

    /** Hinweis, wenn vorhandene Datensätze eine Regel schon verletzen (gespeichert wird trotzdem). */
    protected function afterSave(): void
    {
        /** @var CustomTable $record */
        $record = $this->getRecord()->refresh()->load('fields');
        $service = app(DynamicSchemaService::class);
        $lines = [];

        foreach ($record->tableRules() as $rule) {
            if ($rule->source === TableRule::SOURCE_DESIGNER && ($count = $service->ruleViolations($record, $rule)) > 0) {
                $lines[] = trans_choice('tables.rules.violations', $count, ['count' => $count, 'rule' => TableRuleNormalizer::describe($rule, $record)]);
            }
        }

        if ($lines !== []) {
            Notification::make()
                ->title(__('tables.rules.violations_title'))
                ->body(implode(' ', $lines))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->aiAdjustAction(),
            Action::make('openData')
                ->label(__('tables.actions.open_data'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (CustomTable $record): bool => DataAccess::current($record, 'view'))
                ->url(fn (CustomTable $record): string => DynamicDataPage::getUrl(['table' => $record->name])),
            CreateMenuEntryAction::make(),
            Action::make('dropTable')
                ->label(__('common.delete_permanently'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                // Immer sichtbar, bei aktiven Tabellen gesperrt mit Hinweis: Schutz vor versehentlichem Löschen.
                ->visible(fn (CustomTable $record): bool => ! $record->isExternal())
                ->disabled(fn (CustomTable $record): bool => $record->is_active)
                ->tooltip(fn (CustomTable $record): ?string => $record->is_active ? __('tables.actions.drop_deactivate_first') : null)
                ->authorize(fn (CustomTable $record): bool => auth()->user()?->can('delete', $record) ?? false)
                ->modalHeading(__('tables.actions.drop_heading'))
                ->modalDescription(fn (CustomTable $record): string => __('tables.actions.drop_description', ['name' => $record->name]))
                ->schema([
                    // Nur zur Information: was nach dem Löschen nicht mehr funktioniert (Details im Reiter „Verwendet in“).
                    Callout::make(__('tables.usages.drop_heading'))
                        ->description(fn (CustomTable $record): string => self::usageSummary($record))
                        ->warning()
                        ->visible(fn (CustomTable $record): bool => app(TableUsages::class)->counts($record) !== []),
                    // Dateien aus Dateifeldern werden mitgelöscht (B-021).
                    Callout::make(fn (CustomTable $record): ?string => RecordFiles::dropWarning($record))
                        ->danger()
                        ->visible(fn (CustomTable $record): bool => RecordFiles::dropWarning($record) !== null),
                    TextInput::make('confirmation')
                        ->label(fn (CustomTable $record): string => __('tables.confirm_name', ['name' => $record->name]))
                        ->required(),
                ])
                ->action(function (CustomTable $record, array $data): void {
                    app(DynamicSchemaService::class)->dropTable($record, (string) $data['confirmation']);

                    Notification::make()->title(__('tables.actions.dropped', ['name' => $record->name]))->success()->send();
                    $this->redirect(static::getResource()::getUrl('index'));
                }),
        ];
    }

    /** „3 Auswertungen, 1 Menüeintrag, 2 Rollen“ für die Warnung beim Löschen. */
    public static function usageSummary(CustomTable $table): string
    {
        $parts = [];

        foreach (app(TableUsages::class)->counts($table) as $area => $count) {
            $parts[] = trans_choice('tables.usages.counts.'.$area, $count, ['count' => $count]);
        }

        return __('tables.usages.drop_description', ['list' => implode(', ', $parts)]);
    }
}
