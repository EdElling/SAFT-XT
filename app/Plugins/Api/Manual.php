<?php

declare(strict_types=1);

namespace App\Plugins\Api;

/**
 * Handbuch eines Plugins (SaftPlugin::manuals()): erscheint als eigener Reiter in der Online-Hilfe und als Wissen für
 * den Hilfe-Assistenten – für Personen mit einem der genannten Rechte, solange das Plugin eingeschaltet ist und nicht
 * ruht. Je Sprache eine Markdown-Datei im Plugin („<path>/de.md“, „<path>/en.md“ …); fehlt die Sprache der
 * Oberfläche, gilt Englisch, sonst die erste vorhandene. Überschriften ab Ebene 2 („##“) bilden das
 * Inhaltsverzeichnis; eine Überschrift der Ebene 1 am Anfang entfällt (der Reiter trägt den Titel).
 *
 * @api
 */
final readonly class Manual
{
    /** Erlaubte Kennungen: Kleinbuchstaben, Ziffern und Bindestriche. */
    public const string ID_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /** Sprachen, in denen Handbücher erscheinen können. */
    public const array LANGUAGES = ['de', 'en', 'fr', 'it'];

    /**
     * @param  string  $id  Kennung innerhalb des Plugins, z. B. „benutzer“; Teil der Anker
     * @param  array<string, string>  $title  Titel des Reiters je Sprache (de, en, fr, it)
     * @param  string  $path  Ordner im Plugin mit den Dateien je Sprache, z. B. „docs/manual“
     * @param  list<string>  $permissions  sichtbar mit einem dieser Rechte (vollständige Namen); leer = für alle
     */
    public function __construct(
        public string $id,
        public array $title,
        public string $path,
        public array $permissions = [],
    ) {}
}
