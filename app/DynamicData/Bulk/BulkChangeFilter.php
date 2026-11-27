<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\DataAccess;
use App\DynamicData\Ui\BulkUndoActions;
use App\Models\BulkChange as BulkChangeHead;
use App\Models\CustomTable;
use App\Models\User;
use App\Support\AppLocale;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filter „Datensätze einer Sammeländerung“ (B-170, BULK_EDIT.md §14 Frage 8): beschränkt die Datenansicht auf die
 * Schlüssel eines Kopfs (bulk_changes.record_ids). Gesetzt wird er nur als vorübergehende Ansicht über die Adresse
 * ?bulk=<Kopf> (DynamicDataPage::urlFor()); er wird nicht im gespeicherten Tabellenzustand abgelegt. Das Kärtchen
 * beendet die vorübergehende Ansicht und stellt den gespeicherten Stand wieder her.
 *
 * Rechte wie die Liste „Letzte Sammeländerungen“ (BulkChangeAccess::canSee()); ein fremder oder nicht mehr
 * vorhandener Kopf zeigt keine Datensätze.
 */
final class BulkChangeFilter
{
    /** Name des Filters; mit Unterstrich, damit er nie mit einem Feldnamen zusammenfällt. */
    public const string NAME = '_bulk_change';

    /** Livewire-Methode der Seite, die die vorübergehende Ansicht beendet (RemembersTableState). */
    public const string END_HANDLER = 'endTransientTableView';

    public static function make(CustomTable $table): Filter
    {
        $heads = [];
        $head = static function (mixed $id) use ($table, &$heads): ?BulkChangeHead {
            $key = (int) $id;

            if ($key <= 0) {
                return null;
            }

            // Einmal je Anfrage nachschlagen (Abfrage und Kärtchen fragen beide); false = nicht gefunden.
            $heads[$key] ??= self::head($table, $key) ?? false;

            return $heads[$key] ?: null;
        };

        return Filter::make(self::NAME)
            ->schema([Hidden::make('head')])
            ->query(static function (Builder $query, array $data) use ($head): Builder {
                if (blank($data['head'] ?? null)) {
                    return $query;
                }

                $found = $head($data['head']);

                return self::restrict($query, $found === null ? [] : $found->recordIds());
            })
            ->indicateUsing(static function (array $data) use ($head, $table): array {
                $found = blank($data['head'] ?? null) ? null : $head($data['head']);

                return $found === null ? [] : [
                    Indicator::make(__('data.bulk.view.indicator', [
                        'when' => AppLocale::dateTime($found->created_at) ?? '—',
                        'what' => BulkUndoActions::describe($found, $table),
                        'count' => $found->count,
                    ]))->removeLivewireClickHandler(self::END_HANDLER),
                ];
            });
    }

    /** Kopf der Tabelle, den die angemeldete Person sieht; sonst null. */
    public static function head(CustomTable $table, int $id): ?BulkChangeHead
    {
        $user = auth()->user();
        $found = BulkChangeHead::query()->where('custom_table_id', $table->id)->with(['custom_table', 'user', 'undoneBy', 'undoOf'])->find($id);

        return $found !== null && BulkChangeAccess::canSee($user instanceof User ? $user : null, $found) ? $found : null;
    }

    /**
     * Filterstand der vorübergehenden Ansicht: der Kopf und, wer den Papierkorb sieht, „mit gelöschten“ (auch gelöschte
     * Datensätze gehören zu einer Sammelaktion, beim Löschen sogar alle).
     *
     * @return array<string, mixed>
     */
    public static function state(BulkChangeHead $head, CustomTable $table): array
    {
        $filters = [self::NAME => ['head' => $head->id]];

        if (! $table->isExternal() && DataAccess::current($table, 'delete')) {
            $filters['trashed'] = ['value' => true];
        }

        return $filters;
    }

    /**
     * Nur diese Schlüssel (Query Builder, portabel). Bei der Vorgabe APP_DATA_BULK_MAX (5'000) weit unter der Grenze
     * der Parameter je Abfrage (65'535 auf PostgreSQL und MySQL).
     *
     * @param  list<int|string>  $ids
     */
    private static function restrict(Builder $query, array $ids): Builder
    {
        return $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
    }
}
