<?php

declare(strict_types=1);

namespace App\Documents;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Bereinigt ein hochgeladenes SVG (Logo): ohne Skripte, eingebettetes HTML, Ereignis-Attribute und Verweise nach
 * aussen. Erlaubt bleiben Verweise innerhalb der Datei („#id“) und eingebettete Bilder als data:image.
 * DOCTYPE und Entitäten werden abgelehnt (XXE, „Billion Laughs“).
 */
final class SvgSanitizer
{
    private const array FORBIDDEN = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'animate', 'set', 'handler', 'listener'];

    /** Bereinigtes SVG, oder null, wenn die Datei kein gültiges SVG ist. */
    public static function sanitize(string $svg): ?string
    {
        if ($svg === '' || preg_match('/<!(DOCTYPE|ENTITY)/i', $svg) === 1) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement || strtolower($document->documentElement->localName) !== 'svg') {
            return null;
        }

        $xpath = new DOMXPath($document);

        /** @var list<DOMElement> $elements */
        $elements = iterator_to_array($xpath->query('//*') ?: []);

        foreach ($elements as $element) {
            if (in_array(strtolower($element->localName), self::FORBIDDEN, true)) {
                $element->parentNode?->removeChild($element);

                continue;
            }

            /** @var list<DOMAttr> $attributes */
            $attributes = iterator_to_array($element->attributes ?? []);

            foreach ($attributes as $attribute) {
                $name = strtolower($attribute->localName);
                $value = trim($attribute->value);

                if (str_starts_with($name, 'on')
                    || (in_array($name, ['href', 'src'], true) && ! str_starts_with($value, '#') && ! str_starts_with(strtolower($value), 'data:image/'))
                    || ($name === 'style' && $value !== PdfRenderer::sanitizeCss($value))) {
                    $element->removeAttributeNode($attribute);
                }
            }

            if (strtolower($element->localName) === 'style') {
                $element->textContent = PdfRenderer::sanitizeCss($element->textContent);
            }
        }

        return $document->saveXML($document->documentElement) ?: null;
    }
}
