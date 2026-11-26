<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Notification\ConfirmationFactory;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParser;
use Refberg\SyliusAutopayPlugin\Provider\NotifyResponseProvider;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\HandlerFixtures;

final class NotifyResponseProviderTest extends TestCase
{
    public function test_stored_itn_is_confirmed(): void
    {
        $response = self::provider()->provide(self::notifyRequest(HandlerFixtures::method(), FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS'])));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('<confirmation>CONFIRMED</confirmation>', (string) $response->getContent());
    }

    public function test_invalid_configuration_is_answered_with_503(): void
    {
        $method = HandlerFixtures::method();
        $method->getGatewayConfig()?->setConfig([AutopayGateway::CONFIG_ENVIRONMENT => 'sandbox']);

        $response = self::provider()->provide(self::notifyRequest($method, FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS'])));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function test_invalid_itn_is_answered_with_400(): void
    {
        $response = self::provider()->provide(self::notifyRequest(HandlerFixtures::method(), FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS'], 'other-key')));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function test_other_gateways_keep_the_decorated_response(): void
    {
        $method = new PaymentMethod();
        $method->setCode('OFFLINE');
        $decoratedResponse = new Response('core');
        $decorated = self::createStub(NotifyResponseProviderInterface::class);
        $decorated->method('provide')->willReturn($decoratedResponse);

        self::assertSame($decoratedResponse, self::provider($decorated)->provide(self::notifyRequest($method, '')));
    }

    private static function provider(?NotifyResponseProviderInterface $decorated = null): NotifyResponseProvider
    {
        return new NotifyResponseProvider(
            $decorated ?? self::createStub(NotifyResponseProviderInterface::class),
            new CredentialsResolver(),
            new NotificationParser(new SignatureVerifier()),
            new ConfirmationFactory(new SignatureVerifier()),
        );
    }

    private static function notifyRequest(PaymentMethod $method, string $body): PaymentRequestInterface
    {
        $paymentRequest = HandlerFixtures::paymentRequest(HandlerFixtures::payment($method, PaymentInterface::STATE_NEW), $method, PaymentRequestInterface::ACTION_NOTIFY);
        $paymentRequest->setPayload(['http_request' => ['content' => $body, 'headers' => []]]);

        return $paymentRequest;
    }
}
