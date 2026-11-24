<?php

declare(strict_types=1);

namespace App\Work;

use App\Plugins\PluginOrigin;
use App\Plugins\PluginRegistry;
use InvalidArgumentException;

/** Bekannte Arten von Arbeiten (config/work.php und Plugins). */
final class WorkRegistry
{
    /** @var array<string, WorkType>|null */
    private ?array $types = null;

    /** @return array<string, WorkType> */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];

            foreach ([...(array) config('work.types', []), ...app(PluginRegistry::class)->workTypes()] as $class) {
                $type = app($class);

                if (! $type instanceof WorkType) {
                    throw new InvalidArgumentException("{$class} ist keine Art von Arbeit.");
                }

                $this->types[$type->key()] = $type;
            }
        }

        return $this->types;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): WorkType
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unbekannte Art von Arbeit: {$key}");
    }

    /** Bezeichnung mit Herkunft, z. B. „Inventur zählen (Plugin Lager-Tafel)“. */
    public function labelOf(WorkType $type): string
    {
        return app(PluginOrigin::class)->suffixed($type->label(), $type);
    }
}
