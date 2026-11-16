<?php

declare(strict_types=1);

namespace App\Help;

use App\Models\User;

/**
 * Wissen, aus dem der Hilfe-Assistent antwortet: heute die Handbücher der Online-Hilfe (ManualSource), vorgesehen
 * sind Handbücher aus Plugins und in der Administration gepflegte Seiten. Jede Quelle entscheidet selbst, wer sie
 * sehen darf, und liefert Markdown, dessen Überschriften eindeutige Anker tragen – die Antworten verweisen darauf.
 */
interface KnowledgeSource
{
    /** Eindeutige Kennung, z. B. „user“ oder später „plugin:lager-tafel“. */
    public function id(): string;

    public function label(): string;

    public function visibleFor(User $user): bool;

    /**
     * Inhalt als Markdown in der Sprache der Oberfläche (oder Englisch); jede Überschrift endet mit „{#anker}“.
     */
    public function markdown(): string;

    /**
     * Abschnitte (Anker => Titel) – zum Prüfen der Verweise in Antworten und für späteres Nachschlagen.
     *
     * @return array<string, string>
     */
    public function anchors(): array;

    /** Adresse eines Abschnitts in der Online-Hilfe. */
    public function url(string $anchor): string;
}
