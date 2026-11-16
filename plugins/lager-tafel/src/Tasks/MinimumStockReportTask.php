<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Tasks;

use App\Models\User;
use App\Plugins\Api\Saft;
use App\Tasks\TaskResult;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Saft\LagerTafel\LagerTafelPlugin;
use Saft\LagerTafel\Pages\Lagerspiegel;
use Saft\LagerTafel\Warehouse;

/**
 * A.8 – Geplante Aufgabe „Mindestbestand melden“: Liegen Artikel unter ihrem Mindestbestand, erhält jede aktive
 * Person mit dem Recht „Lager-Tafel: Lagerspiegel anzeigen“ eine Benachrichtigung in die Glocke, mit Link auf den
 * Lagerspiegel. Ohne solche Artikel wird niemand benachrichtigt.
 */
final readonly class MinimumStockReportTask implements TaskType
{
    private const string T = 'lager-tafel::tafel.tasks.minimum.';

    /** Namen in der Benachrichtigung, danach „und n weitere“. */
    private const int NAMES = 5;

    public function key(): string
    {
        return 'lager_tafel_minimum_report';
    }

    public function label(): string
    {
        return __(self::T.'label');
    }

    public function creatable(): bool
    {
        return true;
    }

    public function parameterFields(): array
    {
        return [];
    }

    public function describe(array $parameters): string
    {
        return __(self::T.'describe');
    }

    public function run(array $parameters): TaskResult
    {
        $warehouse = Warehouse::system();

        if (! $warehouse->available()) {
            throw new TaskSkipped(__('lager-tafel::tafel.tasks.unavailable', ['missing' => implode('; ', $warehouse->missing())]));
        }

        if (! $warehouse->hasMinimum()) {
            throw new TaskSkipped(__(self::T.'no_field'));
        }

        $below = $warehouse->belowMinimum();

        if ($below === []) {
            return new TaskResult(__(self::T.'none'), ['articles' => 0, 'notified' => 0]);
        }

        $articles = $warehouse->articles();
        $names = array_map(static fn (int $id): string => $articles[$id] ?? '?', $below);
        $list = implode(', ', array_slice($names, 0, self::NAMES));
        $recipients = $this->recipients();

        foreach ($recipients as $user) {
            Saft::notify($user, Notification::make()
                ->warning()
                ->title(trans_choice(self::T.'title', count($below), ['count' => count($below)]))
                ->body(count($names) > self::NAMES ? __(self::T.'more', ['list' => $list, 'count' => count($names) - self::NAMES]) : $list)
                ->actions([Action::make('open')->label(__(self::T.'open'))->url(Lagerspiegel::getUrl())]));
        }

        return new TaskResult(
            __(self::T.'done', ['count' => count($below), 'people' => count($recipients)]),
            ['articles' => count($below), 'notified' => count($recipients)],
        );
    }

    /**
     * Aktive Personen mit dem Leserecht des Plugins – über Rollen oder direkt; Super-Admins haben es immer.
     *
     * @return list<User>
     */
    private function recipients(): array
    {
        return User::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(static fn (User $user): bool => $user->can(LagerTafelPlugin::ID.'.view'))
            ->values()
            ->all();
    }
}
