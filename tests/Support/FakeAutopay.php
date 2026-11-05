<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Support;

use LogicException;
use OutOfBoundsException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_search;
use function array_shift;
use function array_values;
use function base64_encode;
use function count;
use function explode;
use function hash;
use function htmlspecialchars;
use function http_build_query;
use function implode;
use function intdiv;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function preg_match;
use function round;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;
use const ENT_XML1;
use const JSON_THROW_ON_ERROR;

/**
 * In-memory imitation of the Autopay test environment (testpay.autopay.eu), used as MockHttpClient callback.
 *
 * It checks every hash and the order of the transaction fields, so a client that signs differently fails here.
 * Behaviour of the real API that is not confirmed yet is marked with the assumption IDs of docs/autopay.md.
 * Any request to a host other than the Autopay test environment throws, so tests can never reach production.
 */
final class FakeAutopay
{
    public const HOST = 'testpay.autopay.eu';

    public const SERVICE_ID = '100001';

    public const SHARED_KEY = 'test-shared-key';

    /** Order of the transaction fields in the hash, as numbered in the Autopay documentation (subset used by the plugin). */
    public const TRANSACTION_FIELDS = ['ServiceID', 'OrderID', 'Amount', 'Description', 'GatewayID', 'Currency', 'CustomerEmail', 'CustomerIP', 'Title', 'ValidityTime', 'ReturnURL'];

    /** @var array<string, array{fields: array<string, string>, attempts: list<array<string, string>>}> */
    private array $transactions = [];

    /** @var array<string, array<string, string>> */
    private array $refunds = [];

    /** @var array<string, int> refunded amount per remoteID, also by "panel" refunds */
    private array $refunded = [];

    /** @var list<array{method: string, path: string, body: string, headers: array<string, string>}> */
    private array $requests = [];

    /** @var list<array{int, string}> */
    private array $queuedFailures = [];

    private int $counter = 0;

    /** @var list<string> */
    private array $cancelRequests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (self::HOST !== $host) {
            throw new LogicException(sprintf('Tests must not call "%s". Only the Autopay test environment fake is available.', $host));
        }

        $path = $parts['path'] ?? '/';
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $headers = [];
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (is_string($header) && str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];

        if ([] !== $this->queuedFailures) {
            [$status, $name] = array_shift($this->queuedFailures);

            return self::error($name, $status);
        }

        if ('POST' !== $method) {
            return new MockResponse('ERROR', ['http_code' => 405]);
        }

        return match ($path) {
            '/payment' => $this->startTransaction(self::form($body), $headers['bmheader'] ?? ''),
            '/webapi/transactionStatus' => $this->transactionStatus(self::form($body)),
            '/webapi/transactionCancel' => $this->cancel(self::form($body)),
            '/settlementapi/transactionRefund' => $this->refund(self::form($body)),
            '/settlementapi/outDetails/v2' => $this->refundStatus($body),
            default => new MockResponse('ERROR', ['http_code' => 404]),
        };
    }

    public static function sign(string ...$values): string
    {
        $values = array_values(array_filter($values, static fn (string $value): bool => '' !== $value));
        $values[] = self::SHARED_KEY;

        return hash('sha256', implode('|', $values));
    }

    /**
     * Sets the status of the latest attempt of a transaction.
     */
    public function setStatus(string $orderId, string $status): void
    {
        $attempts = $this->transaction($orderId)['attempts'];
        $attempts[count($attempts) - 1]['paymentStatus'] = $status;
        $this->transactions[$orderId]['attempts'] = $attempts;
    }

    /**
     * The customer makes another attempt for the same OrderID on the Autopay page.
     */
    public function addAttempt(string $orderId, string $status): string
    {
        $transaction = $this->transaction($orderId);
        $remoteId = sprintf('FAKE%04dR', ++$this->counter);
        $this->transactions[$orderId]['attempts'][] = array_merge($transaction['attempts'][0], ['remoteID' => $remoteId, 'paymentStatus' => $status]);

        return $remoteId;
    }

    /**
     * Changes what Autopay reports for the transaction, to simulate tampering or a mismatch.
     */
    public function overrideTransaction(string $orderId, string $field, string $value): void
    {
        $attempts = [];
        foreach ($this->transaction($orderId)['attempts'] as $attempt) {
            $attempt[$field] = $value;
            $attempts[] = $attempt;
        }
        $this->transactions[$orderId]['attempts'] = $attempts;
    }

    public function failNextRequest(int $httpStatus, string $errorName = 'SYSTEM_ERROR'): void
    {
        $this->queuedFailures[] = [$httpStatus, $errorName];
    }

    /**
     * A refund the merchant made in the Autopay panel: Autopay sends no notification about it.
     */
    public function panelRefund(string $orderId, int $amount): string
    {
        $remoteId = $this->transaction($orderId)['attempts'][0]['remoteID'];
        $this->refunded[$remoteId] = ($this->refunded[$remoteId] ?? 0) + $amount;

        return sprintf('ZWTR_PANEL%04d', ++$this->counter);
    }

    /**
     * ISTN request body about a refund of the transaction (sent only when Autopay enabled ISTN for the service).
     * $orderOutId is the MessageID of a refund requested by Sylius, or an Autopay ID for a panel refund.
     */
    public function istnBody(string $orderId, string $remoteOutId, ?string $orderOutId, int $amount, string $status, bool $isRefund = true, string $sharedKey = self::SHARED_KEY, string $currency = 'PLN'): string
    {
        $transaction = [
            'isRefund' => $isRefund ? 'true' : 'false',
            'orderID' => $orderId,
            'orderOutID' => $orderOutId ?? 'AP' . $remoteOutId,
            'remoteID' => $this->transaction($orderId)['attempts'][0]['remoteID'],
            'remoteOutID' => $remoteOutId,
            'amount' => sprintf('%d.%02d', intdiv($amount, 100), $amount % 100),
            'currency' => $currency,
            'transferDate' => '20260102120000',
            'transferStatus' => $status,
            'title' => 'Zwrot',
        ];
        $hash = self::sign(self::SERVICE_ID, ...array_values($transaction));
        if (self::SHARED_KEY !== $sharedKey) {
            $hash = hash('sha256', implode('|', [self::SERVICE_ID, ...array_values($transaction), $sharedKey]));
        }

        return http_build_query(['transactions' => base64_encode(self::transactionListXml(self::SERVICE_ID, [$transaction], $hash))]);
    }

    /**
     * Autopay no longer knows the refund, so reading its status fails.
     */
    public function forgetRefund(string $messageId): void
    {
        unset($this->refunds[$messageId]);
    }

    public function setRefundStatus(string $messageId, string $status): void
    {
        if (!isset($this->refunds[$messageId])) {
            throw new OutOfBoundsException(sprintf('Unknown fake Autopay refund "%s".', $messageId));
        }

        $this->refunds[$messageId]['status'] = $status;
    }

    /**
     * @return array{fields: array<string, string>, attempts: list<array<string, string>>}
     */
    public function transaction(string $orderId): array
    {
        return $this->transactions[$orderId] ?? throw new OutOfBoundsException(sprintf('Unknown fake Autopay transaction "%s".', $orderId));
    }

    public function lastOrderId(): string
    {
        $last = array_key_last($this->transactions);

        return $last ?? throw new LogicException('No Autopay transaction has been started.');
    }

    /**
     * @return list<string>
     */
    public function orderIds(): array
    {
        return array_map(strval(...), array_keys($this->transactions));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function refunds(): array
    {
        return $this->refunds;
    }

    /**
     * @return list<array{method: string, path: string, body: string, headers: array<string, string>}>
     */
    public function requests(?string $method = null, ?string $pathPattern = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => (null === $method || $request['method'] === $method) &&
                (null === $pathPattern || 1 === preg_match($pathPattern, $request['path'])),
        ));
    }

    /**
     * ITN request body as Autopay sends it, with the current transaction data (latest attempt).
     */
    public function itnBody(string $orderId, ?string $reportedStatus = null, string $sharedKey = self::SHARED_KEY, string $serviceId = self::SERVICE_ID): string
    {
        $attempts = $this->transaction($orderId)['attempts'];
        $attempt = $attempts[count($attempts) - 1] ?? throw new LogicException('Transaction without attempts.');

        return self::itn([
            'orderID' => $orderId,
            'remoteID' => $attempt['remoteID'],
            'amount' => $attempt['amount'],
            'currency' => $attempt['currency'],
            'gatewayID' => '106',
            'paymentDate' => '20260101120000',
            'paymentStatus' => $reportedStatus ?? $attempt['paymentStatus'],
            'paymentStatusDetails' => 'AUTHORIZED',
        ], $sharedKey, $serviceId);
    }

    /**
     * @param array<string, string> $transaction
     */
    public static function itn(array $transaction, string $sharedKey = self::SHARED_KEY, string $serviceId = self::SERVICE_ID): string
    {
        $values = array_merge([$serviceId], array_values($transaction), [$sharedKey]);
        $hash = hash('sha256', implode('|', array_filter($values, static fn (string $value): bool => '' !== $value)));

        return http_build_query(['transactions' => base64_encode(self::transactionListXml($serviceId, [$transaction], $hash))]);
    }

    /**
     * @param list<array<string, string>> $transactions
     */
    public static function transactionListXml(string $serviceId, array $transactions, ?string $hash): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<transactionList><serviceID>' . $serviceId . '</serviceID><transactions>';
        foreach ($transactions as $transaction) {
            $xml .= '<transaction>';
            foreach ($transaction as $name => $value) {
                $xml .= sprintf('<%1$s>%2$s</%1$s>', $name, htmlspecialchars($value, ENT_XML1));
            }
            $xml .= '</transaction>';
        }

        return $xml . '</transactions>' . (null !== $hash ? '<hash>' . $hash . '</hash>' : '') . '</transactionList>';
    }

    /**
     * @param array<string, string> $form
     */
    private function startTransaction(array $form, string $bmHeader): MockResponse
    {
        if ('pay-bm-continue-transaction-url' !== $bmHeader) {
            return new MockResponse('<html>paywall</html>', ['http_code' => 200]);
        }

        $error = $this->checkHash($form);
        if ($error instanceof MockResponse) {
            return $error;
        }

        $position = -1;
        foreach (array_keys($form) as $field) {
            if ('Hash' === $field) {
                continue;
            }
            $index = array_search($field, self::TRANSACTION_FIELDS, true);
            if (false === $index || $index <= $position) {
                return self::error('INVALID_PARAMS', 200, sprintf('Field %s is unknown or out of order.', $field));
            }
            $position = $index;
        }

        foreach (['OrderID', 'Amount'] as $field) {
            if (!isset($form[$field])) {
                return self::error('INVALID_PARAMS', 200, 'Missing ' . $field);
            }
        }

        $orderId = $form['OrderID'];
        if (1 !== preg_match('/^[A-Za-z0-9_-]{1,32}$/', $orderId) || 1 !== preg_match('/^\d+\.\d{2}$/', $form['Amount'])) {
            return self::error('INVALID_PARAMS', 200, 'Invalid OrderID or Amount.');
        }
        // Autopay does not check OrderID uniqueness by default; the fake does, so tests catch a reused OrderID.
        if (isset($this->transactions[$orderId])) {
            return self::error('ORDER_ID_NOT_UNIQUE', 200);
        }

        $remoteId = sprintf('FAKE%04dR', ++$this->counter);
        $this->transactions[$orderId] = [
            'fields' => $form,
            'attempts' => [[
                'orderID' => $orderId,
                'remoteID' => $remoteId,
                'amount' => $form['Amount'],
                'currency' => $form['Currency'] ?? 'PLN',
                'paymentStatus' => 'PENDING',
            ]],
        ];

        $redirectUrl = 'https://' . self::HOST . '/payment/continue/' . $remoteId . '/ABCDEFGH';

        return self::xml(sprintf(
            '<transaction><status>PENDING</status><redirecturl>%s</redirecturl><orderID>%s</orderID><remoteID>%s</remoteID><hash>%s</hash></transaction>',
            $redirectUrl,
            $orderId,
            $remoteId,
            self::sign('PENDING', $redirectUrl, $orderId, $remoteId),
        ));
    }

    /**
     * @param array<string, string> $form
     */
    private function transactionStatus(array $form): MockResponse
    {
        $error = $this->checkHash($form);
        if ($error instanceof MockResponse) {
            return $error;
        }

        $orderId = $form['OrderID'] ?? '';
        if (!isset($this->transactions[$orderId])) {
            return self::error('TRANSACTION_NOT_FOUND', 200);
        }

        return self::xml(self::signedTransactionList($this->transactions[$orderId]['attempts']));
    }

    /**
     * @param array<string, string> $form
     */
    private function refund(array $form): MockResponse
    {
        $error = $this->checkHash($form);
        if ($error instanceof MockResponse) {
            return $error;
        }

        $messageId = $form['MessageID'] ?? '';
        $remoteId = $form['RemoteID'] ?? '';
        if (1 !== preg_match('/^[0-9A-Za-z]{32}$/', $messageId)) {
            return self::error('INVALID_PARAMS', 200, 'MessageID must have 32 characters.');
        }

        // The same MessageID never refunds twice; a refund above the balance is refused.
        if (!isset($this->refunds[$messageId])) {
            $attempt = $this->attemptByRemoteId($remoteId);
            if (null === $attempt || 'SUCCESS' !== $attempt['paymentStatus']) {
                return self::error('TRANSACTION_NOT_FOUND', 200);
            }

            $amount = (int) round((float) ($form['Amount'] ?? $attempt['amount']) * 100);
            $balance = (int) round((float) $attempt['amount'] * 100) - ($this->refunded[$remoteId] ?? 0);
            if ($amount > $balance) {
                return self::error('AMOUNT_EXCEEDS_BALANCE', 200);
            }

            $this->refunded[$remoteId] = ($this->refunded[$remoteId] ?? 0) + $amount;
            $this->refunds[$messageId] = [
                'remoteID' => $remoteId,
                'amount' => $form['Amount'] ?? $attempt['amount'],
                'currency' => $form['Currency'] ?? $attempt['currency'],
                'status' => 'NEW',
            ];
        }

        return self::xml(sprintf(
            '<transactionRefund><serviceID>%s</serviceID><messageID>%s</messageID><hash>%s</hash></transactionRefund>',
            self::SERVICE_ID,
            $messageId,
            self::sign(self::SERVICE_ID, $messageId),
        ));
    }

    private function refundStatus(string $body): MockResponse
    {
        /** @var array{serviceId?: int, messageId?: string, method?: string, hash?: string} $data */
        $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        $hash = $data['hash'] ?? '';
        if ($hash !== self::sign((string) ($data['serviceId'] ?? ''), $data['messageId'] ?? '', $data['method'] ?? '')) {
            return new MockResponse(json_encode(['result' => 'ERROR', 'errorStatus' => 'INVALID_HASH'], JSON_THROW_ON_ERROR), ['http_code' => 400]);
        }

        $data['messageId'] ??= '';
        $refund = $this->refunds[$data['messageId']] ?? null;
        if (null === $refund) {
            return new MockResponse(json_encode(['result' => 'ERROR', 'errorStatus' => 'NOT_FOUND'], JSON_THROW_ON_ERROR), ['http_code' => 404]);
        }

        $remoteOutId = 'DONE' === $refund['status'] ? 'ZWTR_' . $data['messageId'] : null;

        return new MockResponse(json_encode([
            'serviceId' => (int) self::SERVICE_ID,
            'balancePointId' => null,
            'messageId' => $data['messageId'],
            'status' => $refund['status'],
            'remoteOutId' => $remoteOutId,
            'hash' => self::sign(self::SERVICE_ID, $data['messageId'], $refund['status'], (string) $remoteOutId),
            'errorStatus' => null,
            'errorStatusCode' => null,
            'errorMessages' => null,
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * @param array<string, string> $form
     */
    private function cancel(array $form): MockResponse
    {
        $error = $this->checkHash($form);
        if ($error instanceof MockResponse) {
            return $error;
        }

        $messageId = $form['MessageID'] ?? '';
        $orderId = $form['OrderID'] ?? '';
        $this->cancelRequests[] = $orderId;
        if (!isset($this->transactions[$orderId])) {
            return self::xml('<transaction><confirmation>NOTCONFIRMED</confirmation><reason>TRANSACTION_NOT_FOUND</reason></transaction>');
        }

        $cancelled = 0;
        $other = 0;
        $attempts = [];
        foreach ($this->transactions[$orderId]['attempts'] as $attempt) {
            if ('PENDING' === $attempt['paymentStatus']) {
                $attempt['paymentStatus'] = 'FAILURE';
                ++$cancelled;
            } elseif ('SUCCESS' === $attempt['paymentStatus']) {
                ++$other;
            }
            $attempts[] = $attempt;
        }
        $this->transactions[$orderId]['attempts'] = $attempts;

        if (0 === $cancelled) {
            return self::xml('<transaction><confirmation>NOTCONFIRMED</confirmation><reason>INCORRECT_PAYMENT_STATUS</reason></transaction>');
        }

        $reason = 0 === $other ? 'CANCELED_FULLY' : 'CANCELED_PARTIALLY';

        return self::xml(sprintf(
            '<transaction><serviceID>%s</serviceID><messageID>%s</messageID><confirmation>CONFIRMED</confirmation><reason>%s</reason><hash>%s</hash></transaction>',
            self::SERVICE_ID,
            $messageId,
            $reason,
            self::sign(self::SERVICE_ID, $messageId, 'CONFIRMED', $reason),
        ));
    }

    /**
     * transactionStatus answer: the hash covers serviceID and the fields of every attempt in order.
     *
     * @param list<array<string, string>> $attempts
     */
    public static function signedTransactionList(array $attempts): string
    {
        $values = [self::SERVICE_ID];
        foreach ($attempts as $attempt) {
            foreach ($attempt as $value) {
                $values[] = $value;
            }
        }

        return self::transactionListXml(self::SERVICE_ID, $attempts, self::sign(...$values));
    }

    /**
     * @return list<string> OrderIDs passed to transactionCancel
     */
    public function cancelRequests(): array
    {
        return $this->cancelRequests;
    }

    /**
     * @param array<string, string> $form
     */
    private function checkHash(array $form): ?MockResponse
    {
        $hash = $form['Hash'] ?? '';
        unset($form['Hash']);
        if (($form['ServiceID'] ?? null) !== self::SERVICE_ID) {
            // A1
            return self::error('INVALID_SERVICE_ID', 200);
        }

        return $hash === self::sign(...array_values($form)) ? null : self::error('INVALID_HASH', 200);
    }

    /**
     * @return array<string, string>|null
     */
    private function attemptByRemoteId(string $remoteId): ?array
    {
        foreach ($this->transactions as $transaction) {
            foreach ($transaction['attempts'] as $attempt) {
                if ($attempt['remoteID'] === $remoteId) {
                    return $attempt;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function form(string $body): array
    {
        parse_str($body, $form);
        $result = [];
        foreach ($form as $key => $value) {
            if (is_string($key)) {
                $result[$key] = is_string($value) ? $value : '';
            }
        }

        return $result;
    }

    private static function xml(string $body): MockResponse
    {
        if (!str_starts_with($body, '<?xml')) {
            $body = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $body;
        }

        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/xml'],
        ]);
    }

    private static function error(string $name, int $httpStatus, string $description = ''): MockResponse
    {
        if ('' === $name) {
            return new MockResponse('ERROR', ['http_code' => $httpStatus]);
        }

        return new MockResponse(sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<error><statusCode>%d</statusCode><name>%s</name><description>%s</description></error>',
            count([$name]),
            $name,
            htmlspecialchars($description, ENT_XML1),
        ), ['http_code' => $httpStatus, 'response_headers' => ['Content-Type' => 'application/xml']]);
    }
}
