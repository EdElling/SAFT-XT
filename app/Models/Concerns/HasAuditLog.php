<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Zentrale Audit-Konfiguration für alle geschäftsrelevanten Models.
 *
 * Protokolliert nur geänderte Felder (dirty) inkl. auslösendem User (causer,
 * automatisch über den aktuellen Auth-Guard). Sensible Felder werden nie geloggt.
 * Models können $auditExcept ergänzen oder getActivitylogOptions() überschreiben.
 */
trait HasAuditLog
{
    use LogsActivity;

    /**
     * Maskierte Felder (Model-Property $auditMasked): Änderungen werden protokolliert,
     * der Wert aber nie – z. B. verschlüsselte Secrets.
     */
    protected static function bootHasAuditLog(): void
    {
        static::created(static fn (self $model) => $model->logMaskedChanges(isCreate: true));
        static::updated(static fn (self $model) => $model->logMaskedChanges(isCreate: false));
    }

    protected function logMaskedChanges(bool $isCreate): void
    {
        $masked = $this->auditMaskedAttributes();

        if ($masked === []) {
            return;
        }

        $changed = $isCreate
            ? array_keys(array_filter($this->only($masked), static fn (mixed $v): bool => filled($v)))
            : array_keys(array_intersect_key($this->getChanges(), array_flip($masked)));

        if ($changed === []) {
            return;
        }

        $values = array_fill_keys($changed, AuditMask::VALUE);

        activity($this->getActivitylogOptions()->logName)
            ->performedOn($this)
            ->event($isCreate ? ActivityEvent::Created : ActivityEvent::Updated)
            ->withChanges($isCreate ? ['attributes' => $values] : ['attributes' => $values, 'old' => $values])
            ->log($isCreate ? 'created' : 'updated');
    }

    /**
     * @return list<string>
     */
    protected function auditMaskedAttributes(): array
    {
        return property_exists($this, 'auditMasked') ? $this->auditMasked : [];
    }

    /** @var list<string> */
    private static array $globalAuditExcept = [
        'password',
        'remember_token',
        'token',
        'refresh_token',
        'updated_at',
        // Stabiler Schlüssel (B-107): technisch, unveränderlich, im Protokoll ohne Nutzen.
        'uuid',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(Str::snake(class_basename(static::class)))
            ->logFillable()
            ->logExcept($this->auditExceptAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Protokolliert Änderungen an BelongsToMany-/MorphToMany-Relationen (Pivot),
     * die von logOnlyDirty() nicht erfasst werden. Gleiches Format wie Feldänderungen.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     */
    public function logRelationChange(string $relation, array $old, array $new): void
    {
        sort($old);
        sort($new);

        if ($old === $new) {
            return;
        }

        activity($this->getActivitylogOptions()->logName)
            ->performedOn($this)
            ->event(ActivityEvent::Updated)
            ->withChanges([
                'attributes' => [$relation => $new],
                'old' => [$relation => $old],
            ])
            ->log('updated');
    }

    /**
     * @return list<string>
     */
    protected function auditExceptAttributes(): array
    {
        $modelExcept = property_exists($this, 'auditExcept') ? $this->auditExcept : [];

        return array_values(array_unique([...self::$globalAuditExcept, ...$modelExcept, ...$this->auditMaskedAttributes()]));
    }
}
