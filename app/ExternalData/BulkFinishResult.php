<?php

declare(strict_types=1);

namespace App\ExternalData;

/**
 * Abschluss einer Massenverknüpfung: ermittelte Verknüpfungen und automatisch eingerichtete
 * Master-Detail-Beziehungen.
 */
final readonly class BulkFinishResult
{
    /**
     * @param  array<string, list<string>>  $details  Tabelle => eingerichtete Details („Tabelle.Feld“)
     * @param  array<string, int>  $tooMany  Tabelle => Zahl der Verweise, bei denen nichts automatisch eingerichtet wurde
     */
    public function __construct(
        public int $links,
        public array $details = [],
        public array $tooMany = [],
    ) {}

    /** Zahl der eingerichteten Detailtabellen insgesamt. */
    public function detailCount(): int
    {
        return array_sum(array_map('count', $this->details));
    }
}
