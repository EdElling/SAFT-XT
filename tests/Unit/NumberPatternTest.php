<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Numbering\NumberPattern;
use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Muster der Nummernkreise (B-135): Prüfung, Periode, Formatieren, Zurücklesen. */
class NumberPatternTest extends TestCase
{
    public function test_formats_with_padding_year_and_month(): void
    {
        $pattern = NumberPattern::make('R-{YYYY}-{0000}', 'yearly');

        $this->assertSame('R-2026-0042', $pattern->format(42, 2026, 11));
        $this->assertSame('R-2026-12345', $pattern->format(12345, 2026, 11), 'Überlauf verlängert, schneidet nie ab.');
        $this->assertSame('B26/11-7', NumberPattern::make('B{YY}/{MM}-{#}', 'monthly')->format(7, 2026, 11));
    }

    public function test_german_aliases_are_accepted(): void
    {
        $this->assertSame('R-{YYYY}-{000}', NumberPattern::make('R-{JJJJ}-{000}', 'yearly')->pattern);
    }

    public function test_period_follows_reset_and_fiscal_year(): void
    {
        $date = CarbonImmutable::parse('2026-03-15');

        $this->assertSame(['key' => '2026', 'year' => 2026, 'month' => 3], NumberPattern::make('{YYYY}-{000}', 'yearly')->period($date));
        $this->assertSame(['key' => '2025', 'year' => 2025, 'month' => 3], NumberPattern::make('{YYYY}-{000}', 'yearly', fiscalStart: 7)->period($date), 'Geschäftsjahr ab Juli: März 2026 gehört zu 2025.');
        $this->assertSame('2026', NumberPattern::make('{YYYY}-{000}', 'yearly', fiscalStart: 7)->period(CarbonImmutable::parse('2026-07-01'))['key']);
        $this->assertSame('2026-03', NumberPattern::make('{YYYY}{MM}-{000}', 'monthly')->period($date)['key']);
        $this->assertSame(NumberPattern::NO_PERIOD, NumberPattern::make('K-{00000}')->period($date)['key']);
    }

    public function test_parse_reads_back_period_and_number(): void
    {
        $pattern = NumberPattern::make('R-{YYYY}-{0000}', 'yearly');

        $this->assertSame(['key' => '2025', 'group' => '', 'number' => 17], $pattern->parse('R-2025-0017'));
        $this->assertSame(['key' => '2025', 'group' => '', 'number' => 12345], $pattern->parse('R-2025-12345'));
        $this->assertNull($pattern->parse('R-2025-017'), 'Andere Auffüllung passt nicht.');
        $this->assertNull($pattern->parse('R-2025-00017'));
        $this->assertNull($pattern->parse('RE-2025-0017'));
        $this->assertSame(['key' => '2026-02', 'group' => '', 'number' => 3], NumberPattern::make('{YY}{MM}-{#}', 'monthly')->parse('2602-3'));
        $this->assertSame(['key' => NumberPattern::NO_PERIOD, 'group' => '', 'number' => 99], NumberPattern::make('K{#}')->parse('K99'));
    }

    public function test_group_placeholder_formats_and_reads_back_the_code(): void
    {
        $pattern = NumberPattern::make('{GROUP}-{YYYY}-{000}', 'yearly', groups: ['V', 'E', 'A-B']);

        $this->assertSame('V-2026-007', $pattern->format(7, 2026, 11, 'V'));
        $this->assertSame(['key' => '2026', 'group' => 'E', 'number' => 12], $pattern->parse('E-2026-012'));
        $this->assertSame(['key' => '2026', 'group' => 'A-B', 'number' => 1], $pattern->parse('A-B-2026-001'), 'Kurzzeichen mit Bindestrich bleiben eindeutig.');
        $this->assertNull($pattern->parse('X-2026-001'), 'Nur bekannte Kurzzeichen.');
        $this->assertSame(['A-B', 'E', 'V'], $pattern->groups, 'Längste zuerst.');
        $this->assertSame(12, $pattern->maxLength(), 'Das längste Kurzzeichen zählt.');
        $this->assertTrue($pattern->differs(NumberPattern::make('{GROUP}-{YYYY}-{000}', 'yearly', groups: ['V', 'E'])));
        $this->assertFalse($pattern->differs(NumberPattern::make('{GROUP}-{YYYY}-{000}', 'yearly', groups: ['E', 'A-B', 'V'])));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>|null}>
     */
    public static function invalidGroupPatterns(): array
    {
        return [
            'Gruppe ohne {GROUP}' => ['R-{0000}', ['V']],
            'zweimal {GROUP}' => ['{GROUP}{GROUP}-{0000}', ['V']],
            '{GROUP} ohne Gruppe' => ['{GROUP}-{0000}', null],
            'Kurzzeichen mit Umlaut' => ['{GROUP}-{0000}', ['Ü']],
            'Kurzzeichen mit Leerzeichen' => ['{GROUP}-{0000}', ['A B']],
            'Kurzzeichen zu lang' => ['{GROUP}-{0000}', ['ABCDEFGHIJK']],
            'zu lang mit Kurzzeichen' => [str_repeat('X', 40).'{GROUP}{0000}', ['ABCDEFGH']],
        ];
    }

    /** @param  list<string>|null  $groups */
    #[DataProvider('invalidGroupPatterns')]
    public function test_invalid_group_patterns_are_rejected(string $pattern, ?array $groups): void
    {
        $this->expectException(BusinessRuleException::class);

        NumberPattern::make($pattern, groups: $groups);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidPatterns(): array
    {
        return [
            'ohne laufende Nummer' => ['R-{YYYY}', 'never'],
            'zwei laufende Nummern' => ['{000}-{#}', 'never'],
            'jährlich ohne Jahr' => ['R-{0000}', 'yearly'],
            'monatlich ohne Monat' => ['{YYYY}-{0000}', 'monthly'],
            'unbekannter Platzhalter' => ['{DD}-{0000}', 'never'],
            'unerlaubtes Zeichen' => ['R#{0000}', 'never'],
            'offene Klammer' => ['R-{0000', 'never'],
            'zu lang' => [str_repeat('X', 46).'{00000}', 'never'],
            'leer' => ['', 'never'],
            'unbekannter Neubeginn' => ['{0000}', 'weekly'],
        ];
    }

    #[DataProvider('invalidPatterns')]
    public function test_invalid_patterns_are_rejected(string $pattern, string $reset): void
    {
        $this->expectException(BusinessRuleException::class);

        NumberPattern::make($pattern, $reset);
    }
}
