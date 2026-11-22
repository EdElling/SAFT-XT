<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * Wirksames Briefpapier eines Dokuments (Installation, ergänzt um die Abweichungen der App). Dateipfade sind absolut
 * und vorhanden – fehlende Dateien sind schon aussortiert.
 */
final readonly class Letterhead
{
    public const array DEFAULT_MARGINS = [
        'first' => ['top' => 45, 'right' => 20, 'bottom' => 25, 'left' => 22],
        'next' => ['top' => 30, 'right' => 20, 'bottom' => 25, 'left' => 22],
    ];

    /** Logo im Kopf: Höhe in mm (Vorgabe, Grenzen) und grösste Breite. */
    public const float DEFAULT_LOGO_HEIGHT = 15.0;

    public const float LOGO_MIN_HEIGHT = 5.0;

    public const float LOGO_MAX_HEIGHT = 40.0;

    public const float LOGO_MAX_WIDTH = 80.0;

    public const string DEFAULT_PRIMARY = '#1f2937';

    public const string DEFAULT_SECONDARY = '#6b7280';

    public const string DEFAULT_TEXT = '#111827';

    /**
     * @param  array{regular: string|null, bold: string|null, italic: string|null}  $fonts
     * @param  array{first: array{top: float, right: float, bottom: float, left: float}, next: array{top: float, right: float, bottom: float, left: float}}  $margins
     * @param  array{firma: string|null, adresse: string|null, mwst: string|null, iban: string|null, kontakt: string|null}  $sender
     */
    public function __construct(
        public ?string $firstPage,
        public ?string $nextPages,
        public ?string $logo,
        public float $logoHeight,
        public array $fonts,
        public string $primary,
        public string $secondary,
        public string $text,
        public array $margins,
        public string $addressPosition,
        public ?string $senderLine,
        public array $sender,
        public bool $pdfa,
    ) {}

    public function hasHouseFont(): bool
    {
        return $this->fonts['regular'] !== null;
    }
}
