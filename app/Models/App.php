<?php

declare(strict_types=1);

namespace App\Models;

use App\Apps\CurrentApp;
use App\Exceptions\BusinessRuleException;
use App\Models\Base\App as BaseApp;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Models\Concerns\HasTranslatedAttributes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'interface_version' => 1,
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

    /** Schlüssel des Anfrage-Caches der inaktiven Apps. */
    private const string INACTIVE_CACHE = 'saft.apps.inactive';

    protected static function booted(): void
    {
        // Je Request ermittelte aktuelle App (CurrentApp, scoped) verwerfen, sobald sich eine App ändert.
        static::saved(static fn () => app(CurrentApp::class)->forget());
        static::deleted(static fn () => app(CurrentApp::class)->forget());

        // Direkt löschen nur leere Apps; samt Inhalt entfernt AppRemover (Etappe 3), der vorher alles abbaut.
        static::deleting(static function (self $app): void {
            if (! $app->isEmpty()) {
                throw new BusinessRuleException(__('apps.not_empty', ['name' => (string) $app->name]));
            }
        });

        // Solange das Entfernen vorgemerkt ist, bleibt die App inaktiv.
        static::saving(static function (self $app): void {
            if ($app->is_active && $app->removal_due_at !== null) {
                throw new BusinessRuleException(__('apps.removal.cancel_first'));
            }
        });

        static::saved(static fn (): mixed => request()->attributes->remove(self::INACTIVE_CACHE));
        static::deleted(static fn (): mixed => request()->attributes->remove(self::INACTIVE_CACHE));
    }

    /** @return list<int> IDs der deaktivierten Apps (einmal je Anfrage gelesen) */
    public static function inactiveIds(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::INACTIVE_CACHE)) {
            $attributes->set(self::INACTIVE_CACHE, self::query()->where('is_active', false)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        }

        return $attributes->get(self::INACTIVE_CACHE);
    }

    /** Ist die App aktiv? Ohne App (System) immer. */
    public static function isActiveId(?int $id): bool
    {
        return $id === null || ! in_array($id, self::inactiveIds(), true);
    }

    public function removalPending(): bool
    {
        return $this->removal_due_at !== null;
    }

    /** Vorgemerkt und die Wartezeit ist abgelaufen. */
    public function removalDue(): bool
    {
        return $this->removal_due_at !== null && $this->removal_due_at->lte(now());
    }

    /** @return BelongsTo<User, $this> */
    public function removalRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removal_requested_by');
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

    /** @return HasMany<AppBaseline, $this> */
    public function baselines(): HasMany
    {
        return $this->hasMany(AppBaseline::class);
    }

    /** Zuletzt gespeicherter Ausgangsstand (Installation), oder null. */
    public function baseline(): ?AppBaseline
    {
        return $this->baselines()->latest('id')->first();
    }

    /** @return HasMany<AppManual, $this> */
    public function manuals(): HasMany
    {
        return $this->hasMany(AppManual::class);
    }

    /** Handbuch in der Sprache, sonst in der Vorgabesprache; null, wenn es keines gibt. */
    public function manual(?string $locale = null): ?string
    {
        $manuals = $this->manuals->keyBy('locale');
        $manual = $manuals->get($locale ?? app()->getLocale()) ?? $manuals->get((string) config('locales.default')) ?? $manuals->first();

        return $manual?->content;
    }

    /** @return HasMany<CustomTable, $this> */
    public function customTables(): HasMany
    {
        return $this->hasMany(CustomTable::class);
    }

    /** @return HasMany<Menu, $this> */
    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return HasMany<ReportLink, $this> */
    public function reportLinks(): HasMany
    {
        return $this->hasMany(ReportLink::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasMany<ScheduledTask, $this> */
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
