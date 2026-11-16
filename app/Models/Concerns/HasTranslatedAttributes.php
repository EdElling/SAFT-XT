<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Spatie\Translatable\HasTranslations;

/**
 * Übersetzbare Attribute (spatie/laravel-translatable) mit klarer Bedeutung beim Schreiben:
 * - Text: setzt die aktuelle Sprache, andere bleiben erhalten.
 * - Array je Sprache: ersetzt alle Sprachen; leere entfallen (dann greift der Ersatz).
 * Das Paket selbst würde ein Array nur ergänzen, geleerte Sprachen blieben stehen.
 */
trait HasTranslatedAttributes
{
    use HasTranslations {
        setAttribute as private setTranslatableAttribute;
    }

    public function setAttribute($key, $value)
    {
        if ($this->isTranslatableAttribute($key) && is_array($value) && ! array_is_list($value)) {
            $filled = array_filter(
                array_map(static fn (mixed $text): mixed => is_string($text) ? trim($text) : $text, $value),
                filled(...),
            );

            $this->attributes[$key] = $filled === [] ? null : $this->asJson($filled);

            return $this;
        }

        return $this->setTranslatableAttribute($key, $value);
    }
}
