<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Unterhaltung mit einem Sprachmodell in freiem Text (Hilfe-Assistent). Heute über Claude (AnthropicChatClient:
 * Anthropic, Bedrock, Vertex); vorgesehen ist zusätzlich ein lokal betriebenes Modell über eine OpenAI-kompatible
 * Schnittstelle. Darum nur Text hinein und heraus: „cache“ ist ein Hinweis, den eine Umsetzung ohne Caching übergeht.
 */
interface ChatClient
{
    public function model(): string;

    /**
     * @param  list<array{text: string, cache?: bool}>  $system  Anweisungen und Wissen, unveränderliche Teile zuerst
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  Verlauf, zuletzt die neue Frage
     */
    public function chat(array $system, array $messages): ChatReply;
}
