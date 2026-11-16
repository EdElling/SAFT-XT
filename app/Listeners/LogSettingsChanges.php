<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Concerns\AuditMask;
use BackedEnum;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\LaravelSettings\Events\SavingSettings;

/**
 * Audit für alle Settings-Klassen (spatie/laravel-settings): protokolliert geänderte
 * Werte, verschlüsselte Properties nur maskiert.
 */
class LogSettingsChanges
{
    public function handle(SavingSettings $event): void
    {
        $group = $event->settings::group();
        $encrypted = method_exists($event->settings, 'encrypted') ? $event->settings::encrypted() : [];

        $old = [];
        $new = [];

        foreach ($event->properties as $name => $value) {
            $before = $event->originalValues?->get($name);

            if ($this->normalize($before) === $this->normalize($value)) {
                continue;
            }

            $masked = in_array($name, $encrypted, true);
            $old[$name] = $masked ? AuditMask::VALUE : $this->normalize($before);
            $new[$name] = $masked ? AuditMask::VALUE : $this->normalize($value);
        }

        if ($new === []) {
            return;
        }

        activity("settings.{$group}")
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => $new, 'old' => $old])
            ->withProperties(['settings_group' => $group])
            ->log('updated');
    }

    private function normalize(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
