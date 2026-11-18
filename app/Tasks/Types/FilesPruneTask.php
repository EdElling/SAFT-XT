<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\DynamicData\Files\FilePruner;
use App\Models\RecordFile;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;

/** System-Aufgabe: verwaiste Dateien aus Dateifeldern aufräumen wie „php artisan files:prune“ (B-021). */
final readonly class FilesPruneTask implements TaskType
{
    public function __construct(private FilePruner $pruner) {}

    public function key(): string
    {
        return 'files_prune';
    }

    public function label(): string
    {
        return __('tasks.types.files_prune');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.files_prune');
    }

    public function run(array $parameters): TaskResult
    {
        $result = $this->pruner->prune();
        $message = __('tasks.results.files_prune', ['deleted' => $result['deleted'], 'size' => RecordFile::sizeText($result['bytes'])]);

        if ($result['missing'] !== []) {
            $message .= ' '.trans_choice('tasks.results.files_missing', count($result['missing']), [
                'count' => count($result['missing']),
                'files' => implode(', ', array_slice($result['missing'], 0, 5)),
            ]);
        }

        return new TaskResult($message, $result);
    }
}
