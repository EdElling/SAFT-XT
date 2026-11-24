<?php

declare(strict_types=1);

namespace App\Work;

use Illuminate\Support\Facades\Storage;

/**
 * Was ein Schritt bekommt: Parameter, Zustand, die verbleibende Zeit des Treibers und den Ordner für Ergebnisdateien.
 *
 * @api
 */
final readonly class WorkStep
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public array $parameters,
        public array $state,
        private float $deadline,
        private string $directory,
    ) {}

    /** Sekunden bis zum Ende des Zeitbudgets; ein Schritt soll vorher zurückkehren. */
    public function remaining(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    /** Absoluter Pfad einer Datei im Ordner der Arbeit (storage/app/private/work/<uuid>/); der Ordner wird angelegt. */
    public function path(string $file): string
    {
        Storage::disk('local')->makeDirectory($this->directory);

        return Storage::disk('local')->path($this->directory.'/'.basename($file));
    }
}
