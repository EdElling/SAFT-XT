<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Handbuch einer App in einer Sprache (Markdown, B-107). Erscheint unter „Dokumentation“ und im Hilfe-Assistenten,
 * solange die App die aktuelle ist; fehlt die Sprache, gilt die Vorgabesprache.
 *
 * @property int $id
 * @property int $app_id
 * @property string $locale
 * @property string $content
 */
class AppManual extends Model
{
    use HasAuditLog;

    protected $table = 'app_manuals';

    protected $fillable = ['app_id', 'locale', 'content'];

    protected $casts = ['app_id' => 'int'];

    /** Inhalt nicht ins Änderungsprotokoll (kann lang sein); das Hochladen protokolliert die Seite „Apps“ selbst. */
    protected array $auditExcept = ['content'];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
