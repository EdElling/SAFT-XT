<?php

declare(strict_types=1);

namespace App\Database;

use App\Exceptions\BusinessRuleException;

/**
 * Sicherung fehlgeschlagen; die Meldung nennt den Grund (Programm fehlt, zu alt, keine Verbindung …) und enthält keine
 * Zugangsdaten – deshalb darf die geplante Aufgabe sie anzeigen (BusinessRuleException).
 */
final class BackupFailed extends BusinessRuleException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'backup_failed');
    }
}
