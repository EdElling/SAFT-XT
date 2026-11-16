<?php

declare(strict_types=1);

namespace App\ExternalData;

final readonly class ConnectionTestResult
{
    /**
     * @param  list<string>  $schemas
     */
    public function __construct(
        public bool $successful,
        public string $message,
        public ?string $serverVersion = null,
        public array $schemas = [],
    ) {}
}
