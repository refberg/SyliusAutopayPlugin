<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayTransportException;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Api\Model\TransactionCancelled;
use Refberg\SyliusAutopayPlugin\Api\Model\TransactionStarted;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifierInterface;
use SimpleXMLElement;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function array_values;
use function in_array;
use function is_array;
use function json_decode;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * Minimal client of the Autopay online payments API.
 *
 * Requests are signed with the shared key (there is no token). Logs contain only HTTP statuses,
 * Autopay error names and identifiers; the shared key, hashes and request bodies are never logged
 * nor put into exception messages.
 */
final readonly class AutopayClient implements AutopayClientInterface
{
    private const HEADER = 'BmHeader';

    private const HEADER_CONTINUE = 'pay-bm-continue-transaction-url';

    private const HEADER_API = 'pay-bm';

    private const REFUND_METHOD = 'TRANSACTION_REFUND';

    public function __construct(
        private HttpClientInterface $httpClient,
        private SignatureVerifierInterface $signatureVerifier,
        private LoggerInterface $logger = new NullLogger(),
        private float $timeout = 15.0,
    ) {
    }

    public function startTransaction(AutopayCredentials $credentials, array $parameters): TransactionStarted
    {
        $orderId = $parameters['OrderID'] ?? '';
        [$statusCode, $xml] = $this->postForm($credentials, '/payment', ['ServiceID' => $credentials->serviceId] + $parameters, self::HEADER_CONTINUE);
        $this->assertNoError('Starting Autopay transaction failed', $statusCode, $xml);
        if ($xml instanceof SimpleXMLElement && 'NOTCONFIRMED' === Xml::text($xml, 'confirmation')) {
            throw $this->rejected('Autopay refused the transaction', $statusCode, Xml::text($xml, 'reason'));
        }

        if (
            !$xml instanceof SimpleXMLElement ||
            !$this->signatureVerifier->verify(Xml::signedValues($xml), Xml::text($xml, 'hash'), $credentials)
        ) {
            throw $this->invalidResponse('Autopay transaction response has an invalid hash or body.', $statusCode);
        }

        $remoteId = Xml::text($xml, 'remoteID');
        $redirectUrl = Xml::text($xml, 'redirecturl');
        if (Xml::text($xml, 'orderID') !== $orderId || null === $remoteId || null === $redirectUrl || !str_starts_with($redirectUrl, 'https://')) {
            throw $this->invalidResponse('Autopay transaction response is missing orderID, remoteID or redirecturl.', $statusCode);
        }

        $this->logger->info('Autopay transaction started.', ['order_id' => $orderId, 'remote_id' => $remoteId]);

        return new TransactionStarted($orderId, $remoteId, $redirectUrl, Xml::text($xml, 'status') ?? AutopayTransaction::STATUS_PENDING);
    }

    public function retrieveTransaction(AutopayCredentials $credentials, string $orderId): AutopayTransaction
    {
        [$statusCode, $xml] = $this->postForm($credentials, '/webapi/transactionStatus', [
            'ServiceID' => $credentials->serviceId,
            'OrderID' => $orderId,
        ], self::HEADER_API);
        $this->assertNoError('Retrieving Autopay transaction failed', $statusCode, $xml);
        if (
            !$xml instanceof SimpleXMLElement ||
            !$this->signatureVerifier->verify(Xml::signedValues($xml), Xml::text($xml, 'hash'), $credentials)
        ) {
            throw $this->invalidResponse('Autopay transaction status response has an invalid hash or body.', $statusCode);
        }

        $serviceId = Xml::text($xml, 'serviceID');
        $success = $pending = $failure = null;
        $paidRemoteIds = [];
        $items = $xml->xpath('//transaction');
        foreach (is_array($items) ? $items : [] as $item) {
            $itemOrderId = Xml::text($item, 'orderID') ?? Xml::text($xml, 'orderID');
            if ($itemOrderId !== $orderId) {
                continue;
            }

            $status = Xml::text($item, 'paymentStatus');
            $amount = Amount::parse(Xml::text($item, 'amount'));
            $currency = Xml::text($item, 'currency');
            if (in_array(null, [$status, $amount, $currency], true)) {
                throw $this->invalidResponse('Autopay transaction status response is incomplete.', $statusCode);
            }

            $remoteId = Xml::text($item, 'remoteID');
            if (AutopayTransaction::STATUS_SUCCESS === $status && null !== $remoteId) {
                $paidRemoteIds[] = $remoteId;
            }

            $transaction = new AutopayTransaction($orderId, $remoteId, $status, $amount, $currency, $serviceId);
            match ($status) {
                AutopayTransaction::STATUS_SUCCESS => $success ??= $transaction,
                AutopayTransaction::STATUS_FAILURE => $failure = $transaction,
                default => $pending ??= $transaction,
            };
        }

        if ($success instanceof AutopayTransaction) {
            return new AutopayTransaction($orderId, $success->remoteId, $success->status, $success->amount, $success->currency, $serviceId, $paidRemoteIds);
        }

        return $pending ?? $failure ?? throw new AutopayApiException('Autopay has no transaction with this OrderID.', 404);
    }

    public function cancelTransaction(AutopayCredentials $credentials, string $orderId, string $messageId): TransactionCancelled
    {
        [$statusCode, $xml] = $this->postForm($credentials, '/webapi/transactionCancel', [
            'ServiceID' => $credentials->serviceId,
            'MessageID' => $messageId,
            'OrderID' => $orderId,
        ], self::HEADER_API);
        $this->assertNoError('Cancelling Autopay transaction failed', $statusCode, $xml);

        $confirmation = $xml instanceof SimpleXMLElement ? Xml::text($xml, 'confirmation') : null;
        $reason = $xml instanceof SimpleXMLElement ? Xml::text($xml, 'reason') : null;
        if ('NOTCONFIRMED' === $confirmation) {
            return new TransactionCancelled(false, $reason);
        }

        // The hash is required for CONFIRMED answers only.
        if (
            !$xml instanceof SimpleXMLElement ||
            'CONFIRMED' !== $confirmation ||
            Xml::text($xml, 'messageID') !== $messageId ||
            !$this->signatureVerifier->verify(Xml::signedValues($xml), Xml::text($xml, 'hash'), $credentials)
        ) {
            throw $this->invalidResponse('Autopay cancellation response has an invalid hash or body.', $statusCode);
        }

        $this->logger->info('Autopay transaction cancelled.', ['order_id' => $orderId, 'reason' => $reason]);

        return new TransactionCancelled(true, $reason);
    }

    public function createRefund(AutopayCredentials $credentials, string $remoteId, int $amount, string $currency, string $messageId): RefundCreated
    {
        [$statusCode, $xml] = $this->postForm($credentials, '/settlementapi/transactionRefund', [
            'ServiceID' => $credentials->serviceId,
            'MessageID' => $messageId,
            'RemoteID' => $remoteId,
            'Amount' => Amount::format($amount),
            'Currency' => $currency,
        ], self::HEADER_API);
        $this->assertNoError('Creating Autopay refund failed', $statusCode, $xml);

        if (
            !$xml instanceof SimpleXMLElement ||
            Xml::text($xml, 'messageID') !== $messageId ||
            !$this->signatureVerifier->verify([(string) Xml::text($xml, 'serviceID'), $messageId], Xml::text($xml, 'hash'), $credentials)
        ) {
            throw $this->invalidResponse('Autopay refund response has an invalid hash or body.', $statusCode);
        }

        $this->logger->info('Autopay refund requested.', ['remote_id' => $remoteId, 'message_id' => $messageId]);

        return new RefundCreated($messageId);
    }

    public function retrieveRefundStatus(AutopayCredentials $credentials, string $messageId): string
    {
        $body = [
            'serviceId' => (int) $credentials->serviceId,
            'messageId' => $messageId,
            'method' => self::REFUND_METHOD,
            'hash' => $this->signatureVerifier->sign([$credentials->serviceId, $messageId, self::REFUND_METHOD], $credentials),
        ];

        $response = $this->send('POST', $credentials->environment->baseUrl() . '/settlementapi/outDetails/v2', [
            'headers' => [self::HEADER => self::HEADER_API, 'Accept' => 'application/json'],
            'json' => $body,
        ]);
        [$statusCode, $content] = $this->read($response);

        $data = json_decode($content, true);
        $data = is_array($data) ? $data : [];
        $status = Json::string($data, 'status');
        $error = Json::string($data, 'errorStatus');
        if (200 !== $statusCode || null !== $error || null === $status) {
            $this->logger->error('Reading Autopay refund status failed.', ['http_status' => $statusCode, 'message_id' => $messageId, 'autopay_error' => $error]);

            throw new AutopayApiException(sprintf('Reading Autopay refund status failed (HTTP %d%s).', $statusCode, null !== $error ? ', ' . $error : ''), $statusCode, $error);
        }

        $hashValues = [(string) Json::string($data, 'serviceId'), $messageId, $status, (string) Json::string($data, 'remoteOutId')];
        if (Json::string($data, 'messageId') !== $messageId || !$this->signatureVerifier->verify($hashValues, Json::string($data, 'hash'), $credentials)) {
            throw $this->invalidResponse('Autopay refund status response has an invalid hash or body.', $statusCode);
        }

        return match (true) {
            RefundCreated::STATUS_DONE === $status => RefundCreated::STATUS_DONE,
            str_starts_with($status, 'CANCELED') => RefundCreated::STATUS_CANCELED,
            default => RefundCreated::STATUS_PENDING,
        };
    }

    /**
     * @param array<string, string> $fields ordered fields without Hash
     *
     * @return array{int, SimpleXMLElement|null}
     */
    private function postForm(AutopayCredentials $credentials, string $path, array $fields, string $header): array
    {
        $fields['Hash'] = $this->signatureVerifier->sign(array_values($fields), $credentials);

        $response = $this->send('POST', $credentials->environment->baseUrl() . $path, [
            'headers' => [self::HEADER => $header, 'Accept' => 'application/xml'],
            'body' => $fields,
        ]);
        [$statusCode, $content] = $this->read($response);

        return [$statusCode, Xml::parse($content)];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options): ResponseInterface
    {
        try {
            return $this->httpClient->request($method, $url, $options + [
                'max_redirects' => 0,
                'timeout' => $this->timeout,
            ]);
        } catch (TransportExceptionInterface) {
            throw new AutopayTransportException('Could not reach Autopay API.');
        }
    }

    /**
     * @return array{int, string}
     */
    private function read(ResponseInterface $response): array
    {
        try {
            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface) {
            $this->logger->warning('Autopay API is unreachable.', ['url' => $response->getInfo('url')]);

            throw new AutopayTransportException('Could not reach Autopay API.');
        } catch (HttpClientExceptionInterface) {
            throw new AutopayTransportException('Autopay API returned an unreadable response.');
        }
    }

    private function assertNoError(string $message, int $statusCode, ?SimpleXMLElement $xml): void
    {
        $isError = $xml instanceof SimpleXMLElement && 'error' === $xml->getName();
        if (200 === $statusCode && !$isError) {
            return;
        }

        // <error><name/><description/></error>, or <transaction><reason/><description/></transaction> for some HTTP errors.
        $name = $xml instanceof SimpleXMLElement ? Xml::text($xml, 'name') ?? Xml::text($xml, 'reason') : null;
        $description = $xml instanceof SimpleXMLElement ? Xml::text($xml, 'description') : null;

        $this->logger->error($message . '.', ['http_status' => $statusCode, 'autopay_error' => $name]);

        throw new AutopayApiException(
            sprintf(
                '%s (HTTP %d%s%s).',
                $message,
                $statusCode,
                null !== $name ? ', ' . $name : '',
                null !== $description ? ': ' . mb_substr($description, 0, 200) : '',
            ),
            $statusCode,
            $name,
        );
    }

    private function rejected(string $message, int $statusCode, ?string $reason): AutopayApiException
    {
        $this->logger->error($message . '.', ['http_status' => $statusCode, 'autopay_error' => $reason]);

        return new AutopayApiException(sprintf('%s (%s).', $message, $reason ?? 'no reason'), $statusCode, $reason);
    }

    private function invalidResponse(string $message, int $statusCode): AutopayApiException
    {
        $this->logger->error($message, ['http_status' => $statusCode]);

        return new AutopayApiException($message, $statusCode >= 500 ? $statusCode : 502);
    }
}
