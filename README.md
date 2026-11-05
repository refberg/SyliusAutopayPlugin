# Sylius Autopay Plugin

[![Build](https://github.com/refberg/SyliusAutopayPlugin/actions/workflows/build.yaml/badge.svg?branch=main)](https://github.com/refberg/SyliusAutopayPlugin/actions/workflows/build.yaml)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](composer.json)
[![Sylius](https://img.shields.io/badge/Sylius-2.2%20%7C%202.3-1abb9c)](https://sylius.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208-000000)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

[Polska wersja](README.pl.md)

Autopay (formerly Blue Media) online payments gateway for Sylius 2, built on Sylius Payment Requests.
It does not use Payum.

## Features

- Autopay payment page (redirect) in the shop checkout and in the Sylius API. The transaction is started
  server-side, so the amount never passes through the browser.
- Payment state is changed only after the transaction is read from the Autopay API and checked against the Sylius
  payment (service ID, OrderID, amount and currency). An ITN (Autopay notification) only triggers that check, and the
  customer's return from Autopay is never treated as proof of payment.
- ITNs and ISTNs are verified twice (hash compared with `hash_equals`) and answered with the signed confirmation Autopay expects.
- Duplicated and out-of-order ITNs do not change the result and never pay an order twice.
- Full refunds from the admin panel. Their status comes with ISTN notifications or is read by a console command;
  refunds made in the Autopay panel are synchronized through ISTN.
- Cancelling a payment in Sylius cancels its open Autopay transactions.
- Credentials are stored encrypted by Sylius and are not written to logs.

Out of scope: payment channel (gateway) selection in the shop, BLIK level 0, cards and recurring payments,
partial refunds started from Sylius.

The plugin relies on Autopay behaviour that is partly not confirmed yet; see
[docs/autopay.md](docs/autopay.md) for the facts, the assumptions (A1–A5) and how to check them.

## Requirements

| Sylius  | PHP     | Symfony                            |
|---------|---------|------------------------------------|
| 2.2.12+ | 8.2–8.4 | 6.4, 7.4                           |
| 2.3.2+  | 8.3–8.5 | 6.4, 7.4, 8.x (8.x needs PHP 8.4+) |

The Sylius shop (`SyliusShopBundle`), the admin panel (`SyliusAdminBundle`) and Twig Hooks are optional, so the plugin
also works in API-only applications.

The `sylius_payment` state machine must use the Symfony Workflow adapter, which is the Sylius default.
Refunds and cancellations are sent to Autopay from listeners of the `workflow.sylius_payment.completed.refund`
and `workflow.sylius_payment.completed.cancel` events. With the winzou adapter for `sylius_payment`
(`winzou_state_machine` in `sylius_state_machine_abstraction.graphs_to_adapters_mapping` or `default_adapter`),
refunds made in Sylius are not sent to Autopay and have to be made in the Autopay panel.

## Installation

1. Install the package:

   ```bash
   composer require refberg/sylius-autopay-plugin
   ```

2. Register the bundle in `config/bundles.php`, if Symfony Flex did not do it:

   ```php
   Refberg\SyliusAutopayPlugin\RefbergSyliusAutopayPlugin::class => ['all' => true],
   ```

3. Make sure the Sylius gateway configuration encryption key exists (Sylius generates it during installation):

   ```bash
   bin/console sylius:payment:generate-key
   ```

4. Applications without the Sylius shop only: import the payment notification route, which Sylius otherwise imports
   together with the shop routes, in `config/routes/sylius_payment.yaml`:

   ```yaml
   sylius_payment_notify:
       resource: "@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml"
   ```

   Without this route Autopay cannot deliver ITNs. In an application with the admin panel, the edit page of an
   Autopay payment method cannot be rendered either, because it shows the ITN address.
   API clients must also send `return_url` in the capture payload (see [API](#api)).

5. Unless Autopay sends ISTNs for your service (see [Configuration](#configuration)), schedule the refund status
   check, for example every hour:

   ```bash
   bin/console refberg:autopay:refresh-refunds
   ```

No database migrations are needed. The plugin keeps its data in `sylius_payment.details`.

## Configuration

In the admin panel, go to **Configuration → Payment methods → Create → Autopay** and fill in:

| Field          | Autopay name                                          |
|----------------|-------------------------------------------------------|
| Environment    | test (`testpay.autopay.eu`) or production (`pay.autopay.eu`) |
| Service ID     | `ServiceID`                                           |
| Shared key     | shared key (klucz współdzielony)                       |
| Hash algorithm | hash function of the service (SHA-256 by default)     |

The shared key is never shown again after saving. Leave the field empty to keep the stored value.

Autopay sends ITNs to the address configured for the service ID, not to an address sent with the transaction.
Give Autopay the address `https://<your-shop>/payment-methods/<PAYMENT_METHOD_CODE>`; the edit page of the payment
method shows it. The domain needs a publicly trusted certificate with the full chain and TLS 1.2 or 1.3.

Ask Autopay to also enable **ISTN** (settlement and refund notifications, with all statuses if possible) and to send
them to the same address. With ISTN the plugin learns the result of refunds right away and sees refunds made in the
Autopay panel. ISTN is off by default.

### Container parameters

| Parameter                             | Default | Meaning                                                                    |
|---------------------------------------|---------|----------------------------------------------------------------------------|
| `refberg_autopay.http_timeout`        | `15.0`  | Idle timeout of requests to Autopay, in seconds.                           |
| `refberg_autopay.transaction_validity`| `0`     | Seconds a started transaction can be paid (`ValidityTime`, at most 31 days); `0` keeps the Autopay default of 6 days. |

```yaml
parameters:
    refberg_autopay.http_timeout: 30.0
    refberg_autopay.transaction_validity: 86400
```

Autopay traffic goes through the `refberg_autopay.http_client` service (an alias of `http_client`). Override it,
for example to use a proxy.

### Creating the payment method outside the admin panel

An Autopay payment method created in fixtures, migrations or code must have `usePayum` set to `false`.
Sylius sets it to `true` by default; the admin form turns it off by itself for gateways without a Payum factory.
With `usePayum` set to `true`, the shop hands the payment over to Payum instead of the plugin, so the pay page
fails, and Sylius does not encrypt the gateway configuration.

Fixture (`sylius_fixtures`):

```yaml
sylius_fixtures:
    suites:
        default:
            fixtures:
                payment_method:
                    options:
                        custom:
                            autopay:
                                code: AUTOPAY
                                name: Autopay
                                gatewayName: autopay
                                gatewayFactory: !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::FACTORY_NAME
                                gatewayConfig:
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_ENVIRONMENT: sandbox
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_SERVICE_ID: '...'
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_SHARED_KEY: '...'
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_HASH_ALGORITHM: sha256
                                usePayum: false
                                enabled: true
                                channels: [FASHION_WEB]
```

Code, with the `sylius.factory.payment_method` and `sylius.repository.payment_method` services:

```php
use Refberg\SyliusAutopayPlugin\AutopayGateway;

$paymentMethod = $paymentMethodFactory->createWithGateway(AutopayGateway::FACTORY_NAME);
$paymentMethod->setCode('AUTOPAY');
$paymentMethod->setName('Autopay');
$paymentMethod->addChannel($channel);

/** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface $gatewayConfig */
$gatewayConfig = $paymentMethod->getGatewayConfig();
$gatewayConfig->setGatewayName('autopay');
$gatewayConfig->setUsePayum(false);
$gatewayConfig->setConfig([
    AutopayGateway::CONFIG_ENVIRONMENT => 'sandbox',
    AutopayGateway::CONFIG_SERVICE_ID => '...',
    AutopayGateway::CONFIG_SHARED_KEY => '...',
    AutopayGateway::CONFIG_HASH_ALGORITHM => 'sha256',
]);

$paymentMethodRepository->add($paymentMethod);
```

## How it works

### Shop

1. The customer places the order and clicks **Pay**. The plugin starts an Autopay transaction in the background
   (with a new `OrderID` for every attempt) and redirects the customer to the Autopay page.
2. After the payment the customer returns to the shop (`/order/after-pay/{hash}`). The plugin reads the transaction
   status from the Autopay API; the parameters of the return URL are ignored. While Autopay is still processing
   the payment, the customer sees the thank-you page and the payment stays unpaid.
3. Autopay sends an ITN to `/payment-methods/{code}`. The payment is completed or failed based on the state read
   from the Autopay API.

Opening the pay page again reuses the transaction in progress instead of starting a new one.

### API

Create a payment request for a placed order:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "AUTOPAY",
    "payload": {"return_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` contains the Autopay page. `return_url` is optional when the shop is installed; without it
the customer returns to the shop's after-pay page. Without the shop, a capture request without `return_url` fails and
Autopay is not called.

When the customer is back, ask for the status:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{"paymentId": 123, "paymentMethodCode": "AUTOPAY", "action": "status"}
```

`responseData.payment_state` holds the Sylius payment state and `responseData.autopay_status` the Autopay status
(`PENDING`, `SUCCESS` or `FAILURE`).

### Payment state rules

| Autopay status of the OrderID | Sylius payment                                                      |
|-------------------------------|---------------------------------------------------------------------|
| `PENDING`                     | unchanged (`new`)                                                   |
| `SUCCESS`                     | `completed`, once                                                   |
| `FAILURE`                     | `failed`, only when no other transaction of this payment is open    |

The customer can make several attempts for one `OrderID` on the Autopay page. The `OrderID` is `SUCCESS` when any
attempt succeeded and `FAILURE` only when all of them failed, so a failed attempt followed by a successful one pays
the order. When more than one attempt of the same `OrderID` was paid, the payment is completed once and gets the
`duplicate_payment` warning.

A completed payment never goes back to an earlier state. If a second transaction of the same payment is paid (for
example after the customer retried in another tab), the Sylius order is not paid twice: the plugin records the
`duplicate_payment` warning in the payment details (`refberg_autopay.warnings`) and logs an error on the `autopay`
channel. Refund that transaction manually in the Autopay panel.

If the customer switches the order to another payment method after the redirect to Autopay, the transaction started
before the switch stays linked to the payment. Its ITN, or the customer's return from Autopay, completes the payment
once the Autopay API confirms it; the plugin sets the payment method back to the Autopay method that was paid, records
the `paid_with_previous_payment_method` warning and logs a warning. Refunds of that payment from Sylius therefore reach
Autopay. A `status` request through the API with the new method checks only transactions of that method's service ID.

ITNs with a missing or invalid hash, or for another service ID, get HTTP 400 and are not stored. ITNs for unknown
transactions get HTTP 404. When the Autopay API cannot be reached, answers with HTTP 5xx or rejects the plugin
credentials, the endpoint answers with HTTP 5xx, so Autopay repeats the ITN. This works only with the synchronous
payment request transport (`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync://`, the Sylius default): with an
asynchronous transport the ITN is confirmed before it is processed and Autopay does not repeat it. Other errors
(for example an amount mismatch) are final: the ITN is confirmed and the payment request keeps the reason.

### Refunds

Click **Refund** on a completed Autopay payment in the admin panel. The plugin requests a refund of the payment
amount less the refunds that are not canceled (also those made in the Autopay panel). `MessageID` is derived from the
payment request ID, so a redelivered request does not refund twice.

Autopay processes refunds asynchronously (up to 30 minutes), and a confirmed refund can still fail. The result is
recorded from the ISTN, when Autopay sends them, or by `bin/console refberg:autopay:refresh-refunds`
(option `--days`, default 30).

Sylius marks the payment as refunded right away. If Autopay rejects the refund, the refund payment request is marked
as failed and an error is logged; if the refund fails later (ISTN `FAILURE` or `CANCELED_*` status), the plugin
records it as canceled and logs an error. In both cases the payment stays `refunded`; refund the money manually in the
Autopay panel. Sylius does not retry a failed request automatically, also when Autopay was unavailable.

### Refunds made in the Autopay panel

With ISTN enabled, a refund of the paid transaction that Sylius did not request is recorded in the payment details as
external. When done refunds cover the whole payment amount, the payment is moved to `refunded` without sending another
refund to Autopay (the refund payment request completes with `covered_by_autopay_panel_refunds`). Partial refunds only
show up in the payment details, because Sylius has no state for them. A refund from Sylius after a partial panel
refund asks for the remaining amount.

Without ISTN the plugin does not see panel refunds: a refund from Sylius then asks for the full amount, which Autopay
refuses when it exceeds what is left; the refund request fails and has to be finished in the panel.

### Cancellation

When an Autopay payment is cancelled in Sylius (for example when the order is cancelled), the plugin cancels every
transaction of that payment that may still be paid (`transactionCancel`). If Autopay reports that a transaction was
already paid (`CANCELED_PARTIALLY`, `INCORRECT_PAYMENT_STATUS`), the cancel payment request is marked as failed and an
error is logged; the later ITN records the `payment_completed_after_failure` warning and the payment is not completed.
Refund it in the Autopay panel. Transactions of another Autopay service (started before the customer switched methods)
cannot be cancelled with the current credentials; they get the `transaction_not_cancelled_at_autopay` warning.

## Limitations

- The `sylius_payment` state machine must use the Symfony Workflow adapter (see [Requirements](#requirements)).
- Retries of ITNs after temporary errors need the `sync://` payment request transport.
- With an asynchronous transport, set `framework.router.default_uri` to the public shop URL, so the return URL is
  generated correctly.
- Refunds made in the Autopay panel are synchronized only with ISTN enabled by Autopay.
- Models in which Autopay adds its commission to the transaction amount are not supported.
- A payment whose method was changed after the redirect to a method outside Autopay and that is then cancelled in Sylius
  is not cancelled at Autopay; check the old transaction in the Autopay panel.

## Security notes

- Secrets are stored in the gateway configuration, which Sylius encrypts.
- Refund and cancel requests act only on a payment that is already refunded or cancelled in Sylius, so they cannot be
  triggered through the shop API.
- The shared key and hashes are never logged or put into payment request data. Exception messages contain only HTTP
  statuses and Autopay error names.
- XML from Autopay is parsed without network access and documents with a DTD are rejected.

## Extending

The services below are registered under their interface. Decorate or replace them by the interface:

| Service ID                                                                 | Responsibility                                 |
|----------------------------------------------------------------------------|------------------------------------------------|
| `Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface`                   | Autopay API calls                              |
| `Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface`          | Credentials from the gateway configuration     |
| `Refberg\SyliusAutopayPlugin\Signature\SignatureVerifierInterface`         | `Hash` calculation and verification            |
| `Refberg\SyliusAutopayPlugin\Notification\NotificationParserInterface`     | ITN parsing                                    |
| `Refberg\SyliusAutopayPlugin\Notification\ConfirmationFactoryInterface`    | ITN confirmation document                      |
| `Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactoryInterface`     | Transaction parameters                         |
| `Refberg\SyliusAutopayPlugin\Order\ExtOrderIdGeneratorInterface`           | `OrderID` values                               |
| `Refberg\SyliusAutopayPlugin\Order\AutopayTransactionValidatorInterface`   | Check of a transaction against the payment     |
| `Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface`               | Payment lock                                   |
| `Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface`         | Payment state changes                          |
| `Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface`         | Reading, checking and applying a transaction   |
| `Refberg\SyliusAutopayPlugin\Payment\RefundSynchronizerInterface`          | Reading refund statuses                        |

`ExtOrderIdGeneratorInterface::extractPaymentId()` must read the payment ID back from every value returned by
`generate()`, because ITNs are matched to payments by it. Classes and traits marked `@internal` are not part of the
public API.

## Development

```bash
composer install
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/composer-dependency-analyser
APP_ENV=test vendor/bin/console cache:warmup   # PHPStan reads the compiled test container
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite=unit
```

Integration tests run the Sylius test application against MySQL 8:

```bash
docker run -d --name autopay-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:8.0
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:schema:create
vendor/bin/phpunit --testsuite=integration
```

Tests never call Autopay. All requests go to an in-memory fake of the Autopay test environment
(`tests/Support/FakeAutopay.php`), which checks every hash and rejects any other host. The test application uses
an encrypted gateway configuration with a committed, test-only key, renders admin icons without the Iconify API and
processes payment requests with `sync://`. `tests/HeadlessApplication` runs the plugin without the shop, the admin
panel and Twig Hooks. CI also runs Infection (mutation tests).

### Tests against the Autopay test environment

`tests/Sandbox` checks the assumptions of [docs/autopay.md](docs/autopay.md) against `testpay.autopay.eu`.
It is not part of CI and is skipped unless enabled:

```bash
AUTOPAY_SANDBOX=1 AUTOPAY_SANDBOX_SERVICE_ID=... AUTOPAY_SANDBOX_SHARED_KEY=... \
vendor/bin/phpunit --testsuite=sandbox
```

The refund test also needs `AUTOPAY_SANDBOX_PAID_REMOTE_ID`, the RemoteID of a transaction paid on the Autopay
test page. The manual scenarios are listed in [docs/autopay.md](docs/autopay.md#manual-checks-in-the-test-environment).

## License

MIT, see [LICENSE](LICENSE).
