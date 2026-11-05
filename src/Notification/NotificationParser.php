<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Notification;

use Refberg\SyliusAutopayPlugin\Api\Xml;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifierInterface;
use SimpleXMLElement;
use function base64_decode;
use function count;
use function is_array;
use function is_string;
use function parse_str;

/**
 * Reads an Autopay ITN: POST field "transactions" with a base64-encoded <transactionList> document.
 *
 * The document hash covers the values of all its elements in document order (except the hash itself)
 * followed by the shared key. Only ITNs with exactly one transaction are accepted.
 */
final readonly class NotificationParser implements NotificationParserInterface
{
    public const FIELD = 'transactions';

    public function __construct(private SignatureVerifierInterface $signatureVerifier)
    {
    }

    public function parse(string $rawBody, AutopayCredentials $credentials): ?Notification
    {
        parse_str($rawBody, $fields);
        $encoded = $fields[self::FIELD] ?? null;
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
        $xml = is_string($decoded) ? Xml::parse($decoded) : null;
        if (!$xml instanceof SimpleXMLElement || 'transactionList' !== $xml->getName()) {
            return null;
        }

        if (!$this->signatureVerifier->verify(Xml::signedValues($xml), Xml::text($xml, 'hash'), $credentials)) {
            return null;
        }

        $serviceId = Xml::text($xml, 'serviceID');
        $transactions = $xml->transactions->transaction;
        if ($serviceId !== $credentials->serviceId || 1 !== count($transactions)) {
            return null;
        }

        $transaction = $transactions[0];
        $orderId = null !== $transaction ? Xml::text($transaction, 'orderID') : null;
        if (null === $transaction || null === $orderId) {
            return null;
        }

        return new Notification(
            type: Notification::TYPE_TRANSACTION,
            serviceId: $serviceId,
            orderId: $orderId,
            remoteId: Xml::text($transaction, 'remoteID'),
            status: Xml::text($transaction, 'paymentStatus'),
        );
    }

    /**
     * Parses an ITN stored in PaymentRequest::$payload by Sylius' NotifyPayloadProcessor.
     */
    public function parseStoredPayload(mixed $payload, AutopayCredentials $credentials): ?Notification
    {
        $httpRequest = is_array($payload) ? ($payload['http_request'] ?? null) : null;
        $content = is_array($httpRequest) ? ($httpRequest['content'] ?? null) : null;

        return is_string($content) ? $this->parse($content, $credentials) : null;
    }
}
