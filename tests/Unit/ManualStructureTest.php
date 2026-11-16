<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Documentation\Documentation;
use PHPUnit\Framework\TestCase;

/**
 * Handbücher in docs/manual: Sprungmarken führen zu vorhandenen Überschriften (gleiche Regel wie die Online-Hilfe),
 * und alle Sprachfassungen eines Handbuchs haben dieselbe Gliederung (Ebenen, Kapitelnummern, Anhänge).
 */
class ManualStructureTest extends TestCase
{
    private const string DIR = __DIR__.'/../../docs/manual/';

    /** @return list<array{level: int, title: string}> */
    private function headings(string $file): array
    {
        $text = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents(self::DIR.$file));
        preg_match_all('/^(#{1,6}) (.+)$/m', $text, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => ['level' => strlen($m[1]), 'title' => trim($m[2])], $matches);
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_map('basename', glob(self::DIR.'*.md') ?: []);
    }

    public function test_every_anchor_link_points_to_an_existing_heading(): void
    {
        $anchors = [];

        foreach ($this->files() as $file) {
            $anchors[$file] = array_map(fn (array $h): string => Documentation::slug($h['title']), $this->headings($file));
        }

        $broken = [];

        foreach ($this->files() as $file) {
            preg_match_all('/\]\(([A-Z_]+(?:\.[a-z]{2})?\.md)?#([^)\s]+)\)/', (string) file_get_contents(self::DIR.$file), $links, PREG_SET_ORDER);

            foreach ($links as $link) {
                $target = $link[1] !== '' ? $link[1] : $file;

                if (! in_array($link[2], $anchors[$target] ?? [], true)) {
                    $broken[] = "{$file} → {$target}#{$link[2]}";
                }
            }
        }

        $this->assertSame([], $broken);
    }

    public function test_all_languages_of_a_manual_have_the_same_outline(): void
    {
        $groups = [];

        foreach ($this->files() as $file) {
            $groups[(string) preg_replace('/(\.[a-z]{2})?\.md$/', '', $file)][] = $file;
        }

        // Gliederung: Ebene und Nummer bzw. Anhang-Buchstabe; der Titel selbst ist übersetzt.
        $outline = fn (string $file): array => array_map(function (array $h): string {
            preg_match('/^(\d+(?:\.\d+)*\.?)\s|^(?:Anhang|Appendix|Annexe|Allegato) ([A-Z])\b/u', $h['title'], $m);

            return $h['level'].':'.($m[1] ?? (isset($m[2]) ? 'Anhang '.$m[2] : ''));
        }, $this->headings($file));

        foreach ($groups as $name => $files) {
            $reference = $outline($files[0]);

            foreach (array_slice($files, 1) as $file) {
                $this->assertSame($reference, $outline($file), "Gliederung von {$file} weicht von {$files[0]} ab ({$name}).");
            }
        }

        $this->assertArrayHasKey('USER_GUIDE', $groups);
    }

    public function test_each_manual_numbers_its_own_chapters_from_one(): void
    {
        foreach (['USER_GUIDE', 'ADMIN_GUIDE', 'SYSADMIN_GUIDE'] as $name) {
            $numbers = array_values(array_filter(array_map(
                fn (array $h): ?int => $h['level'] === 2 && preg_match('/^(\d+)\. /', $h['title'], $m) === 1 ? (int) $m[1] : null,
                $this->headings($name.'.md'),
            )));

            $this->assertSame(range(1, count($numbers)), $numbers, $name);
        }
    }
}
