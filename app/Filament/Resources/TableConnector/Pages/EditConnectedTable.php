<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\Pages;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\SchemaComparison;
use App\ExternalData\TableConnectorService;
use App\Filament\Concerns\DetectsConcurrentEdits;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\TableConnector\Actions\AssignConnectionAction;
use App\Filament\Resources\TableConnector\TableConnectorResource;
use App\Filament\Support\CreateMenuEntryAction;
use App\Models\CustomTable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

class EditConnectedTable extends EditRecord
{
    use DetectsConcurrentEdits;
    use LimitsFormWidth;

    protected static string $resource = TableConnectorResource::class;

    /** Abweichungen vom Schema der externen Datenbank (beim Öffnen ermittelt); leer = keine. */
    public array $schemaLines = [];

    /** Schema konnte nicht gelesen werden (z. B. Verbindung nicht erreichbar). */
    public ?string $schemaError = null;

    /** Garantiert die Datenbank den Schlüssel als eindeutig? Sonst kein Schreiben. */
    public bool $keyGuaranteed = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->checkSchema();
        $this->checkKey();
    }

    protected function afterSave(): void
    {
        $this->checkKey();
    }

    private function checkKey(): void
    {
        /** @var CustomTable $table */
        $table = $this->getRecord();
        $this->keyGuaranteed = app(TableConnectorService::class)->keyGuaranteed($table);
    }

    public function checkSchema(): void
    {
        /** @var CustomTable $table */
        $table = $this->getRecord();

        try {
            $this->schemaLines = app(SchemaComparison::class)->compare($table)->lines();
            $this->schemaError = null;
        } catch (BusinessRuleException $e) {
            [$this->schemaLines, $this->schemaError] = [[], $e->getMessage()];
        } catch (Throwable) {
            [$this->schemaLines, $this->schemaError] = [[], __('table_connector.unreachable', ['connection' => (string) $table->databaseConnection?->name])];
        }
    }

    /** Liste der Abweichungen für Hinweis und Bestätigung. */
    public static function schemaList(array $lines): HtmlString
    {
        return new HtmlString('<ul class="list-disc ps-5">'.implode('', array_map(static fn (string $line): string => '<li>'.e($line).'</li>', $lines)).'</ul>');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CustomTable $record */
        $record = $this->getRecord();

        return [
            ...$data,
            'label' => $record->getTranslations('label'),
            'description' => $record->getTranslations('description'),
            'key_columns' => $record->keyColumns(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CustomTable $record */
        return app(TableConnectorService::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            AssignConnectionAction::make()->after(fn () => $this->redirect(static::getUrl(['record' => $this->getRecord()]))),
            Action::make('openData')
                ->label(__('tables.actions.open_data'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(fn (CustomTable $record): string => DynamicDataPage::urlFor($record->name))
                ->visible(fn (CustomTable $record): bool => $record->is_active),
            Action::make('refreshSchema')
                ->label(__('table_connector.actions.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->color(fn (): string => $this->schemaLines === [] ? 'gray' : 'warning')
                ->authorize(fn (): bool => TableConnectorResource::canEdit($this->getRecord()))
                ->modalHeading(__('table_connector.actions.refresh_heading'))
                ->modalDescription(fn (): HtmlString => $this->schemaLines === []
                    ? new HtmlString(e(__('table_connector.schema.none')))
                    : self::schemaList($this->schemaLines))
                ->modalSubmitActionLabel(__('table_connector.actions.refresh_submit'))
                ->mountUsing(fn () => $this->checkSchema())
                ->action(function (CustomTable $record): void {
                    $notices = app(TableConnectorService::class)->refresh($record);
                    Notification::make()
                        ->title(__('table_connector.schema.refreshed'))
                        ->body($notices === [] ? null : self::schemaList($notices))
                        ->success()
                        ->send();

                    // Formular, Spaltenliste und Hinweis neu aufbauen.
                    $this->redirect(static::getUrl(['record' => $record]));
                }),
            CreateMenuEntryAction::make(),
            DeleteAction::make()
                ->label(__('table_connector.actions.disconnect'))
                ->modalHeading(__('table_connector.actions.disconnect_heading'))
                ->modalDescription(__('table_connector.actions.disconnect_description'))
                ->using(function (CustomTable $record): bool {
                    app(TableConnectorService::class)->disconnect($record);

                    return true;
                }),
        ];
    }
}
