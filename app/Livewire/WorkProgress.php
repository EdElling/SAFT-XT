<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Resources\WorkItems\Tables\WorkItemsTable;
use App\Models\WorkItem;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fortschritt einer Arbeit (Fundament 2, F3a) – der Treiber „Browser“: Solange die Arbeit offen ist, fragt die Seite
 * alle 2 s nach. Ist es die startende Person, treibt jede Abfrage die Arbeit ein Zeitbudget lang voran
 * (work.browser_budget, unter max_execution_time); alle anderen sehen nur den Stand. Verlässt die Person die Seite,
 * übernimmt der Scheduler (work:run).
 */
class WorkProgress extends Component
{
    #[Locked]
    public int $workId;

    public function mount(int $workId): void
    {
        $this->workId = $workId;
        $this->authorize('view', $this->item());
    }

    public function advance(WorkRunner $runner): void
    {
        $item = $this->item();

        if ($item->isOpen() && $item->user_id !== null && $item->user_id === Auth::id()) {
            $runner->advance($item, self::budget(), interactive: true);
        }
    }

    public function cancel(WorkService $work): void
    {
        $item = $this->item();
        $this->authorize('cancel', $item);
        $work->cancel($item);
    }

    /** Zeitbudget je Abfrage: work.browser_budget, höchstens max_execution_time − 5 s, mindestens 1 s. */
    public static function budget(): float
    {
        $budget = (float) config('work.browser_budget', 15);
        $limit = (int) ini_get('max_execution_time');

        return max(1.0, $limit > 0 ? min($budget, $limit - 5) : $budget);
    }

    private function item(): WorkItem
    {
        return WorkItem::query()->findOrFail($this->workId);
    }

    public function render(): View
    {
        $item = $this->item();

        return view('livewire.work-progress', [
            'item' => $item,
            'color' => WorkItemsTable::color($item->status),
            'progress' => WorkItemsTable::progress($item),
            'canCancel' => Auth::user()?->can('cancel', $item) ?? false,
            'drives' => $item->isOpen() && $item->user_id !== null && $item->user_id === Auth::id(),
        ]);
    }
}
