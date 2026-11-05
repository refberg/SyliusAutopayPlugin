<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use DateTime;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_url;
use function strtolower;
use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * Runs the real Sylius test application with the Autopay HTTP client replaced by FakeAutopay.
 */
abstract class IntegrationTestCase extends WebTestCase
{
    protected const METHOD_CODE = 'AUTOPAY';

    protected KernelBrowser $client;

    protected FakeAutopay $autopay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        // Keep one kernel (and one FakeAutopay state) for all requests of a test.
        $this->client->disableReboot();

        $this->autopay = self::service(FakeAutopay::class, FakeAutopay::class);

        $connection = $this->em()->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        (new ORMPurger($this->em()))->purge();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function em(): EntityManagerInterface
    {
        return self::service(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
    }

    /**
     * Creates a placed order (checkout completed) with one Autopay payment of 15.00 PLN awaiting payment.
     */
    protected function createPlacedOrder(int $amount = 1500): PaymentInterface
    {
        $locale = $this->factory('locale')->createNew();
        self::assertInstanceOf(LocaleInterface::class, $locale);
        $locale->setCode('en_US');

        $currency = $this->factory('currency')->createNew();
        self::assertInstanceOf(CurrencyInterface::class, $currency);
        $currency->setCode('PLN');

        $channel = $this->factory('channel')->createNew();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $channel->setCode('WEB');
        $channel->setName('Web');
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setTaxCalculationStrategy('order_items_based');

        $method = $this->createAutopayMethod(self::METHOD_CODE, FakeAutopay::SERVICE_ID, FakeAutopay::SHARED_KEY);
        $method->addChannel($channel);

        $customer = $this->factory('customer')->createNew();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $customer->setEmail('buyer@example.com');

        $order = $this->factory('order')->createNew();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setChannel($channel);
        $order->setCurrencyCode('PLN');
        $order->setLocaleCode('en_US');
        $order->setCustomer($customer);
        $order->setNumber('000000001');
        $order->setTokenValue('order-token');
        $order->setCheckoutState('completed');
        $order->setState('new');
        $order->setPaymentState('awaiting_payment');
        $order->setCustomerIp('10.0.0.1');
        $order->setCheckoutCompletedAt(new DateTime());

        $payment = $this->factory('payment')->createNew();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod($method);
        $payment->setAmount($amount);
        $payment->setCurrencyCode('PLN');
        $payment->setState('new');
        $order->addPayment($payment);

        $em = $this->em();
        foreach ([$locale, $currency, $channel, $customer, $order, $payment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $payment;
    }

    /**
     * Creates an Autopay payment method, persisted but not flushed.
     */
    protected function createAutopayMethod(string $code, string $serviceId, string $sharedKey): PaymentMethodInterface
    {
        $gatewayConfig = $this->factory('gateway_config')->createNew();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);
        $gatewayConfig->setFactoryName(AutopayGateway::FACTORY_NAME);
        $gatewayConfig->setGatewayName(strtolower($code));
        $gatewayConfig->setConfig([
            AutopayGateway::CONFIG_ENVIRONMENT => 'sandbox',
            AutopayGateway::CONFIG_SERVICE_ID => $serviceId,
            AutopayGateway::CONFIG_SHARED_KEY => $sharedKey,
            AutopayGateway::CONFIG_HASH_ALGORITHM => 'sha256',
        ]);
        if ($gatewayConfig instanceof GatewayConfig) {
            $gatewayConfig->setUsePayum(false);
        }

        $method = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Autopay');
        $method->setEnabled(true);
        $method->setGatewayConfig($gatewayConfig);

        $this->em()->persist($gatewayConfig);
        $this->em()->persist($method);

        return $method;
    }

    /**
     * Creates and dispatches a payment request the same way the plugin's payment transition listener does.
     */
    protected function dispatchPaymentRequest(PaymentInterface $payment, string $action): void
    {
        $payment = $this->reloadPayment($payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $paymentRequest = self::service(PaymentRequestFactoryInterface::class, 'sylius.factory.payment_request')->create($payment, $method);
        $paymentRequest->setAction($action);
        self::service(PaymentRequestRepositoryInterface::class, 'sylius.repository.payment_request')->add($paymentRequest);

        self::service(PaymentRequestAnnouncerInterface::class, 'sylius.announcer.payment_request')->dispatchPaymentRequestCommand($paymentRequest);
    }

    /**
     * Goes through the shop "pay" flow and returns the Autopay page URL the customer is redirected to.
     */
    protected function payInShop(): string
    {
        $this->client->request('GET', '/en_US/order/order-token/pay');
        $response = $this->client->getResponse();
        self::assertTrue($response->isRedirect(), (string) $response->getContent());

        $this->client->request('GET', (string) $response->headers->get('Location'));
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->headers->get('Location');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    protected function postPaymentRequest(array $body): array
    {
        $this->client->request(
            'POST',
            '/api/v2/shop/orders/order-token/payment-requests',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    protected function notify(string $body, string $methodCode = self::METHOD_CODE): Response
    {
        $this->client->request('POST', '/payment-methods/' . $methodCode, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: $body);

        return $this->client->getResponse();
    }

    protected function notifyOrder(string $orderId, ?string $reportedStatus = null): Response
    {
        return $this->notify($this->autopay->itnBody($orderId, $reportedStatus));
    }

    /**
     * Path of the ReturnURL sent to Autopay for the transaction.
     */
    protected function returnPath(string $orderId): string
    {
        return (string) parse_url($this->autopay->transaction($orderId)['fields']['ReturnURL'] ?? '', PHP_URL_PATH);
    }

    protected function reloadPayment(PaymentInterface $payment): PaymentInterface
    {
        $this->em()->clear();
        $reloaded = $this->em()->find($payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $reloaded);

        return $reloaded;
    }

    protected function details(PaymentInterface $payment): PaymentDetails
    {
        return PaymentDetails::of($this->reloadPayment($payment));
    }

    /**
     * @return list<PaymentRequestInterface>
     */
    protected function paymentRequests(string $action): array
    {
        $this->em()->clear();
        $repository = self::service(ObjectRepository::class, 'sylius.repository.payment_request');

        /** @var list<PaymentRequestInterface> $requests */
        $requests = $repository->findBy(['action' => $action]);

        return $requests;
    }

    /**
     * Reads a nested value from decoded JSON, failing the test when the path does not exist.
     *
     * @param array<array-key, mixed> $data
     */
    protected static function at(array $data, string ...$path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return FactoryInterface<object>
     */
    protected function factory(string $resource): FactoryInterface
    {
        return self::service(FactoryInterface::class, 'sylius.factory.' . $resource);
    }

    /**
     * Fetches a service from the test container and checks its type.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function service(string $class, string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
