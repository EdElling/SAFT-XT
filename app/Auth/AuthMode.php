<?php

declare(strict_types=1);

namespace App\Auth;

enum AuthMode: string
{
    case Local = 'local';
    case Sso = 'sso';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return __("auth_admin.modes.{$this->value}.label");
    }

    public function description(): string
    {
        return __("auth_admin.modes.{$this->value}.description");
    }

    public function allowsLocal(): bool
    {
        return $this !== self::Sso;
    }

    public function allowsSso(): bool
    {
        return $this !== self::Local;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m): array => [$m->value => $m->label()])->all();
    }
}
