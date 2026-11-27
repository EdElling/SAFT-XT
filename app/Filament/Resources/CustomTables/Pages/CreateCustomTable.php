<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\Pages;

use App\DynamicData\Assistant\TableAssistant;
use App\DynamicData\Assistant\TableAssistantResult;
use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\CustomTables\CustomTableResource;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Filament\Support\AppSelect;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateCustomTable extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = CustomTableResource::class;

    /** KI-Assistent: Eingabe, Gesprächsverlauf (für Nachfragen), letzte Antwort bzw. Rückfrage */
    public string $assistantPrompt = '';

    /** @var list<array{role: string, content: string}> */
    public array $assistantHistory = [];

    public ?string $assistantReply = null;

    public ?string $assistantReplyKind = null;

    /** Beschreibung an den Assistenten; ein gültiger Vorschlag füllt das Formular (angelegt wird erst mit „Erstellen“). */
    public function askTableAssistant(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && TableAssistant::availableFor($user), 403);

        try {
            $result = app(TableAssistant::class)->proposeTable($user, $this->assistantPrompt, $this->form->getRawState(), $this->assistantHistory);
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->assistantReplyKind = $result->status;
        $this->assistantReply = $result->message;

        if ($result->status === TableAssistantResult::ERROR) {
            return;
        }

        $this->assistantHistory = $result->history;
        $this->assistantPrompt = '';

        if ($result->status === TableAssistantResult::PROPOSAL) {
            // App aus dem Vorschlag (im Text genannt), sonst die gewählte (B-107); der Name bekommt ihr Präfix.
            $proposal = $result->proposal ?? [];
            $appId = $proposal['app_id'] ?? $this->form->getRawState()['app_id'] ?? null;
            $prefix = AppSelect::prefix($appId);

            if ($prefix !== '' && filled($proposal['name'] ?? null) && ! str_starts_with((string) $proposal['name'], $prefix)) {
                $proposal['name'] = mb_substr($prefix.$proposal['name'], 0, 63);
            }

            $this->form->fill([...$proposal, 'app_id' => $appId]);
            $this->assistantReply = __('tables_assistant.applied_form');
            // Vorgeschlagene Felder eingeklappt zeigen (Kopfzeile: Bezeichnung · Typ · Eigenschaften); von Hand ergänzte bleiben offen.
            $this->js("window.dispatchEvent(new CustomEvent('repeater-collapse', { detail: 'data.fields' }))");
        }
    }

    public function resetTableAssistant(): void
    {
        $this->assistantHistory = [];
        $this->assistantReply = null;
        $this->assistantReplyKind = null;
        $this->assistantPrompt = '';
    }

    /** Anlegen ausschließlich über den Service (Metadaten + physische Tabelle). */
    protected function handleRecordCreation(array $data): Model
    {
        $table = app(DynamicSchemaService::class)->createTable(
            Arr::only($data, ['app_id', 'name', 'label', 'description', 'icon', 'is_active', 'export_enabled', 'import_enabled', 'bulk_enabled']),
            array_map(FieldForm::toServiceData(...), array_values((array) ($data['fields'] ?? []))),
        );

        if (filled($data['menu_group'] ?? null)) {
            app(DataMenu::class)->create($table, (string) $data['menu_group']);
        }

        return $table;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
