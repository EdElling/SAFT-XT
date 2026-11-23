<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\AiHelpConversation as BaseAiHelpConversation;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gespeicherte Unterhaltung mit dem Hilfe-Assistenten – gehört genau einer Person, nur sie sieht sie. Anlegen, Umbenennen
 * und Löschen stehen im Änderungsprotokoll; jede Frage zusätzlich als KI-Anfrage (AiUsage).
 */
class AiHelpConversation extends BaseAiHelpConversation
{
    use HasAuditLog;

    /** @return HasMany<AiHelpMessage, $this> */
    public function messages(): HasMany
    {
        return $this->ai_help_messages()->orderBy('id');
    }

    /** @param  Builder<self>  $query */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
