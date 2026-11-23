<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

use App\Exceptions\BusinessRuleException;
use App\Models\DynamicRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Der Datensatz wurde seit dem Lesen von jemand anderem geändert (optimistische Sperre, Fundament 2, F2). Die
 * Oberfläche bietet „Neu laden“ und „Trotzdem speichern“ (mit der aktuellen Version). Teil der Plugin-API.
 *
 * @api
 */
final class StaleRecordException extends BusinessRuleException
{
    public const string CODE = 'record_stale';

    public function __construct(
        string $message,
        /** Version des Standes in der Datenbank – mit ihr lässt sich bewusst überschreiben. */
        public readonly string $currentVersion,
        public readonly ?string $changedBy = null,
        public readonly ?Carbon $changedAt = null,
    ) {
        parent::__construct($message, self::CODE, __('errors.titles.stale_record'));
    }

    /** Aus dem aktuellen Stand: wer und wann, soweit die Tabelle es weiss (updated_by, updated_at). */
    public static function for(DynamicRecord $current): self
    {
        $changedAt = $current->hasSoftDeleteColumn() ? $current->getAttribute('updated_at') : null;
        $changedAt = $changedAt instanceof Carbon ? $changedAt : ($changedAt !== null ? Carbon::parse((string) $changedAt) : null);
        $changedBy = $current->hasSoftDeleteColumn() && $current->getAttribute('updated_by') !== null
            ? User::withTrashed()->find($current->getAttribute('updated_by'))?->name
            : null;

        $message = $changedBy !== null && $changedAt !== null
            ? __('errors.rules.stale_record', ['user' => $changedBy, 'time' => $changedAt->format('d.m.Y H:i')])
            : __('errors.rules.stale_record_unknown');

        return new self($message, RecordVersion::of($current), $changedBy, $changedAt);
    }
}
