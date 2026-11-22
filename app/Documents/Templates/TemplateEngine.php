<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use Mustache\Engine;
use Mustache\Exception as MustacheException;

/**
 * Mustache mit Formatierungen ({{betrag | CHF}}). Platzhalter werden für HTML maskiert; für Dateinamen ohne Maskieren.
 * Mustache führt keinen Code aus – Vorlagen von Admins oder einer KI sind damit sicher.
 */
final class TemplateEngine
{
    public function html(string $template, DocumentContext $context): string
    {
        return str_replace(DocumentContext::LINE_BREAK, '<br>', $this->render($template, $context, true));
    }

    public function text(string $template, DocumentContext $context): string
    {
        return str_replace(DocumentContext::LINE_BREAK, "\n", $this->render($template, $context, false));
    }

    private function render(string $template, DocumentContext $context, bool $escape): string
    {
        $engine = new Engine([
            'pragmas' => [Engine::PRAGMA_FILTERS],
            'strict_callables' => true,
            'escape' => $escape
                ? static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : static fn (mixed $value): string => (string) $value,
        ]);

        try {
            return $engine->render($template, $context);
        } catch (MustacheException $exception) {
            throw new TemplateException($exception->getMessage(), 0, $exception);
        }
    }
}
