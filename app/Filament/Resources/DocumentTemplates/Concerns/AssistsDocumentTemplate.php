<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Concerns;

use App\Ai\AiUsage;
use App\Documents\Assistant\TemplateAssistant;
use App\Documents\Assistant\TemplateAssistantResult;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * KI-Assistent auf den Seiten einer Dokumentvorlage (B-124, D2a): Ein gültiger Vorschlag ersetzt Empfänger, Inhalt und
 * Dateiname im Formular (gespeichert wird erst mit „Speichern“); „Rückgängig“ stellt den Stand davor wieder her. Den
 * Namen setzt der Assistent nur, solange er leer ist oder noch vom Assistenten stammt.
 */
trait AssistsDocumentTemplate
{
    public string $assistantPrompt = '';

    /** @var list<array{role: string, content: string}> */
    public array $assistantHistory = [];

    public ?string $assistantReply = null;

    public ?string $assistantReplyKind = null;

    /** Stand vor dem letzten Vorschlag. @var array<string, string>|null */
    public ?array $assistantUndo = null;

    public ?string $assistantName = null;

    private const array ASSISTANT_FIELDS = ['name', 'recipient', 'body', 'file_name'];

    public function askAssistant(): void
    {
        $user = auth()->user();
        $data = (array) $this->data;
        $table = filled($data['custom_table_id'] ?? null) ? CustomTable::query()->find((int) $data['custom_table_id']) : null;

        abort_unless($user instanceof User && $table !== null && TemplateAssistant::availableFor($user, $table->app_id), 403);

        try {
            $result = app(TemplateAssistant::class)->propose($user, $table, $this->assistantPrompt, $data, $this->assistantHistory);
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->assistantReplyKind = $result->status;

        if ($result->status === TemplateAssistantResult::ERROR) {
            $this->assistantReply = $result->message;

            return;
        }

        $this->assistantHistory = $result->history;
        $this->assistantPrompt = '';

        if ($result->status === TemplateAssistantResult::QUESTION || $result->template === null) {
            $this->assistantReply = $result->message;

            return;
        }

        $this->assistantUndo = array_map(static fn (mixed $v): string => (string) $v, array_intersect_key($data + array_fill_keys(self::ASSISTANT_FIELDS, ''), array_flip(self::ASSISTANT_FIELDS)));
        $proposal = $result->template;
        $name = $data['name'] ?? null;

        if (filled($proposal['name']) && (blank($name) || $name === $this->assistantName)) {
            $this->data['name'] = $proposal['name'];
            $this->assistantName = $proposal['name'];
        }

        $this->data['recipient'] = $proposal['recipient'];
        $this->data['body'] = $proposal['body'];

        if (filled($proposal['file_name'])) {
            $this->data['file_name'] = $proposal['file_name'];
        }

        $this->assistantReply = trim(__('documents.assistant.applied').' '.(string) $result->message);
        $this->refreshPreview();
    }

    public function undoAssistant(): void
    {
        if ($this->assistantUndo === null) {
            return;
        }

        foreach ($this->assistantUndo as $field => $value) {
            $this->data[$field] = $value;
        }

        $this->assistantUndo = null;
        $this->assistantReplyKind = null;
        $this->assistantReply = __('documents.assistant.undone');
        $this->refreshPreview();
    }

    public function resetAssistant(): void
    {
        $this->assistantHistory = [];
        $this->assistantReply = null;
        $this->assistantReplyKind = null;
        $this->assistantPrompt = '';
        $this->assistantUndo = null;
    }

    public function assistantRemaining(): int
    {
        $user = auth()->user();

        return $user instanceof User ? AiUsage::remainingToday($user) : 0;
    }
}
