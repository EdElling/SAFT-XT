<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Documentation\Documentation;
use PHPUnit\Framework\TestCase;

/**
 * Handbücher in docs/manual und die Architekturbeschreibung docs/ARCHITECTURE.*.md: Sprungmarken führen zu vorhandenen
 * Überschriften (gleiche Regel wie die Online-Hilfe), und alle Sprachfassungen eines Dokuments haben dieselbe Gliederung
 * (Ebenen, Kapitelnummern, Anhänge).
 */
class ManualStructureTest extends TestCase
{
    private const string DIR = __DIR__.'/../../docs/manual/';

    private const string DOCS = __DIR__.'/../../docs/';

    /** @return list<array{level: int, title: string}> */
    private function headings(string $file): array
    {
        $text = (string) preg_replace('/^```.*?^```/ms', '', $this->contents($file));
        preg_match_all('/^(#{1,6}) (.+)$/m', $text, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => ['level' => strlen($m[1]), 'title' => trim($m[2])], $matches);
    }

    private function contents(string $file): string
    {
        return (string) file_get_contents(str_starts_with($file, 'ARCHITECTURE') ? self::DOCS.$file : self::DIR.$file);
    }

    /** @return list<string> Handbücher und ARCHITECTURE.*.md, als Dateinamen */
    private function files(): array
    {
        return array_map('basename', [...glob(self::DIR.'*.md') ?: [], ...glob(self::DOCS.'ARCHITECTURE*.md') ?: []]);
    }

    public function test_every_anchor_link_points_to_an_existing_heading(): void
    {
        $anchors = [];

        foreach ($this->files() as $file) {
            $anchors[$file] = array_map(fn (array $h): string => Documentation::slug($h['title']), $this->headings($file));
        }

        $broken = [];

        foreach ($this->files() as $file) {
            preg_match_all('/\]\(([A-Z_]+(?:\.[a-z]{2})?\.md)?#([^)\s]+)\)/', $this->contents($file), $links, PREG_SET_ORDER);

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
        $this->assertCount(2, $groups['ARCHITECTURE'] ?? [], 'ARCHITECTURE.de.md und ARCHITECTURE.md');
    }

    public function test_each_manual_numbers_its_own_chapters_from_one(): void
    {
        foreach (['USER_GUIDE', 'ADMIN_GUIDE', 'SYSADMIN_GUIDE', 'ARCHITECTURE'] as $name) {
            $numbers = array_values(array_filter(array_map(
                fn (array $h): ?int => $h['level'] === 2 && preg_match('/^(\d+)\. /', $h['title'], $m) === 1 ? (int) $m[1] : null,
                $this->headings($name.'.md'),
            )));

            $this->assertSame(range(1, count($numbers)), $numbers, $name);
        }
    }
}
