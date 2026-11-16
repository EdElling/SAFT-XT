<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * Persönliche Anordnung des Dashboards: zwei Spalten (links, rechts) für halbbreite Karten und ein
 * Bereich in voller Breite darunter, dazu ausgeblendete Karten.
 *
 * Breite je Karte: SPAN_HALF nur in den Spalten, SPAN_FULL nur im breiten Bereich, SPAN_FLEX (Auswertungen)
 * überall – wer sie in den breiten Bereich zieht, macht sie breit. Gespeichertes wird gegen die gerade
 * erlaubten Karten geprüft: Unbekanntes und Unpassendes fällt weg, Neues kommt an seinen Standardplatz.
 */
final class DashboardLayout
{
    public const string LEFT = 'left';

    public const string RIGHT = 'right';

    public const string FULL = 'full';

    public const array COLUMNS = [self::LEFT, self::RIGHT, self::FULL];

    public const string SPAN_HALF = 'half';

    public const string SPAN_FULL = 'full';

    public const string SPAN_FLEX = 'flex';

    /**
     * @param  array<string, array{span: string, default: string}>  $cards  Schlüssel => Breite und Standardbereich (half/full), in Standardreihenfolge
     * @param  array<string, mixed>|null  $saved  gespeicherte Anordnung (users.dashboard_layout)
     * @return array{left: list<string>, right: list<string>, full: list<string>, hidden: list<string>}
     */
    public static function resolve(array $cards, ?array $saved): array
    {
        $layout = [self::LEFT => [], self::RIGHT => [], self::FULL => [], 'hidden' => []];
        $placed = [];

        foreach (self::COLUMNS as $column) {
            foreach ((array) ($saved[$column] ?? []) as $key) {
                if (is_string($key) && isset($cards[$key]) && ! isset($placed[$key]) && self::fits($cards[$key]['span'], $column)) {
                    $layout[$column][] = $key;
                    $placed[$key] = true;
                }
            }
        }

        foreach ((array) ($saved['hidden'] ?? []) as $key) {
            if (is_string($key) && isset($cards[$key]) && ! isset($placed[$key])) {
                $layout['hidden'][] = $key;
                $placed[$key] = true;
            }
        }

        // Neue (oder bisher nicht gespeicherte) Karten an ihren Standardplatz; halbbreite in die kürzere Spalte.
        foreach ($cards as $key => $card) {
            if (isset($placed[$key])) {
                continue;
            }

            $column = $card['default'] === self::SPAN_FULL
                ? self::FULL
                : (count($layout[self::LEFT]) <= count($layout[self::RIGHT]) ? self::LEFT : self::RIGHT);
            $layout[$column][] = $key;
        }

        return $layout;
    }

    /** Darf eine Karte dieser Breite in diesen Bereich? */
    public static function fits(string $span, string $column): bool
    {
        return match ($span) {
            self::SPAN_HALF => in_array($column, [self::LEFT, self::RIGHT], true),
            self::SPAN_FULL => $column === self::FULL,
            default => in_array($column, self::COLUMNS, true),
        };
    }

    /**
     * Neue Anordnung aus dem Editor übernehmen (nur erlaubte Karten, passende Bereiche); Rest wie bisher.
     *
     * @param  array<string, array{span: string, default: string}>  $cards
     * @param  array<string, mixed>  $columns  Bereich => Schlüssel in neuer Reihenfolge
     * @param  list<string>  $hidden
     * @return array{left: list<string>, right: list<string>, full: list<string>, hidden: list<string>}
     */
    public static function store(array $cards, array $columns, array $hidden): array
    {
        return self::resolve($cards, [
            self::LEFT => (array) ($columns[self::LEFT] ?? []),
            self::RIGHT => (array) ($columns[self::RIGHT] ?? []),
            self::FULL => (array) ($columns[self::FULL] ?? []),
            'hidden' => $hidden,
        ]);
    }
}
