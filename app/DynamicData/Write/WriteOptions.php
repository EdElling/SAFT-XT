<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

/**
 * Optionen eines Schreibvorgangs (RecordWriter). Unveränderlich; abgeleitete Fassungen über with*().
 *
 * - origin: wer schreibt (kommt als „via“ in die Eigenschaften des Protokolleintrags)
 * - pluginEvents: RecordSaving/RecordSaved/RecordDeleting/RecordDeleted an Plugins senden
 * - log: Einträge im Änderungsprotokoll je Datensatz (der Import schreibt stattdessen einen Sammeleintrag)
 * - expectedVersion: Stand (RecordVersion), den das Formular gelesen hat; weicht der aktuelle ab → StaleRecordException
 * - overwriting: bewusstes Überschreiben nach einer Meldung („Trotzdem speichern“), kommt ins Protokoll
 * - run: Kennung zusammengehöriger Änderungen (z. B. „work:42“ für einen Lauf), kommt als „run“ ins Protokoll
 */
final readonly class WriteOptions
{
    public const string UI = 'ui';

    public const string PLUGIN = 'plugin';

    public const string IMPORT = 'import';

    public const string COPY = 'copy';

    public const string DOCUMENT = 'document';

    public const string RECOMPUTE = 'recompute';

    public const string SEED = 'seed';

    /** Nachnummerieren (B-135, N2): darf eine leere Nummer eines bestehenden Datensatzes füllen. */
    public const string NUMBERING = 'numbering';

    /** Sammeländerung (B-133): gleiche Prüfungen wie beim Bearbeiten, Kennung „bulk:<uuid>“ als run. */
    public const string BULK = 'bulk';

    /** Schritt einer Arbeit (WritesRecords), sofern die Art keine eigenen Optionen mitgibt. */
    public const string WORK = 'work';

    /** Aufruf ohne ausdrückliche Optionen, z. B. $record->save() in eigenem Code. */
    public const string DIRECT = 'direct';

    public function __construct(
        public string $origin = self::DIRECT,
        public bool $pluginEvents = true,
        public bool $log = true,
        public ?string $expectedVersion = null,
        public bool $overwriting = false,
        public ?string $run = null,
    ) {}

    public static function defaults(): self
    {
        return new self;
    }

    public static function ui(): self
    {
        return new self(origin: self::UI);
    }

    public static function plugin(): self
    {
        return new self(origin: self::PLUGIN);
    }

    /** Import in eine bestehende Tabelle: Plugins prüfen mit, das Protokoll bekommt einen Sammeleintrag. */
    public static function import(): self
    {
        return new self(origin: self::IMPORT, log: false);
    }

    /** Sammeländerung (B-133) mit der Kennung des Laufs. */
    public static function bulk(string $run): self
    {
        return new self(origin: self::BULK, run: $run);
    }

    /** Feste Datensätze (Demo-Daten): keine Geschäftsregeln von Plugins, kein Protokoll. */
    public static function seed(): self
    {
        return new self(origin: self::SEED, pluginEvents: false, log: false);
    }

    public function withOrigin(string $origin): self
    {
        return new self($origin, $this->pluginEvents, $this->log, $this->expectedVersion, $this->overwriting, $this->run);
    }

    public function withoutLog(): self
    {
        return new self($this->origin, $this->pluginEvents, false, $this->expectedVersion, $this->overwriting, $this->run);
    }

    public function withoutPluginEvents(): self
    {
        return new self($this->origin, false, $this->log, $this->expectedVersion, $this->overwriting, $this->run);
    }

    /** @param  bool  $overwriting  die Version ist die nach einer Meldung „inzwischen geändert“ neu gelesene */
    public function expecting(?string $version, bool $overwriting = false): self
    {
        return new self($this->origin, $this->pluginEvents, $this->log, $version, $overwriting, $this->run);
    }

    /** Kennung zusammengehöriger Änderungen im Protokoll, z. B. „work:42“ (Sammeländerung „bulk:<uuid>“). */
    public function withRun(string $run): self
    {
        return new self($this->origin, $this->pluginEvents, $this->log, $this->expectedVersion, $this->overwriting, $run);
    }
}
