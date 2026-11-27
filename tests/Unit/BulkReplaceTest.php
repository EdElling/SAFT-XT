<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Bulk\BulkReplace;
use PHPUnit\Framework\TestCase;

/** Sammeländerung „ersetzen“ (B-133 S2, BULK_EDIT.md §12 Fragen 9, 10): kein regulärer Ausdruck für die Person. */
class BulkReplaceTest extends TestCase
{
    public function test_replaces_all_matches_ignoring_case_by_default(): void
    {
        $this->assertSame('Hauptstrasse 5', BulkReplace::apply('Hauptstr. 5', 'str.', 'strasse', false, false));
        $this->assertSame('strasse des 1. Mai, strasse 2', BulkReplace::apply('Str. des 1. Mai, STR. 2', 'Str.', 'strasse', false, false));
        $this->assertSame(BulkReplace::NO_MATCH, BulkReplace::apply('Hauptstr. 5', 'Str.', 'Strasse', true, false), 'mit Gross-/Kleinschreibung');
    }

    public function test_whole_word_needs_no_letter_digit_or_underscore_around_the_match(): void
    {
        $this->assertSame('Strasse des 1. Mai', BulkReplace::apply('Str. des 1. Mai', 'Str.', 'Strasse', false, true));
        $this->assertSame(BulkReplace::NO_MATCH, BulkReplace::apply('Hauptstr. 5', 'Str.', 'Strasse', false, true));
        $this->assertSame('Ölweg (neu)', BulkReplace::apply('Ölweg (alt)', 'alt', 'neu', false, true));
        $this->assertSame(BulkReplace::NO_MATCH, BulkReplace::apply('Altstadt', 'alt', 'neu', false, true));
        $this->assertSame(BulkReplace::NO_MATCH, BulkReplace::apply('Über_alt', 'alt', 'neu', false, true));
    }

    public function test_special_characters_are_text_not_patterns(): void
    {
        $this->assertSame('Preis $1 (a/b)', BulkReplace::apply('Preis ? (a/b)', '?', '$1', true, false));
        $this->assertSame('x\\0y', BulkReplace::apply('x.y', '.', '\\0', true, false));
        $this->assertSame('ÄRGER', BulkReplace::apply('äRGER', 'ä', 'Ä', true, false), 'Unicode');
        $this->assertSame('Müller', BulkReplace::apply('MÜller', 'mü', 'Mü', false, false), 'Unicode ohne Gross-/Kleinschreibung');
    }

    public function test_no_match_empty_value_and_empty_result(): void
    {
        $this->assertSame(BulkReplace::NO_MATCH, BulkReplace::apply('Bahnhofweg', 'str.', 'strasse', false, false));
        $this->assertSame(BulkReplace::NO_MATCH, BulkReplace::apply(null, 'a', 'b', false, false));
        $this->assertSame(BulkReplace::NO_MATCH, BulkReplace::apply('', 'a', 'b', false, false));
        $this->assertNull(BulkReplace::apply(' tmp ', 'tmp', '', false, false), 'nur Leerzeichen gilt als leer');
        $this->assertSame('Bahnhof', BulkReplace::apply('Bahnhof (alt)', ' (alt)', '', false, false));
    }
}
