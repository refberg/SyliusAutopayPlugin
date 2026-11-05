<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\EventListener\AcknowledgedNotificationListener;
use Refberg\SyliusAutopayPlugin\Provider\AcknowledgedNotificationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

final class AcknowledgedNotificationListenerTest extends TestCase
{
    public function test_acknowledged_notification_is_answered_with_its_confirmation(): void
    {
        $event = self::event(new AcknowledgedNotificationException('<confirmationList/>'));

        (new AcknowledgedNotificationListener())($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('<confirmationList/>', $response->getContent());
        self::assertTrue($event->isAllowingCustomResponseCode());
    }

    public function test_other_exceptions_are_left_alone(): void
    {
        $event = self::event(new RuntimeException('x'));

        (new AcknowledgedNotificationListener())($event);

        self::assertNull($event->getResponse());
    }

    private static function event(Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
