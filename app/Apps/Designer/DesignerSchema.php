<?php

declare(strict_types=1);

namespace App\Apps\Designer;

use App\DynamicData\Assistant\TableProposalSchema;

/**
 * Antwortformat des KI-App-Designers (B-107, Etappe 7a): App, Tabellen mit Feldern (wie der Tabellen-Assistent, auch
 * berechnete Felder) und Tabellenregeln, Menügruppen. Bewusst knapp – das Schema der Antwort ist bei Anthropic in der
 * Grösse begrenzt; DraftCompiler macht daraus eine vollständige App-Definition saft-app/1.
 */
final class DesignerSchema
{
    /** @return array<string, mixed> */
    public static function response(): array
    {
        return TableProposalSchema::object([
            'kind' => ['type' => 'string', 'enum' => ['app', 'question']],
            // Leer = keine Rückfrage.
            'question' => ['type' => 'string'],
            'app' => TableProposalSchema::object([
                'name' => TableProposalSchema::translations(),
                'description' => TableProposalSchema::translations(),
                'prefix' => ['type' => 'string'],
                'icon' => ['type' => 'string'],
            ]),
            'tables' => ['type' => 'array', 'items' => TableProposalSchema::object([
                'name' => ['type' => 'string'],
                'label' => TableProposalSchema::translations(),
                'description' => TableProposalSchema::translations(),
                'icon' => ['type' => 'string'],
                'fields' => ['type' => 'array', 'items' => TableProposalSchema::field()],
                // Tabellenregeln als Text, z. B. „ende >= beginn“.
                'rules' => ['type' => 'array', 'items' => ['type' => 'string']],
            ])],
            'menus' => ['type' => 'array', 'items' => TableProposalSchema::object([
                'label' => TableProposalSchema::translations(),
                'icon' => ['type' => 'string'],
                'tables' => ['type' => 'array', 'items' => ['type' => 'string']],
            ])],
        ]);
    }
}
