<?php

declare(strict_types=1);

namespace App\Logs;

/** Ein Eintrag der Logdatei: Kopfzeile „[Zeit] umgebung.STUFE: Meldung“ und Folgezeilen (Ablauf). */
final readonly class LogEntry
{
    public function __construct(
        public string $id,
        public string $time,
        public string $environment,
        public string $level,
        public string $message,
        public string $text,
        public ?string $reference,
    ) {}

    /** @return array<string, string|null> für die Tabelle */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'time' => $this->time,
            'environment' => $this->environment,
            'level' => $this->level,
            'message' => $this->message,
            'reference' => $this->reference,
        ];
    }
}
