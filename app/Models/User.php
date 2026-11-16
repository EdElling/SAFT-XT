<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\User as BaseUser;
use App\Models\Concerns\HasAuditLog;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends BaseUser implements FilamentUser, HasLocalePreference
{
    use HasAuditLog;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    public const string ROLE_SUPER_ADMIN = 'super-admin';

    /** Name der initialen Standardrolle (konfigurierbar in AuthSettings::$default_role). */
    public const string ROLE_DEFAULT = 'benutzer';

    /** Spiegelt die DB-Defaults, damit das Audit-Log keine Phantom-Änderungen erzeugt. */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Ergänzt die generierten Casts der Base-Klasse.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * E-Mails werden normalisiert gespeichert: portabler, case-insensitiver Abgleich
     * (z. B. bei SSO-Verknüpfung) ohne dialektspezifische Funktionen.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => self::normalizeEmail($value));
    }

    /** Eingaben müssen vor dem Abgleich mit gespeicherten Adressen ebenso normalisiert werden. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Auswertungen auf dem persönlichen Dashboard (Widget „Meine Auswertungen“), in eigener Reihenfolge.
     *
     * @return BelongsToMany<Report, $this>
     */
    public function dashboardReports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class, 'dashboard_reports')->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order');
    }

    public function socialAccounts(): HasMany
    {
        return $this->social_accounts();
    }

    /** Sprache für E-Mails an diesen Benutzer, etwa beim Zurücksetzen des Passworts. */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }
}
