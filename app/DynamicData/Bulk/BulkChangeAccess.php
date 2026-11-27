<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\DataAccess;
use App\Models\BulkChange as BulkChangeHead;
use App\Models\CustomTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Wer sieht welche Sammelaktionen in „Letzte Sammeländerungen“ und darf sie zurücknehmen (B-133 S3, BULK_EDIT.md §13
 * Frage 9): eigene mit dem Recht der Tabelle (ändern und Schalter „Sammeländerung erlauben“ bzw. löschen), fremde
 * zusätzlich mit dem Recht, das ganze Änderungsprotokoll zu lesen (activity_log.view; der Super-Admin hat es).
 */
final class BulkChangeAccess
{
    /** Recht, fremde Sammelaktionen zu sehen und zurückzunehmen. */
    public const string OTHERS_PERMISSION = 'activity_log.view';

    /** Darf die Person Sammelaktionen dieser Art auf der Tabelle zurücknehmen (ohne Blick auf den einzelnen Kopf)? */
    public static function allowsOperation(?User $user, CustomTable $table, string $operation): bool
    {
        if ($user === null || $table->isExternal()) {
            return false;
        }

        return $operation === BulkChangeHead::UPDATE
            ? BulkChange::supports($table) && DataAccess::allows($user, $table, 'update')
            : DataAccess::allows($user, $table, 'delete');
    }

    public static function seesOthers(?User $user): bool
    {
        return $user !== null && $user->can(self::OTHERS_PERMISSION);
    }

    /** Sieht die Person den Kopf in der Liste? */
    public static function canSee(?User $user, BulkChangeHead $head): bool
    {
        $table = $head->custom_table;

        return $user !== null && $table instanceof CustomTable
            && self::allowsOperation($user, $table, $head->operation)
            && ($head->user_id === $user->id || self::seesOthers($user));
    }

    /** Darf die Person die Sammelaktion jetzt zurücknehmen (Recht und Stand: nicht oder nur teilweise zurückgenommen)? */
    public static function canUndo(?User $user, BulkChangeHead $head): bool
    {
        return self::canSee($user, $head) && self::undoable($head);
    }

    /** Noch nicht zurückgenommen, oder die Rücknahme hat Datensätze übersprungen (erneut starten). */
    public static function undoable(BulkChangeHead $head): bool
    {
        return $head->undone_by_id === null || self::partial($head->undoneBy);
    }

    /** Hat eine Rücknahme Datensätze übersprungen? */
    public static function partial(?BulkChangeHead $undo): bool
    {
        return $undo !== null && (int) (($undo->result ?? [])['skipped'] ?? 0) > 0;
    }

    /**
     * Die letzten Sammelaktionen der Tabelle, die die Person sieht, neueste zuerst.
     *
     * @return Collection<int, BulkChangeHead>
     */
    public static function recent(?User $user, CustomTable $table, int $limit = BulkChangeHead::LIST_LIMIT): Collection
    {
        $operations = array_values(array_filter(
            [BulkChangeHead::UPDATE, BulkChangeHead::DELETE, BulkChangeHead::RESTORE],
            static fn (string $operation): bool => self::allowsOperation($user, $table, $operation),
        ));

        if ($user === null || $operations === []) {
            return new Collection;
        }

        return BulkChangeHead::query()
            ->where('custom_table_id', $table->id)
            ->whereIn('operation', $operations)
            ->when(! self::seesOthers($user), static fn (Builder $query) => $query->where('user_id', $user->id))
            ->with(['user', 'undoneBy.user', 'undoOf', 'custom_table'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
