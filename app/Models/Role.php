<?php

declare(strict_types=1);

namespace App\Models;

use App\Apps\SystemObjects;
use App\DynamicData\DataPermissions;
use App\Exceptions\BusinessRuleException;
use App\Models\Concerns\BelongsToApp;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Settings\AuthSettings;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Paket-Model (kein reliese-Base): erweitert Spatie um die zentrale Audit-Pflicht
 * und schützt systemrelevante Rollen.
 */
class Role extends SpatieRole
{
    use BelongsToApp;
    use HasAuditLog;
    use HasStableKey;

    /** Explizit, da logFillable() sonst bei $guarded = [] nichts protokolliert. */
    protected $fillable = ['name', 'guard_name', 'uuid', 'app_id'];

    protected $casts = ['app_id' => 'int'];

    /**
     * Kopie als Vorlage für eine neue Rolle (B-107): Funktionsrechte, Datenrechte auf Tabellen der Ziel-App und
     * ausgeblendete Menüeinträge – ohne Mitglieder.
     */
    public function copyAs(string $name, ?int $appId): self
    {
        $tables = CustomTable::query()->when($appId !== null, static fn ($q) => $q->where('app_id', $appId))->pluck('name')->all();
        $permissions = $this->permissions->filter(static function ($permission) use ($tables): bool {
            if (! str_starts_with($permission->name, DataPermissions::PREFIX)) {
                return true;
            }

            $table = explode('.', substr($permission->name, strlen(DataPermissions::PREFIX)))[0];

            return in_array($table, $tables, true);
        });

        $copy = self::query()->create(['name' => $name, 'guard_name' => $this->guard_name, 'app_id' => $appId]);
        $copy->syncPermissions($permissions);
        $copy->hiddenMenus()->sync($this->hiddenMenus()->pluck('menus.id')->all());

        return $copy;
    }

    /** Menüeinträge, die diese Rolle ausblendet (siehe Menu::visibleFor). */
    public function hiddenMenus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'menu_role_hidden');
    }

    /**
     * Schutz auf Model-Ebene (zusätzlich zur Policy): greift auch außerhalb der UI.
     */
    protected static function booted(): void
    {
        // Gibt es genau eine App, gehören neue Rollen ohne Angabe zu ihr (B-107); Systemrollen zu keiner.
        static::creating(static function (self $role): void {
            if ($role->app_id === null && ! SystemObjects::isSystemRole($role->name)) {
                $role->app_id = App::onlyId();
            }
        });

        static::deleting(static function (self $role): void {
            $role->assertNotProtected();
        });

        static::updating(static function (self $role): void {
            if ($role->isDirty('name')) {
                $role->assertNotProtected();
            }
        });
    }

    /** Super-Admin-Rolle und konfigurierte Standardrolle dürfen weder gelöscht noch umbenannt werden. */
    private function assertNotProtected(): void
    {
        $name = $this->getOriginal('name');

        if ($name === User::ROLE_SUPER_ADMIN) {
            throw new BusinessRuleException(__('errors.rules.super_admin_role_protected'), 'role.protected');
        }

        if ($name === app(AuthSettings::class)->default_role) {
            throw new BusinessRuleException(__('errors.rules.default_role_protected'), 'role.default_protected');
        }
    }
}
