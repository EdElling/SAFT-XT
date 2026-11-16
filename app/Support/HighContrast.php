<?php

declare(strict_types=1);

namespace App\Support;

/** Persönliche Einstellung „Hoher Kontrast“ (users.high_contrast: null = automatisch). */
enum HighContrast: string
{
    case Auto = 'auto';
    case On = 'on';
    case Off = 'off';

    public static function fromStored(?bool $value): self
    {
        return match ($value) {
            null => self::Auto,
            true => self::On,
            false => self::Off,
        };
    }

    public function toStored(): ?bool
    {
        return match ($this) {
            self::Auto => null,
            self::On => true,
            self::Off => false,
        };
    }

    public function label(): string
    {
        return __('my_settings.high_contrast_options.'.$this->value);
    }

    /** CSS-Klasse am html-Element; automatisch greift nur bei „Mehr Kontrast“ im Betriebssystem. */
    public function cssClass(): ?string
    {
        return match ($this) {
            self::Auto => 'saft-hc-auto',
            self::On => 'saft-hc',
            self::Off => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
