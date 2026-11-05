<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Component\Core\Model\AdminUserInterface;
use function trim;

final class AdminOrderPaymentDetailsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->factory('admin_user')->createNew();
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $admin->setUsername('admin');
        $admin->setEmail('admin@example.com');
        $admin->setPlainPassword('admin');
        $admin->setLocaleCode('en_US');
        $admin->setEnabled(true);
        $this->em()->persist($admin);
        $this->em()->flush();

        $this->client->loginUser($admin, 'admin');
    }

    public function test_order_page_shows_the_autopay_transaction_and_warnings(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $remoteId = $this->autopay->transaction($orderId)['attempts'][0]['remoteID'];
        $this->autopay->setStatus($orderId, 'SUCCESS');
        $this->autopay->addAttempt($orderId, 'SUCCESS');
        $this->notifyOrder($orderId);
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'order_id' => $orderId]], $this->details($payment)->warnings());

        $orderDbId = $this->reloadPayment($payment)->getOrder()?->getId();
        self::assertIsInt($orderDbId);
        $crawler = $this->client->request('GET', '/admin/orders/' . $orderDbId);

        self::assertResponseIsSuccessful();
        $cell = $crawler->filterXPath('//*[@data-test-autopay-details]');
        self::assertCount(1, $cell);
        self::assertStringContainsString($orderId, $cell->text());
        self::assertStringContainsString($remoteId, $cell->text());
        self::assertStringContainsString('SUCCESS', $cell->text());
        self::assertStringContainsString('Paid twice', $crawler->filterXPath('//*[@data-test-autopay-warning]')->text());
    }

    public function test_order_page_without_autopay_data_renders_an_empty_cell(): void
    {
        $payment = $this->createPlacedOrder();
        $orderDbId = $payment->getOrder()?->getId();
        self::assertIsInt($orderDbId);

        $crawler = $this->client->request('GET', '/admin/orders/' . $orderDbId);

        self::assertResponseIsSuccessful();
        self::assertSame('', trim($crawler->filterXPath('//*[@data-test-autopay-details]')->text()));
    }
}
