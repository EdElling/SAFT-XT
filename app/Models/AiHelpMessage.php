<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\AiHelpMessage as BaseAiHelpMessage;

/**
 * Frage oder Antwort einer Unterhaltung mit dem Hilfe-Assistenten. Kein eigenes Änderungsprotokoll: jede Frage steht
 * bereits als KI-Anfrage mit Modell und Tokens im Protokoll (AiUsage), Nachrichten werden nicht geändert.
 */
class AiHelpMessage extends BaseAiHelpMessage
{
    public const string USER = 'user';

    public const string ASSISTANT = 'assistant';
}
