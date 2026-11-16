<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Models\Concerns\HasAuditLog;
use App\Settings\AuthSettings;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Paket-Model (kein reliese-Base): erweitert Spatie um die zentrale Audit-Pflicht
 * und schützt systemrelevante Rollen.
 */
class Role extends SpatieRole
{
    use HasAuditLog;

    /** Explizit, da logFillable() sonst bei $guarded = [] nichts protokolliert. */
    protected $fillable = ['name', 'guard_name'];

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
