<?php

declare(strict_types=1);

namespace App\Reports\Assistant;

/** Zugang zum Sprachmodell. Umsetzungen: AnthropicAssistantClient; in Tests eine Attrappe ohne Netz. */
interface AssistantClient
{
    /**
     * @param  list<array{type: string, text: string, cache?: bool}>  $system  Blöcke; cache = Zwischenspeicher bis hier
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema  JSON-Schema der Antwort
     * @param  bool  $strict  Form der Antwort erzwingen (Structured Outputs); false = Schema nur als Vorgabe im Auftrag,
     *                        für grosse Schemas, die der Anbieter als Grammatik ablehnt („grammar too large“) – die
     *                        Antwort muss dann vollständig geprüft werden
     */
    public function complete(array $system, array $messages, array $schema, bool $strict = true): AssistantReply;

    /** Modell-ID für Protokoll und Anzeige. */
    public function model(): string;
}
