<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\User as BaseUser;
use App\Models\Concerns\HasAuditLog;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

class User extends BaseUser implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference
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
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
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

    /** @return HasMany<SocialAccount, $this> */
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

    /**
     * Alle Super-Admins (z. B. für Benachrichtigungen). Die einzige Stelle neben isSuperAdmin(), die die
     * Rolle am Namen erkennt (tests/Unit/ArchitectureTest.php).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function superAdmins(Builder $query): void
    {
        $query->role(self::ROLE_SUPER_ADMIN);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    /** Einrichten bzw. Ausschalten in „Meine Einstellungen“ (Filament); im Protokoll nur das Ereignis, nie das Geheimnis. */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $wasEnabled = filled($this->app_authentication_secret);
        $this->forceFill(['app_authentication_secret' => $secret])->saveQuietly();

        if ($wasEnabled !== filled($secret)) {
            $event = filled($secret) ? 'two_factor_enabled' : 'two_factor_disabled';
            activity('auth')->performedOn($this)->causedBy($this)->event($event)->log($event);
        }
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<string>|null gehashte Wiederherstellungscodes */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param  array<string>|null  $codes  gehashte Wiederherstellungscodes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->saveQuietly();
    }
}
