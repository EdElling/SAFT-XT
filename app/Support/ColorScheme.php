<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * Farbschema der Oberfläche (App-Einstellungen, für alle). Bestimmt nur die Hauptfarbe
 * (Schaltflächen, aktive Menüeinträge, Links) und die Markierung der ausgewählten Zeile.
 */
enum ColorScheme: string
{
    case Indigo = 'indigo';
    case HfhRed = 'hfh_red';
    case Green = 'green';
    case Gray = 'gray';

    /**
     * Rot nach Web-Styleguide der HfH: Schaltflächen #BE1925 (weiße Schrift 6.3:1), heller Ton #D8757C
     * für Text auf dunklem Grund, #E31826 als Abstufung. Die Grundfarbe #E74011 erreicht mit weißer
     * Schrift nur 4.1:1 und dient deshalb nur zur Markierung (highlight()).
     */
    private const array HFH_RED = [
        50 => '#FDF3F3',
        100 => '#FBE4E5',
        200 => '#F5C9CC',
        300 => '#EAA3A8',
        400 => '#D8757C',
        500 => '#E31826',
        600 => '#BE1925',
        700 => '#9D1520',
        800 => '#80141C',
        900 => '#67131A',
        950 => '#3B080C',
    ];

    public function label(): string
    {
        return __('settings.color_schemes.'.$this->value);
    }

    /**
     * @return array<int, string>
     */
    public function palette(): array
    {
        return match ($this) {
            self::Indigo => Color::Indigo,
            self::HfhRed => self::HFH_RED,
            self::Green => self::darker(Color::Emerald),
            self::Gray => Color::Slate,
        };
    }

    /**
     * Eine Stufe dunkler ab 500: Emerald-600 erreicht mit weißer Schrift nur 3.6:1, Emerald-700 5.5:1.
     *
     * @param  array<int, string>  $palette
     * @return array<int, string>
     */
    private static function darker(array $palette): array
    {
        // array_replace statt Spread: Zahlenschlüssel bleiben erhalten.
        return array_replace($palette, [
            500 => $palette[600],
            600 => $palette[700],
            700 => $palette[800],
            800 => $palette[900],
            900 => $palette[950],
            950 => 'oklch(0.2 0.04 172.552)',
        ]);
    }

    /** Eigene Farbe für die Markierung der ausgewählten Zeile; null = Hauptfarbe. */
    public function highlight(): ?string
    {
        return $this === self::HfhRed ? '#E74011' : null;
    }

    /** Farbmuster für die Auswahl in den App-Einstellungen. */
    public function swatch(): string
    {
        return Color::convertToHex($this->palette()[600]);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $scheme) {
            $options[$scheme->value] = $scheme->label();
        }

        return $options;
    }
}
