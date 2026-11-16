<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

/** Ergebnis der Prüfung einer Import-Datei. */
final readonly class ImportAnalysis
{
    /**
     * @param  list<ImportColumn>  $columns
     * @param  list<ImportProblem>  $problems
     * @param  list<string>  $sheets
     */
    public function __construct(
        public ?ImportFormat $format,
        public array $columns = [],
        public array $problems = [],
        public int $rowCount = 0,
        public ?string $sheet = null,
        public array $sheets = [],
        public ?string $delimiter = null,
        public ?string $encoding = null,
        public ?string $tableName = null,
    ) {}

    public function isImportable(): bool
    {
        return $this->errors() === [];
    }

    /** @return list<ImportProblem> */
    public function errors(): array
    {
        return array_values(array_filter($this->problems, fn (ImportProblem $p): bool => $p->isError()));
    }

    /** @return list<ImportProblem> */
    public function warnings(): array
    {
        return array_values(array_filter($this->problems, fn (ImportProblem $p): bool => ! $p->isError()));
    }

    /** @return list<string> */
    public function messages(?string $severity = null): array
    {
        return array_values(array_map(
            fn (ImportProblem $p): string => $p->message,
            array_filter($this->problems, fn (ImportProblem $p): bool => $severity === null || $p->severity === $severity),
        ));
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_map(fn (ImportProblem $p): string => $p->code, $this->problems)));
    }

    public function column(string $name): ?ImportColumn
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }
}
