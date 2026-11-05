<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Api;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Xml;
use SimpleXMLElement;

final class XmlTest extends TestCase
{
    public function test_signed_values_are_leaf_values_in_document_order_without_the_root_hash(): void
    {
        $xml = Xml::parse('<?xml version="1.0"?><a><b>1</b><c><d> 2 </d><e></e><hash>3</hash></c><f>4</f><hash>h</hash></a>');

        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        self::assertSame(['1', '2', '3', '4'], Xml::signedValues($xml));
        self::assertSame('h', Xml::text($xml, 'hash'));
        self::assertSame('1', Xml::text($xml, 'b'));
        self::assertNull(Xml::text($xml, 'c'));
        self::assertNull(Xml::text($xml, 'missing'));
    }

    public function test_empty_text_is_null(): void
    {
        $xml = Xml::parse('<a><b>  </b></a>');

        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        self::assertNull(Xml::text($xml, 'b'));
    }

    public function test_documents_with_a_dtd_or_invalid_xml_are_rejected(): void
    {
        self::assertNull(Xml::parse('<?xml version="1.0"?><!DOCTYPE a [<!ENTITY e SYSTEM "file:///etc/passwd">]><a>&e;</a>'));
        self::assertNull(Xml::parse('<a><b></a>'));
        self::assertNull(Xml::parse(''));
        self::assertNull(Xml::parse('ERROR'));
    }
}
