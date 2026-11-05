<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Refberg\SyliusAutopayPlugin\Api\AutopayClient;
use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Refberg\SyliusAutopayPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusAutopayPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusAutopayPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusAutopayPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusAutopayPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusAutopayPlugin\CommandHandler\CancelPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\CommandProvider\PaymentRequestCommandProvider;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Console\RefreshRefundsCommand;
use Refberg\SyliusAutopayPlugin\EventListener\AcknowledgedNotificationListener;
use Refberg\SyliusAutopayPlugin\EventListener\PaymentTransitionListener;
use Refberg\SyliusAutopayPlugin\Form\Type\AutopayGatewayConfigurationType;
use Refberg\SyliusAutopayPlugin\Notification\ConfirmationFactory;
use Refberg\SyliusAutopayPlugin\Notification\ConfirmationFactoryInterface;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParser;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusAutopayPlugin\Order\AutopayTransactionValidator;
use Refberg\SyliusAutopayPlugin\Order\AutopayTransactionValidatorInterface;
use Refberg\SyliusAutopayPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusAutopayPlugin\Order\ExtOrderIdGeneratorInterface;
use Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactory;
use Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusAutopayPlugin\OrderPay\CaptureHttpResponseProvider;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLocker;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplier;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizer;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusAutopayPlugin\Payment\RefundSynchronizer;
use Refberg\SyliusAutopayPlugin\Payment\RefundSynchronizerInterface;
use Refberg\SyliusAutopayPlugin\Provider\AutopayNotifyPaymentProvider;
use Refberg\SyliusAutopayPlugin\Provider\NotifyResponseProvider;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifierInterface;
use Sylius\Bundle\PaymentBundle\CommandProvider\ActionsCommandProvider;
use Sylius\Bundle\PaymentBundle\Provider\ActionsHttpResponseProvider;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters->set('refberg_autopay.http_timeout', 15.0);
    // Seconds a started transaction can be paid (Autopay "ValidityTime"); 0 leaves the Autopay default.
    $parameters->set('refberg_autopay.transaction_validity', 0);

    $services = $container->services();
    $services->defaults()->private();

    // Configuration and Autopay API
    $services->set(CredentialsResolverInterface::class, CredentialsResolver::class);
    $services->set(SignatureVerifierInterface::class, SignatureVerifier::class);

    // Override "refberg_autopay.http_client" to route Autopay traffic through a custom client (proxy, mock).
    $services->alias('refberg_autopay.http_client', 'http_client');

    $services->set(AutopayClientInterface::class, AutopayClient::class)
        ->args([
            service('refberg_autopay.http_client'),
            service(SignatureVerifierInterface::class),
            service('logger')->ignoreOnInvalid(),
            param('refberg_autopay.http_timeout'),
        ])
        ->tag('monolog.logger', ['channel' => 'autopay']);

    $services->set(NotificationParserInterface::class, NotificationParser::class)->args([service(SignatureVerifierInterface::class)]);
    $services->set(ConfirmationFactoryInterface::class, ConfirmationFactory::class)->args([service(SignatureVerifierInterface::class)]);

    // Transactions and payment state
    $services->set(ExtOrderIdGeneratorInterface::class, ExtOrderIdGenerator::class);
    $services->set(AutopayTransactionValidatorInterface::class, AutopayTransactionValidator::class);
    $services->set(TransactionRequestFactoryInterface::class, TransactionRequestFactory::class)
        ->args([null, param('refberg_autopay.transaction_validity')]);

    $services->set(PaymentLockerInterface::class, PaymentLocker::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set(PaymentStateApplierInterface::class, PaymentStateApplier::class)
        ->args([service('sylius_abstraction.state_machine'), service('logger')->ignoreOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'autopay']);

    $services->set(PaymentSynchronizerInterface::class, PaymentSynchronizer::class)
        ->args([
            service(AutopayClientInterface::class),
            service(AutopayTransactionValidatorInterface::class),
            service(PaymentStateApplierInterface::class),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'autopay']);

    $services->set(RefundSynchronizerInterface::class, RefundSynchronizer::class)
        ->args([service(AutopayClientInterface::class), service('logger')->ignoreOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'autopay']);

    $services->set(RefreshRefundsCommand::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(CredentialsResolverInterface::class),
            service(PaymentLockerInterface::class),
            service(RefundSynchronizerInterface::class),
            param('sylius.model.payment.class'),
        ])
        ->tag('console.command');

    // Admin: gateway configuration form
    $services->set(AutopayGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => AutopayGateway::FACTORY_NAME, 'label' => 'refberg_autopay.gateway_factory.refberg_autopay'])
        ->tag('form.type');

    // Payment request commands
    $services->set('refberg_autopay.command_provider', ActionsCommandProvider::class)
        ->args([tagged_locator('refberg_autopay.command_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => AutopayGateway::FACTORY_NAME]);

    $commands = [
        'capture' => [CapturePaymentRequest::class, [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE]],
        'status' => [StatusPaymentRequest::class, [PaymentRequestInterface::ACTION_STATUS]],
        'notify' => [NotifyPaymentRequest::class, [PaymentRequestInterface::ACTION_NOTIFY]],
        'refund' => [RefundPaymentRequest::class, [PaymentRequestInterface::ACTION_REFUND]],
        'cancel' => [CancelPaymentRequest::class, [PaymentRequestInterface::ACTION_CANCEL]],
    ];
    foreach ($commands as $name => [$commandClass, $actions]) {
        $definition = $services->set('refberg_autopay.command_provider.' . $name, PaymentRequestCommandProvider::class)
            ->args([$commandClass]);
        foreach ($actions as $action) {
            $definition->tag('refberg_autopay.command_provider', ['action' => $action]);
        }
    }

    $handlerDefaults = [
        service('sylius.provider.payment_request'),
        service(CredentialsResolverInterface::class),
    ];
    $handlerArguments = [
        CapturePaymentRequestHandler::class => [
            service(AutopayClientInterface::class),
            service(TransactionRequestFactoryInterface::class),
            service(ExtOrderIdGeneratorInterface::class),
        ],
        StatusPaymentRequestHandler::class => [service(PaymentSynchronizerInterface::class)],
        NotifyPaymentRequestHandler::class => [service(NotificationParserInterface::class), service(PaymentSynchronizerInterface::class)],
        RefundPaymentRequestHandler::class => [service(AutopayClientInterface::class)],
        CancelPaymentRequestHandler::class => [service(AutopayClientInterface::class)],
    ];
    foreach ($handlerArguments as $handler => $arguments) {
        $services->set($handler)
            ->args([
                ...$handlerDefaults,
                ...$arguments,
                service(PaymentLockerInterface::class),
                service('sylius_abstraction.state_machine'),
                service('logger')->ignoreOnInvalid(),
            ])
            ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
            ->tag('monolog.logger', ['channel' => 'autopay']);
    }

    // Notifications (ITN and ISTN)
    $services->set(AutopayNotifyPaymentProvider::class)
        ->args([
            service(CredentialsResolverInterface::class),
            service(NotificationParserInterface::class),
            service(ExtOrderIdGeneratorInterface::class),
            service('sylius.repository.payment'),
            service(ConfirmationFactoryInterface::class),
        ])
        ->tag('sylius.payment_request.payment_notify_provider');

    $services->set(AcknowledgedNotificationListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => 64]);

    $services->set(NotifyResponseProvider::class)
        ->decorate('sylius.provider.payment_request.notify_response')
        ->args([
            service('.inner'),
            service(CredentialsResolverInterface::class),
            service(NotificationParserInterface::class),
            service(ConfirmationFactoryInterface::class),
        ]);

    // Shop and API: HTTP responses
    $services->set('refberg_autopay.http_response_provider', ActionsHttpResponseProvider::class)
        ->args([tagged_locator('refberg_autopay.http_response_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.provider.http_response', ['gateway_factory' => AutopayGateway::FACTORY_NAME]);

    $services->set(CaptureHttpResponseProvider::class)
        ->tag('refberg_autopay.http_response_provider', ['action' => PaymentRequestInterface::ACTION_CAPTURE])
        ->tag('refberg_autopay.http_response_provider', ['action' => PaymentRequestInterface::ACTION_AUTHORIZE]);

    // Refunds and cancellations from the admin panel
    $transitionActions = [
        'refund' => PaymentRequestInterface::ACTION_REFUND,
        'cancel' => PaymentRequestInterface::ACTION_CANCEL,
    ];
    foreach ($transitionActions as $transition => $action) {
        $services->set('refberg_autopay.listener.payment_transition.' . $transition, PaymentTransitionListener::class)
            ->args([
                service(CredentialsResolverInterface::class),
                service('sylius.factory.payment_request'),
                service('sylius.repository.payment_request'),
                service('sylius.announcer.payment_request'),
                $action,
            ])
            ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.completed.' . $transition]);
    }
};
