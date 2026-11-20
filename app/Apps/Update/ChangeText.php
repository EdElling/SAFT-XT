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
            static fn (array $c): string => $c['attr'] === 'options' && is_array($c['from']) && is_array($c['to'])
                ? self::options($c['from'], $c['to'])
                : __('apps.update.attr.'.$c['attr']).' '.self::value($c['from']).' → '.self::value($c['to']),
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
