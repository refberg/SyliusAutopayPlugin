<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Notification;

use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifierInterface;
use function htmlspecialchars;
use const ENT_QUOTES;
use const ENT_XML1;

final readonly class ConfirmationFactory implements ConfirmationFactoryInterface
{
    public function __construct(private SignatureVerifierInterface $signatureVerifier)
    {
    }

    public function create(Notification $notification, AutopayCredentials $credentials, bool $confirmed): string
    {
        $confirmation = $confirmed ? self::CONFIRMED : self::NOT_CONFIRMED;
        // An ITN is confirmed by orderID, an ISTN by remoteOutID (hash order of the ISTN answer: assumption A2 in docs/autopay.md).
        [$element, $id] = $notification->isTransfer() ? ['remoteOutID', (string) $notification->remoteOutId] : ['orderID', (string) $notification->orderId];
        $hash = $this->signatureVerifier->sign([$credentials->serviceId, $id, $confirmation], $credentials);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<confirmationList>' .
            '<serviceID>' . self::escape($credentials->serviceId) . '</serviceID>' .
            '<transactionsConfirmations><transactionConfirmed>' .
            '<' . $element . '>' . self::escape($id) . '</' . $element . '>' .
            '<confirmation>' . $confirmation . '</confirmation>' .
            '</transactionConfirmed></transactionsConfirmations>' .
            '<hash>' . $hash . '</hash>' .
            '</confirmationList>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
