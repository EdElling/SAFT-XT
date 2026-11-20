<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Exceptions\BusinessRuleException;
use App\Help\HelpAssistant;
use App\Models\AiHelpConversation;
use App\Models\AiHelpMessage;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Hilfe-Assistent: gespeicherte Unterhaltungen der angemeldeten Person, Verlauf, Eingabe. Auf der Seite
 * „Dokumentation“ (Reiter) und im seitlichen Fenster hinter dem Knopf „?“ in der Kopfzeile.
 */
final class HelpAssistantChat extends Component
{
    /** Im seitlichen Fenster: Liste der Unterhaltungen eingeklappt. */
    public bool $compact = false;

    /** Auf der Seite „Dokumentation“: Verweise springen in den Abschnitt. */
    public bool $onDocumentationPage = false;

    public ?int $conversationId = null;

    public string $question = '';

    public ?string $error = null;

    /** @var array{title?: string, path?: string} Seite, auf der die Person gerade ist (vom Knopf „?“) */
    public array $page = [];

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function assistant(): HelpAssistant
    {
        return app(HelpAssistant::class);
    }

    #[On('help-page')]
    public function setPage(string $title = '', string $path = ''): void
    {
        $this->page = ['title' => mb_substr($title, 0, 200), 'path' => mb_substr($path, 0, 300)];

        // Beim Öffnen über „?“: Frage zur aktuellen Maske vorbelegt (vorausgewählt, Überschreiben genügt).
        if (trim($this->question) === '') {
            $this->question = __('help.prefill');
        }
    }

    public function ask(): void
    {
        $this->error = null;
        $conversation = $this->conversationId === null ? null
            : AiHelpConversation::query()->ownedBy($this->user())->find($this->conversationId);

        try {
            $conversation = $this->assistant()->ask($this->user(), $conversation, $this->question, $this->onDocumentationPage ? null : $this->page);
        } catch (BusinessRuleException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->conversationId = $conversation->id;
        $this->question = '';
    }

    public function open(int $id): void
    {
        $this->conversationId = AiHelpConversation::query()->ownedBy($this->user())->whereKey($id)->value('id');
        $this->error = null;
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->question = '';
        $this->error = null;
    }

    public function delete(int $id): void
    {
        AiHelpConversation::query()->ownedBy($this->user())->whereKey($id)->first()?->delete();

        if ($this->conversationId === $id) {
            $this->newConversation();
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $available = $this->assistant()->availableFor($user);
        $conversation = $this->conversationId === null ? null
            : AiHelpConversation::query()->ownedBy($user)->find($this->conversationId);

        return view('livewire.help-assistant-chat', [
            'available' => $available,
            'conversations' => AiHelpConversation::query()->ownedBy($user)->latest('updated_at')->limit(30)->get(['id', 'title', 'updated_at']),
            'messages' => $conversation === null ? [] : $conversation->messages()->get()->map(fn (AiHelpMessage $m): array => [
                'role' => $m->role,
                'html' => $m->role === AiHelpMessage::ASSISTANT ? $this->assistant()->html($user, $m->content, $this->onDocumentationPage) : null,
                'text' => $m->content,
            ])->all(),
            'current' => $conversation,
        ]);
    }
}
