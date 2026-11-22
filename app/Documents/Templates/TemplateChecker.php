<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\Documents\LetterheadResolver;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomTable;
use Mustache\Exception as MustacheException;
use Mustache\Tokenizer;

/**
 * Prüft eine Vorlage, bevor sie gespeichert wird. Fehler (Speichern nicht möglich): Syntax, nicht geschlossene oder
 * falsch verschachtelte Abschnitte, unbekannte Platzhalter und Formatierungen, Verknüpfungen über mehr als
 * TemplateFields::MAX_DEPTH Schritte. Hinweise: HTML/CSS, das tc-lib-pdf nicht darstellt, und Bilder von aussen.
 *
 * Gültig ist, was Mustache in der Tiefe der Abschnitte findet: Felder der Tabelle des innersten Abschnitts, dann der
 * äusseren bis zur Grundtabelle, dazu absender und heute.
 */
final class TemplateChecker
{
    /** Hinweise auf HTML und CSS, das im PDF nicht wie im Browser aussieht. */
    private const array UNSUPPORTED = [
        '/display\s*:\s*(flex|grid|inline-flex|inline-grid)/i' => 'layout',
        '/(?<![\w-])float\s*:/i' => 'float',
        '/position\s*:\s*(absolute|fixed|relative|sticky)/i' => 'position',
        '/@media\b/i' => 'media',
        '/<img\b[^>]*\bsrc\s*=\s*["\']?(?!data:)/i' => 'image',
        '/<(script|iframe|object|embed|link)\b/i' => 'script',
    ];

    /**
     * @param  array{email_to?: ?string, email_subject?: ?string, email_body?: ?string, email_reply_to?: ?string}  $mail  E-Mail-Teile (D3b)
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(CustomTable $table, string $body, ?string $recipient = null, ?string $fileName = null, array $mail = []): array
    {
        $errors = [];
        $parts = ['body' => $body, 'recipient' => (string) $recipient, 'file_name' => (string) $fileName];

        foreach (['email_to', 'email_subject', 'email_body', 'email_reply_to'] as $part) {
            $parts[$part] = (string) ($mail[$part] ?? '');
        }

        foreach ($parts as $part => $template) {
            foreach ($this->placeholders($table, $template) as $error) {
                $errors[] = __('documents.templates.check.in_'.$part, ['error' => $error]);
            }
        }

        $warnings = [];

        foreach (self::UNSUPPORTED as $pattern => $key) {
            if (preg_match($pattern, $body) === 1) {
                $warnings[] = __('documents.templates.check.'.$key);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** @return list<string> */
    private function placeholders(CustomTable $table, string $template): array
    {
        try {
            $tokens = (new Tokenizer)->scan($template);
        } catch (MustacheException $exception) {
            return [__('documents.templates.check.syntax', ['message' => $exception->getMessage()])];
        }

        $errors = [];
        // Stapel der Abschnitte: Name und Tabelle (null: Abschnitt über einen Wert oder den Absender).
        $stack = [['name' => null, 'table' => $table, 'sender' => false]];

        foreach ($tokens as $token) {
            $type = $token[Tokenizer::TYPE];
            $line = (int) ($token[Tokenizer::LINE] ?? 0) + 1;

            if (in_array($type, [Tokenizer::T_PARTIAL, Tokenizer::T_PARENT, Tokenizer::T_BLOCK_VAR, Tokenizer::T_BLOCK_ARG, Tokenizer::T_PRAGMA], true)) {
                $errors[] = __('documents.templates.check.unsupported_tag', ['tag' => $type, 'line' => $line]);

                continue;
            }

            if (! in_array($type, [Tokenizer::T_ESCAPED, Tokenizer::T_UNESCAPED, Tokenizer::T_UNESCAPED_2, Tokenizer::T_SECTION, Tokenizer::T_INVERTED, Tokenizer::T_END_SECTION], true)) {
                continue;
            }

            $parts = array_map('trim', explode('|', (string) $token[Tokenizer::NAME]));
            $name = array_shift($parts);

            if ($type === Tokenizer::T_END_SECTION) {
                $open = array_pop($stack);

                if (($open['name'] ?? null) !== $name || $stack === []) {
                    return [...$errors, __('documents.templates.check.unexpected_end', ['name' => $name, 'line' => $line])];
                }

                continue;
            }

            foreach ($parts as $filter) {
                if (! TemplateFields::isFilter($filter)) {
                    $errors[] = __('documents.templates.check.unknown_filter', ['name' => $filter, 'line' => $line]);
                }
            }

            $resolved = $this->resolve($name, $stack);

            if ($resolved === false) {
                $errors[] = __('documents.templates.check.unknown', ['name' => $name, 'line' => $line]);
            } elseif (is_string($resolved)) {
                $errors[] = $resolved;
            }

            if ($type === Tokenizer::T_SECTION || $type === Tokenizer::T_INVERTED) {
                $stack[] = ['name' => $name, 'table' => $type === Tokenizer::T_SECTION && $resolved instanceof CustomTable ? $resolved : null, 'sender' => $resolved === TemplateFields::SENDER.'!'];
            }
        }

        if (count($stack) > 1) {
            $errors[] = __('documents.templates.check.unclosed', ['name' => (string) end($stack)['name']]);
        }

        return $errors;
    }

    /**
     * Was ein Platzhalter bezeichnet: eine Tabelle (Verknüpfung oder Kind-Tabelle), true (Wert), „absender!“ (Absender),
     * false (unbekannt) oder eine Fehlermeldung.
     *
     * @param  list<array{name: ?string, table: ?CustomTable, sender: bool}>  $stack
     */
    private function resolve(string $name, array $stack): CustomTable|bool|string
    {
        if ($name === '.' || $name === '') {
            return true;
        }

        $segments = explode('.', $name);
        $first = array_shift($segments);
        $current = null;

        foreach (array_reverse($stack) as $frame) {
            if ($frame['sender']) {
                if (in_array($first, array_keys(self::senderKeys()), true)) {
                    $current = true;
                    break;
                }

                continue;
            }

            if ($frame['table'] !== null && ($found = $this->member($frame['table'], $first)) !== null) {
                $current = $found;
                break;
            }
        }

        if ($current === null) {
            if ($first === TemplateFields::SENDER) {
                return $segments === [] ? TemplateFields::SENDER.'!' : (count($segments) === 1 && array_key_exists($segments[0], self::senderKeys()));
            }

            return $first === TemplateFields::TODAY && $segments === [];
        }

        $steps = 0;

        foreach ($segments as $segment) {
            if (! $current instanceof CustomTable || ($next = $this->member($current, $segment)) === null) {
                return false;
            }

            if (++$steps > TemplateFields::MAX_DEPTH) {
                return __('documents.templates.check.too_deep', ['name' => $name, 'max' => TemplateFields::MAX_DEPTH]);
            }

            $current = $next;
        }

        return $current;
    }

    /** Feld (true), Verknüpfung (Zieltabelle) oder Kind-Tabelle einer Tabelle; null, wenn es den Namen nicht gibt. */
    private function member(CustomTable $table, string $name): CustomTable|bool|null
    {
        $field = TemplateFields::field($table, $name);

        if ($field !== null) {
            return $field->valueType() === FieldType::Relation ? (TemplateFields::target($field) ?? true) : true;
        }

        $child = TemplateFields::children($table)[$name] ?? null;

        return $child?->customTable;
    }

    /** @return array<string, true> */
    private static function senderKeys(): array
    {
        return array_fill_keys(LetterheadResolver::SENDER_KEYS, true);
    }
}
