<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\RoleSimulation;
use App\Models\Base\Report as BaseReport;
use App\Models\Concerns\BelongsToApp;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gespeicherte Auswertung. Privat (nur Ersteller) oder geteilt; ausgeführt wird immer mit den
 * Datenrechten der aufrufenden Person (siehe ReportRunner).
 */
class Report extends BaseReport
{
    use BelongsToApp;
    use HasAuditLog;
    use HasStableKey;

    protected static function booted(): void
    {
        // Eine Auswertung gehört zur App ihrer Grundtabelle (B-107).
        static::saving(static function (self $report): void {
            $base = (int) (((array) $report->definition)['base'] ?? 0);
            $appId = $base > 0 ? CustomTable::query()->whereKey($base)->value('app_id') : null;

            if ($appId !== null) {
                $report->app_id = (int) $appId;
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function definitionObject(): ReportDefinition
    {
        return ReportDefinition::fromArray((array) $this->definition);
    }

    /** Während der „Ansicht als Rolle“ besitzt niemand eine Auswertung (andere Person mit den Rollen). */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->owner_id === $user->id && ! app(RoleSimulation::class)->isActive();
    }

    /**
     * Eigene und geteilte Auswertungen (ob sie ausführbar sind, entscheiden die Datenrechte).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can('reports.update')) {
            return;
        }

        if (app(RoleSimulation::class)->isActive()) {
            $query->where('is_shared', true);

            return;
        }

        $query->where(fn (Builder $q) => $q->where('owner_id', $user->id)->orWhere('is_shared', true));
    }
}
