<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\AppDraft as BaseAppDraft;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entwurf des KI-App-Designers (B-107, Etappe 7a): Gespräch mit der KI und der zuletzt geprüfte Vorschlag als
 * App-Definition saft-app/1 – noch nichts in der Datenbank. „App erzeugen“ installiert ihn; danach verweist der Entwurf
 * auf die App. Je Person mehrere Entwürfe, nur für sie sichtbar.
 */
class AppDraft extends BaseAppDraft
{
    use HasAuditLog;

    public const string DRAFT = 'draft';

    public const string CREATED = 'created';

    /** Der Verlauf ist lang und ändert sich bei jeder Antwort; protokolliert werden Titel, Status und erzeugte App. */
    protected array $auditExcept = ['history', 'proposal', 'definition'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** Tabellen des Entwurfs (Definition), leer ohne Vorschlag. */
    public function tableCount(): int
    {
        return count((array) ($this->definition['tables'] ?? []));
    }
}
