<?php

declare(strict_types=1);

namespace App\Rules;

use BladeUI\Icons\Exceptions\SvgNotFound;
use BladeUI\Icons\Factory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Icon-Name muss in einem installierten Blade-Icon-Set existieren. */
final class KnownIcon implements ValidationRule
{
    public static function exists(string $name): bool
    {
        try {
            app(Factory::class)->svg($name);

            return true;
        } catch (SvgNotFound) {
            return false;
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::exists($value)) {
            $fail(__('errors.rules.unknown_icon'));
        }
    }
}
