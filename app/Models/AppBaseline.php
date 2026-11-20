<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\AppBaseline as BaseAppBaseline;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ausgangsstand einer App (B-107, Etappe 4b): die App-Definition, wie sie installiert wurde, mit Version, Art
 * (installiert/Kopie/aktualisiert), Zeitpunkt und Person. Bezugspunkt für das Aktualisieren (Etappe 6); die Folge der
 * Ausgangsstände ergibt den Verlauf. Kein Änderungsprotokoll: Das Installieren protokolliert sich selbst.
 */
class AppBaseline extends BaseAppBaseline
{
    public const string INSTALLED = 'installed';

    public const string COPY = 'copy';

    /** Aus einem Paket aktualisiert (Etappe 6): das angewendete Paket ist der neue Ausgangsstand. */
    public const string UPDATED = 'updated';

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /** @return list<string> Kennungen der Plugins, die die Definition braucht */
    public function requiredPlugins(): array
    {
        return array_map('strval', array_keys((array) ($this->definition['plugins'] ?? [])));
    }
}
