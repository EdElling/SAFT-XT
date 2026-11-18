<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Models\Base\App as BaseApp;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Models\Concerns\HasTranslatedAttributes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Fachanwendung (B-107): fasst Tabellen, Menüs, Auswertungen, Tabellenverknüpfungen, Rollen und Aufgaben
 * zusammen, die fachlich zusammengehören (siehe docs/concept/APPS.md).
 */
class App extends BaseApp
{
    use HasAuditLog;
    use HasStableKey;
    use HasTranslatedAttributes;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** Spiegelt die DB-Defaults, damit das Audit-Log keine Phantom-Änderungen erzeugt. */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    /** Was an einer App hängt (Relation => Übersetzungsschlüssel für die Anzeige). */
    public const array PARTS = [
        'customTables' => 'tables',
        'menus' => 'menus',
        'reports' => 'reports',
        'reportLinks' => 'report_links',
        'roles' => 'roles',
        'scheduledTasks' => 'tasks',
    ];

    protected static function booted(): void
    {
        // Nur leere Apps lassen sich löschen; Entfernen samt Inhalt kommt mit dem Lebenszyklus (Etappe 3).
        static::deleting(static function (self $app): void {
            if (! $app->isEmpty()) {
                throw new BusinessRuleException(__('apps.not_empty', ['name' => (string) $app->name]));
            }
        });
    }

    /**
     * App für neue Objekte: die angegebene (Modell oder ID) oder – ohne Angabe – die einzige vorhandene. Gibt es keine
     * oder mehrere, muss sie angegeben werden.
     *
     * @throws BusinessRuleException
     */
    public static function resolve(self|int|string|null $app): self
    {
        if ($app instanceof self) {
            return $app;
        }

        if (filled($app)) {
            return self::query()->find((int) $app) ?? throw new BusinessRuleException(__('apps.errors.unknown'));
        }

        $apps = self::query()->limit(2)->get();

        return $apps->count() === 1 ? $apps->first() : throw new BusinessRuleException(__('apps.errors.required'));
    }

    /** ID der einzigen App; null, wenn es keine oder mehrere gibt. */
    public static function onlyId(): ?int
    {
        $ids = self::query()->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    public function customTables(): HasMany
    {
        return $this->hasMany(CustomTable::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function reportLinks(): HasMany
    {
        return $this->hasMany(ReportLink::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    public function isEmpty(): bool
    {
        foreach (array_keys(self::PARTS) as $relation) {
            if ($this->{$relation}()->exists()) {
                return false;
            }
        }

        return true;
    }

    /** Präfix für neue Tabellen, z. B. „lager_“; leer, wenn die App keines hat. */
    public function tablePrefix(): string
    {
        $prefix = strtolower(trim((string) $this->prefix));

        return $prefix === '' ? '' : rtrim($prefix, '_').'_';
    }
}
