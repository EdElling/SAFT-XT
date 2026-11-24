<?php

declare(strict_types=1);

namespace App\DynamicData\Export;

use DateTimeInterface;

/** Eine Zelle für den XlsxStreamWriter: Text, Zahl oder Datum mit Stil-Index. */
final readonly class XlsxCell
{
    public const string TEXT = 't';

    public const string NUMBER = 'n';

    public const string DATE = 'd';

    private function __construct(public string $kind, public string|DateTimeInterface $value, public int $style) {}

    public static function text(string $value, bool $bold = false): self
    {
        return new self(self::TEXT, $value, $bold ? XlsxStreamWriter::STYLE_BOLD : XlsxStreamWriter::STYLE_DEFAULT);
    }

    /** @param  string  $value  Zahl als Text, wie die Datenbank sie liefert (z. B. „1234.50“, „-3“) */
    public static function number(string $value, int $style): self
    {
        return new self(self::NUMBER, $value, $style);
    }

    public static function date(DateTimeInterface $value, int $style): self
    {
        return new self(self::DATE, $value, $style);
    }
}
