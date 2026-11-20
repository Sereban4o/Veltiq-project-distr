<?php

namespace App\Support;

/**
 * Minimal SVG sanitizer for uploaded logos and favicons.
 *
 * SVG is served from our own origin, so scripts, event handlers and external
 * references have to go before the file is written to the public disk.
 */
class SvgSanitizer
{
    private const DANGEROUS_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'style',
        // SMIL animation can rewrite href/other attributes after sanitising.
        'animate', 'animatemotion', 'animatetransform', 'set',
    ];

    public static function clean(?string $svg): ?string
    {
        if ($svg === null || trim($svg) === '') {
            return null;
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $doc->documentElement instanceof \DOMElement) {
            return null;
        }

        foreach (iterator_to_array($doc->getElementsByTagName('*')) as $element) {
            if (in_array(strtolower($element->nodeName), self::DANGEROUS_ELEMENTS, true)) {
                $element->parentNode?->removeChild($element);
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);

                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attribute->name);
                    continue;
                }

                if (! in_array($name, ['href', 'src'], true) && ! str_ends_with($name, ':href')) {
                    continue;
                }

                $value = trim($attribute->value);

                if (self::isSafeReference($value)) {
                    continue;
                }

                $element->removeAttribute($attribute->name);
            }
        }

        return $doc->saveXML($doc->documentElement) ?: null;
    }

    private static function isSafeReference(string $value): bool
    {
        $compact = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        // In-document references (gradients, clip paths) and inline raster images.
        if (str_starts_with($compact, '#')) {
            return true;
        }

        return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);#i', $compact);
    }
}
