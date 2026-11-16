<?php

declare(strict_types=1);

namespace App\Help;

use App\Documentation\Documentation;
use App\Models\User;

/**
 * Alle Wissensquellen des Hilfe-Assistenten. Heute die Handbücher der Online-Hilfe; Erweiterungspunkt für Handbücher
 * aus Plugins und gepflegte Seiten (Backlog) – sie kommen hier hinzu, Assistent und Oberfläche bleiben unverändert.
 */
final class KnowledgeSources
{
    /** @return list<KnowledgeSource> */
    public function all(): array
    {
        // Handbücher von SAFT und der Plugins (Documentation::keys()); weitere Arten von Quellen kommen hier hinzu.
        return array_map(static fn (string $doc): KnowledgeSource => new ManualSource($doc), Documentation::keys());
    }

    /** @return list<KnowledgeSource> was diese Person sehen darf, in fester Reihenfolge */
    public function for(User $user): array
    {
        return array_values(array_filter($this->all(), static fn (KnowledgeSource $source): bool => $source->visibleFor($user)));
    }
}
