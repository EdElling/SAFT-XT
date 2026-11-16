<?php

declare(strict_types=1);

namespace App\Help;

use App\Documentation\Documentation;
use App\Filament\Pages\DocumentationPage;
use App\Models\User;

/**
 * Ein Handbuch der Online-Hilfe (Anwenderhandbuch, Häufige Fragen, Administratorhandbuch) als Wissensquelle – mit
 * denselben Regeln wie die Seite „Dokumentation“: wer es sieht, in welcher Sprache, welche Anker.
 */
final class ManualSource implements KnowledgeSource
{
    /** @var array{markdown: string, anchors: array<string, string>}|null je Anfrage einmal aufbereitet */
    private ?array $prepared = null;

    public function __construct(private readonly string $doc) {}

    public function id(): string
    {
        return $this->doc;
    }

    public function label(): string
    {
        return __('documentation.docs.'.$this->doc);
    }

    public function visibleFor(User $user): bool
    {
        return in_array($this->doc, Documentation::visibleFor($user), true);
    }

    public function markdown(): string
    {
        return $this->prepare()['markdown'];
    }

    public function anchors(): array
    {
        return $this->prepare()['anchors'];
    }

    public function url(string $anchor): string
    {
        return DocumentationPage::getUrl().'#'.$anchor;
    }

    /**
     * Überschriften der Ebenen 2 bis 4 mit dem Anker der Online-Hilfe versehen („user-41-die-tabellenansicht“);
     * Code-Blöcke bleiben unberührt.
     *
     * @return array{markdown: string, anchors: array<string, string>}
     */
    private function prepare(): array
    {
        if ($this->prepared !== null) {
            return $this->prepared;
        }

        $lines = explode("\n", Documentation::source($this->doc));
        $anchors = [];
        $fence = false;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '```')) {
                $fence = ! $fence;

                continue;
            }

            if (! $fence && preg_match('/^(#{2,4}) (.+)$/', $line, $m) === 1) {
                $title = trim($m[2]);
                // Wie die Online-Hilfe: Titel als HTML, ohne Tags, Entitäten aufgelöst.
                $anchor = $this->doc.'-'.Documentation::slug(html_entity_decode(strip_tags(Documentation::markdown('## '.$title)), ENT_QUOTES | ENT_HTML5));
                $anchors[$anchor] = $title;
                $lines[$i] = $m[1].' '.$title.' {#'.$anchor.'}';
            }
        }

        return $this->prepared = ['markdown' => implode("\n", $lines), 'anchors' => $anchors];
    }
}
