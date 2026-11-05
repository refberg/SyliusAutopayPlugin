<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api;

use SimpleXMLElement;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function simplexml_load_string;
use function stripos;
use function trim;
use const LIBXML_NONET;

/**
 * Safe XML reading for Autopay messages: no network access, no DTD (and so no entities).
 *
 * @internal
 */
final class Xml
{
    private function __construct()
    {
    }

    public static function parse(string $xml): ?SimpleXMLElement
    {
        if ('' === trim($xml) || false !== stripos($xml, '<!DOCTYPE')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return false === $document ? null : $document;
    }

    /**
     * Text of a child element without children, or null when it is missing or empty.
     */
    public static function text(SimpleXMLElement $parent, string $name): ?string
    {
        foreach ($parent->children() as $childName => $element) {
            if ($childName !== $name) {
                continue;
            }

            $value = trim((string) $element);

            return $element->children()->count() > 0 || '' === $value ? null : $value;
        }

        return null;
    }

    /**
     * Texts of all leaf elements in document order, skipping the root "hash" element and empty values.
     * Autopay signs messages with these values.
     *
     * @return list<string>
     */
    public static function signedValues(SimpleXMLElement $root): array
    {
        $values = [];
        foreach ($root->children() as $name => $child) {
            if ('hash' === $name) {
                continue;
            }

            self::collect($child, $values);
        }

        return $values;
    }

    /**
     * @param list<string> $values
     */
    private static function collect(SimpleXMLElement $element, array &$values): void
    {
        if ($element->children()->count() > 0) {
            foreach ($element->children() as $child) {
                self::collect($child, $values);
            }

            return;
        }

        $value = trim((string) $element);
        if ('' !== $value) {
            $values[] = $value;
        }
    }
}
