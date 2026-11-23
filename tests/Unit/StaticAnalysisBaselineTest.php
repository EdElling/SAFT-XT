<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die PHPStan-Baseline (phpstan-baseline.neon) darf nur kleiner werden (Fundament 2, F0). Wer Meldungen behebt,
 * erzeugt die Baseline neu (vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon) und senkt
 * MAX_ERRORS auf den neuen Stand. Neue Meldungen gehören nicht in die Baseline, sondern werden behoben.
 */
class StaticAnalysisBaselineTest extends TestCase
{
    /** Stand beim Einführen von Larastan Level 5 (23.11.2026). */
    private const int MAX_ERRORS = 324;

    public function test_baseline_does_not_grow(): void
    {
        $baseline = (string) file_get_contents(__DIR__.'/../../phpstan-baseline.neon');
        preg_match_all('/^\s+count: (\d+)$/m', $baseline, $matches);
        $errors = array_sum(array_map('intval', $matches[1]));

        $this->assertLessThanOrEqual(self::MAX_ERRORS, $errors, 'Die PHPStan-Baseline ist gewachsen: neue Meldungen beheben statt in die Baseline aufnehmen.');
    }
}
