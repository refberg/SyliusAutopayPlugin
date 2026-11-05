<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\EventListener;

use Refberg\SyliusAutopayPlugin\Provider\AcknowledgedNotificationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * @internal
 */
final readonly class AcknowledgedNotificationListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof AcknowledgedNotificationException) {
            return;
        }

        $event->allowCustomResponseCode();
        $event->setResponse(new Response($exception->confirmation, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']));
    }
}
