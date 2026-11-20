<?php

declare(strict_types=1);

namespace App\Apps\Update;

use App\Apps\Definition\References;
use Illuminate\Support\Str;

/** Schritte des Änderungsplans in Worten (Vorschau, Protokoll). */
final class ChangeText
{
    /** z. B. „Feld · Artikel › preis: Typ string → decimal; Pflicht Nein → Ja“ */
    public static function summary(ChangeStep $step): string
    {
        $head = __('apps.update.kind.'.$step->kind).' · '.$step->label;

        if ($step->action !== ChangeStep::UPDATE || $step->changes === []) {
            return $head.': '.__('apps.update.text.'.$step->action);
        }

        return $head.': '.implode('; ', array_map(
            static fn (array $c): string => match (true) {
                $c['attr'] === 'options' && is_array($c['from']) && is_array($c['to']) => self::options($c['from'], $c['to']),
                // Inhalte ohne sinnvolle Kurzform: nur „geändert“.
                in_array($c['attr'], ['definition', 'content', 'fields', 'parameters'], true) => __('apps.update.attr.'.$c['attr']).' '.__('apps.update.text.update'),
                $c['attr'] === 'target' => __('apps.update.attr.target').' '.self::target($c['from']).' → '.self::target($c['to']),
                // Listen von Namen (Rechte, ausgeblendete Menüs): + neu, − entfällt.
                in_array($c['attr'], ['permissions', 'hidden_menus'], true) => __('apps.update.attr.'.$c['attr']).': '.self::listDiff((array) $c['from'], (array) $c['to']),
                // Name einer Auswertung, Rolle oder Aufgabe ist kein technischer Name.
                $c['attr'] === 'name' && ! in_array($step->kind, ['table', 'field'], true) => __('apps.update.attr.title').' '.self::value($c['from']).' → '.self::value($c['to']),
                default => __('apps.update.attr.'.$c['attr']).' '.self::value($c['from']).' → '.self::value($c['to']),
            },
            $step->changes,
        ));
    }

    /**
     * Optionen je Schlüssel; Auswahlwerte als „+ neu, − entfällt“.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private static function options(array $from, array $to): string
    {
        $parts = [];

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $key) {
            if (ChangePlanner::same($from[$key] ?? null, $to[$key] ?? null)) {
                continue;
            }

            if ($key === 'options' && array_is_list((array) ($from[$key] ?? [])) && array_is_list((array) ($to[$key] ?? []))) {
                $values = static fn (mixed $list): array => array_map(static fn (mixed $o): string => (string) (is_array($o) ? ($o['value'] ?? '') : $o), (array) $list);
                [$old, $new] = [$values($from[$key] ?? []), $values($to[$key] ?? [])];
                $diff = [...array_map(static fn (string $v): string => '+ '.$v, array_diff($new, $old)), ...array_map(static fn (string $v): string => '− '.$v, array_diff($old, $new))];
                $parts[] = __('apps.update.choices').' '.($diff !== [] ? implode(', ', $diff) : __('apps.update.labels_changed'));

                continue;
            }

            $parts[] = $key.' '.self::value($from[$key] ?? null).' → '.self::value($to[$key] ?? null);
        }

        return __('apps.update.attr.options').': '.implode(', ', $parts);
    }

    /** Ziel eines Menüeintrags in Kurzform: Tabelle, Auswertung, Seite, Link oder Gruppe. */
    private static function target(mixed $target): string
    {
        $target = (array) $target;

        return match ($target['type'] ?? 'group') {
            'data' => __('apps.update.target.data', ['name' => (string) ($target['table'] ?? '')]),
            'report' => __('apps.update.target.report', ['name' => (string) ($target['report'] ?? '')]),
            'route' => __('apps.update.target.route', ['name' => (string) ($target['route'] ?? '')]),
            'url' => (string) ($target['url'] ?? ''),
            default => __('apps.update.target.group'),
        };
    }

    /**
     * @param  list<mixed>  $from
     * @param  list<mixed>  $to
     */
    private static function listDiff(array $from, array $to): string
    {
        $from = array_map('strval', $from);
        $to = array_map('strval', $to);
        $diff = [...array_map(static fn (string $v): string => '+ '.$v, array_diff($to, $from)), ...array_map(static fn (string $v): string => '− '.$v, array_diff($from, $to))];

        return Str::limit(implode(', ', $diff), 160);
    }

    private static function value(mixed $value): string
    {
        $text = match (true) {
            $value === null || $value === '' || $value === [] => '–',
            is_bool($value) => $value ? __('common.yes') : __('common.no'),
            is_array($value) && ! array_is_list($value) && collect($value)->every(static fn (mixed $v): bool => is_string($v) || $v === null) => References::defaultLabel($value) ?: '–',
            is_array($value) && array_is_list($value) && collect($value)->every(static fn (mixed $v): bool => is_scalar($v)) => implode(', ', $value),
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };

        return Str::limit($text, 80);
    }
}
