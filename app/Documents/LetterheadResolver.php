<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\DocumentLetterhead;
use Illuminate\Support\Facades\Storage;

/**
 * Wirksames Briefpapier für eine App: je Wert die Abweichung der App, sonst die Installation, sonst die Vorgabe.
 * Absenderdaten und Ränder erben je einzelnem Wert.
 */
final class LetterheadResolver
{
    public const array SENDER_KEYS = ['firma', 'adresse', 'mwst', 'iban', 'kontakt'];

    public const array SIDES = ['top', 'right', 'bottom', 'left'];

    public function for(?int $appId): Letterhead
    {
        $rows = DocumentLetterhead::query()->whereNull('app_id')->when($appId !== null, fn ($q) => $q->orWhere('app_id', $appId))->get();
        $app = $appId === null ? null : $rows->firstWhere('app_id', $appId);

        return $this->combine($app, $rows->firstWhere('app_id', null));
    }

    public function combine(?DocumentLetterhead $app, ?DocumentLetterhead $installation): Letterhead
    {
        $value = static fn (string $attribute): mixed => filled($app?->getAttribute($attribute)) ? $app->getAttribute($attribute) : $installation?->getAttribute($attribute);
        $file = fn (string $attribute): ?string => $this->path($value($attribute));

        $margins = Letterhead::DEFAULT_MARGINS;

        foreach (['first', 'next'] as $page) {
            foreach (self::SIDES as $side) {
                $own = $app?->margins[$page][$side] ?? null;
                $base = $installation?->margins[$page][$side] ?? null;
                $margins[$page][$side] = (float) (is_numeric($own) ? $own : (is_numeric($base) ? $base : $margins[$page][$side]));
            }
        }

        $sender = [];

        foreach (self::SENDER_KEYS as $key) {
            $own = $app?->sender[$key] ?? null;
            $sender[$key] = filled($own) ? (string) $own : (filled($installation?->sender[$key] ?? null) ? (string) $installation->sender[$key] : null);
        }

        $pdfa = $app?->pdfa ?? $installation?->pdfa ?? true;
        $regular = $file('font_regular_path');

        return new Letterhead(
            firstPage: $file('first_page_path'),
            nextPages: $file('next_pages_path'),
            logo: $file('logo_path'),
            logoHeight: is_numeric($value('logo_height')) ? (float) $value('logo_height') : Letterhead::DEFAULT_LOGO_HEIGHT,
            // Schnitte nur zusammen mit der normalen Schrift derselben Ebene, sonst mischen sich zwei Schriften.
            fonts: ['regular' => $regular, 'bold' => $regular === null ? null : $file('font_bold_path'), 'italic' => $regular === null ? null : $file('font_italic_path')],
            primary: self::color($value('color_primary'), Letterhead::DEFAULT_PRIMARY),
            secondary: self::color($value('color_secondary'), Letterhead::DEFAULT_SECONDARY),
            text: self::color($value('color_text'), Letterhead::DEFAULT_TEXT),
            margins: $margins,
            addressPosition: $value('address_position') === DocumentLetterhead::RIGHT ? DocumentLetterhead::RIGHT : DocumentLetterhead::LEFT,
            senderLine: filled($value('sender_line')) ? (string) $value('sender_line') : null,
            sender: $sender,
            pdfa: (bool) $pdfa,
        );
    }

    private function path(mixed $relative): ?string
    {
        if (blank($relative)) {
            return null;
        }

        $disk = Storage::disk('local');

        return $disk->exists((string) $relative) ? $disk->path((string) $relative) : null;
    }

    private static function color(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : $default;
    }
}
