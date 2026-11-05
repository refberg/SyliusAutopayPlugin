<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Xml;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Config\Environment;
use Refberg\SyliusAutopayPlugin\Config\HashAlgorithm;
use Refberg\SyliusAutopayPlugin\Notification\ConfirmationFactory;
use Refberg\SyliusAutopayPlugin\Notification\Notification;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use SimpleXMLElement;

final class ConfirmationFactoryTest extends TestCase
{
    public function test_it_matches_the_confirmation_of_the_autopay_sdk(): void
    {
        $credentials = new AutopayCredentials('123456', 'QCBm3N0oFjzQAWsTIVN8mPLK12TW6HU6InSfjvnF', HashAlgorithm::Sha256, Environment::Sandbox);

        $xml = Xml::parse((new ConfirmationFactory(new SignatureVerifier()))->create(new Notification(Notification::TYPE_TRANSACTION, '123456', '123'), $credentials, true));

        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        self::assertSame('confirmationList', $xml->getName());
        self::assertSame(['123456', '123', 'CONFIRMED', '7194a50431ce359cc4c201a0f154400f89f49f765b8eeb60299fc6fee1a01228'], [
            (string) $xml->serviceID,
            (string) $xml->transactionsConfirmations->transactionConfirmed->orderID,
            (string) $xml->transactionsConfirmations->transactionConfirmed->confirmation,
            (string) $xml->hash,
        ]);
    }

    public function test_not_confirmed_and_special_characters(): void
    {
        $credentials = new AutopayCredentials('1', 'key', HashAlgorithm::Sha256, Environment::Sandbox);

        $document = (new ConfirmationFactory(new SignatureVerifier()))->create(new Notification(Notification::TYPE_TRANSACTION, '1', 'A<&>'), $credentials, false);
        $xml = Xml::parse($document);

        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        self::assertSame('A<&>', (string) $xml->transactionsConfirmations->transactionConfirmed->orderID);
        self::assertSame('NOTCONFIRMED', (string) $xml->transactionsConfirmations->transactionConfirmed->confirmation);
        self::assertSame((new SignatureVerifier())->sign(['1', 'A<&>', 'NOTCONFIRMED'], $credentials), (string) $xml->hash);
    }
}
