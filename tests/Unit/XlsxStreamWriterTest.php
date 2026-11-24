<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Export\XlsxStreamWriter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Bausteine des anhängbaren Excel-Schreibers (Fundament 2, F3b). */
class XlsxStreamWriterTest extends TestCase
{
    public function test_column_letters(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AB', 'ZZ', 'AAA'], array_map(XlsxStreamWriter::column(...), [0, 25, 26, 27, 701, 702]));
    }

    public function test_excel_serial_dates(): void
    {
        $this->assertSame('46054', XlsxStreamWriter::serial(new DateTimeImmutable('2026-02-01')));
        $this->assertSame('46054.5', XlsxStreamWriter::serial(new DateTimeImmutable('2026-02-01 12:00:00')));
        $this->assertSame('25569', XlsxStreamWriter::serial(new DateTimeImmutable('1970-01-01 00:00:00', new \DateTimeZone('Europe/Zurich'))), 'Zeitzone des Werts bleibt unangetastet');
    }

    public function test_escaping_removes_what_xml_forbids(): void
    {
        $this->assertSame('&lt;a&gt; &amp; &quot;b&quot; &apos;c&apos;', XlsxStreamWriter::escape('<a> & "b" \'c\''));
        $this->assertSame('ab', XlsxStreamWriter::escape("a\x00\x01b"));
        $this->assertSame("a\tb\nc", XlsxStreamWriter::escape("a\tb\nc"));
    }

    public function test_styles_are_two_plus_formats(): void
    {
        $formats = ['0', '#,##0.00', 'dd.mm.yyyy'];
        $this->assertSame(2, XlsxStreamWriter::styleFor($formats, '0'));
        $this->assertSame(4, XlsxStreamWriter::styleFor($formats, 'dd.mm.yyyy'));
        $this->assertSame(XlsxStreamWriter::STYLE_DEFAULT, XlsxStreamWriter::styleFor($formats, 'unbekannt'));
    }
}
