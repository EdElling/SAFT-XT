<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Plugins\PluginRegistry;
use InvalidArgumentException;

/** Bekannte Arten von Aufgaben (config/tasks.php und Plugins). */
final class TaskRegistry
{
    /** @var array<string, TaskType>|null */
    private ?array $types = null;

    /** @return array<string, TaskType> */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];

            // Eigene Arten und die der Plugins.
            foreach ([...(array) config('tasks.types', []), ...app(PluginRegistry::class)->taskTypes()] as $class) {
                $type = app($class);

                if (! $type instanceof TaskType) {
                    throw new InvalidArgumentException("{$class} ist keine Art von Aufgabe.");
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

    public function get(string $key): TaskType
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unbekannte Art von Aufgabe: {$key}");
    }

    /** @return array<string, string> Arten, die sich in der Verwaltung anlegen lassen. */
    public function creatableOptions(): array
    {
        return collect($this->all())->filter(fn (TaskType $type): bool => $type->creatable())
            ->map(fn (TaskType $type): string => $type->label())->all();
    }
}
