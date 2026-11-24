<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\DataSnapshot as BaseDataSnapshot;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Datenstand einer angebundenen Tabelle: unveränderliche Kopie zu einem Zeitpunkt (Zeilen in
 * app_data.ds_<tabelle>, Spalte _snapshot). Nur vollständige Stände (complete) werden gelesen.
 */
class DataSnapshot extends BaseDataSnapshot
{
    use HasAuditLog;

    public const string RUNNING = 'running';

    public const string COMPLETE = 'complete';

    public const string FAILED = 'failed';

    /** @return BelongsTo<CustomTable, $this> */
    public function customTable(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Die Arbeit, die den Stand zieht oder gezogen hat (Fundament 2, F3b); leer, wenn sie aufgeräumt wurde.
     *
     * @return BelongsTo<WorkItem, $this>
     */
    public function work(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    public function isComplete(): bool
    {
        return $this->status === self::COMPLETE;
    }
}
