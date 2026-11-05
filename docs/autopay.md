# Autopay: integration facts and assumptions

This page fills in the "to be checked with the operator" part of the plugin plan. The facts come from the
official Autopay documentation for online payments (<https://developers.autopay.pl/online/dokumentacja>), the
official Autopay PHP SDK (`bluepayment-plugin/bm-sdk`, including its test vectors) and the official Magento 2 module.
What the documentation does not state, or what was not checked in the Autopay test environment yet, is an
**assumption** with an ID (A1–A5). The same IDs mark the tests that depend on them, and
`tests/Sandbox/SandboxFlowTest.php` checks what can be checked automatically.

Before release 1.0.0, check every assumption in the Autopay test environment and update this page.

## Hosts

| Environment | Base URL                       |
|-------------|--------------------------------|
| test        | `https://testpay.autopay.eu`   |
| production  | `https://pay.autopay.eu`       |

## Authentication and hash

There is no token: every request and every message carries `Hash`, which is
`hash(algorithm, value1|value2|...|sharedKey)` over the field values in the order of their numbers in the
documentation. Empty values are skipped, without a separator. The algorithm is set by Autopay for the service ID:
SHA-256 (default) or SHA-512. The implementation matches the documented example and three test vectors of the
Autopay SDK (`tests/Unit/Signature/SignatureVerifierTest.php`).

Errors come as an `<error>` document (`statusCode`, `name`, `description`) or, for some calls, as
`confirmation=NOTCONFIRMED` with a `reason`; usually with HTTP 200. The documentation says the list of errors is not
maintained and only `description` is reliable.

- **A1** – The plugin treats the error names `INVALID_HASH` (documented), `WRONG_HASH` and `INVALID_SERVICE_ID`
  (not documented), and HTTP 401/403 without an error name, as rejected credentials: an ITN that hits one is
  answered with HTTP 5xx, so Autopay repeats it after the merchant fixes the configuration.

## Starting a transaction (pre-transaction)

`POST /payment` with `BmHeader: pay-bm-continue-transaction-url` and the fields
`ServiceID (1), OrderID (2), Amount (3), Description (4), Currency (6), CustomerEmail (7), ValidityTime (19),
ReturnURL (49), Hash`. The answer is `<transaction><status/><redirecturl/><orderID/><remoteID/><hash/></transaction>`,
whose hash the plugin verifies; a refused start is `<transaction><orderID/><confirmation>NOTCONFIRMED</confirmation>
<reason/></transaction>`.

- `OrderID`: up to 32 characters of `[A-Za-z0-9_-]`. The plugin generates a new one per payment attempt:
  `<order number>-<payment ID>-<8 hex>`. Autopay does not check its uniqueness by default; the plugin never reuses one.
- `Description`: up to 79 characters, Latin letters, digits and `. : - ,`; the plugin strips anything else.
- `CustomerEmail` is required by Autopay.
- `ValidityTime` (CET): default 6 days, at most 31 days; after it the link stops working and late transfers are
  returned to the payer. Set by `refberg_autopay.transaction_validity`.
- `ReturnURL`: dynamic return address.
- The ITN address is not sent with the transaction: it is configured by Autopay for the service ID.

## Transaction status

`POST /webapi/transactionStatus` (`BmHeader: pay-bm`) with `ServiceID, OrderID, Hash` returns every transaction
(`remoteID`) of the `OrderID`: the customer can start several on the Autopay page, for example by changing the payment
channel. The answer is signed: `serviceID` followed by `orderID|remoteID|amount|currency|gatewayID|paymentDate|
paymentStatus|paymentStatusDetails` of each transaction in order; the plugin verifies it. More than 50 transactions of
one `OrderID` give HTTP 403 with `LIMIT_REQUESTED_TRANSACTIONS_WITH_THE_SAME_ORDER_ID_AND_SERVICE_ID_EXCEEDED`.

`paymentStatus` is `PENDING`, `SUCCESS` or `FAILURE`. One transaction never goes from `SUCCESS` to another status
(except special business agreements). Following the documentation's proposal, the plugin treats an `OrderID` as:

| Transactions of the OrderID                | Plugin                                              |
|--------------------------------------------|-----------------------------------------------------|
| at least one `SUCCESS`                     | `SUCCESS`; more than one: `duplicate_payment` warning |
| a `PENDING` one and no `SUCCESS`           | `PENDING`                                           |
| only `FAILURE`                             | `FAILURE`                                           |

- **A4** – The elements of the answer come in the documented hash order (the documentation shows the hash chain,
  not a full example document).

## ITN (transaction notifications)

Autopay posts the form field `transactions` with a base64-encoded `<transactionList>` with exactly one transaction.
The hash covers the values of all elements in order (except `hash`). The merchant answers with
`<confirmationList>` signed with `serviceID|orderID|confirmation`. Without a correct answer Autopay repeats the
message: 12 times every 3 minutes, 144 times every 10 minutes, 48 times every hour, then 5 times every day
(about 8 days in total).

- The plugin uses only `orderID` (and checks `serviceID`): the state is always read again from the transaction status
  API and validated (service ID, OrderID, amount, currency) before the payment changes.
- An ITN rejected for good (for example an amount mismatch) is still answered `CONFIRMED`, so Autopay stops
  repeating it; the payment request keeps the reason. Temporary errors answer HTTP 5xx, which is not a correct answer,
  so Autopay repeats the ITN.
- In models where Autopay adds its commission to the amount, the ITN `amount` is higher than the started amount
  (`startAmount` holds the original). The plugin does not support these models: such a payment never completes.
- The customer's return (`ReturnURL?ServiceID=...&OrderID=...&Hash=...`) is never treated as proof of payment.

## Refunds

`POST /settlementapi/transactionRefund` with `ServiceID, MessageID, RemoteID, Amount, Currency, Hash`; the answer is
`<transactionRefund><serviceID/><messageID/><hash/></transactionRefund>` signed with `serviceID|messageID`.
`MessageID` (32 characters) makes the call idempotent: a repeated `MessageID` is confirmed without a second refund.
The plugin derives it from the refund payment request hash. A full refund can be made once; partial refunds as long as
their sum does not exceed the transaction. Refunds are possible up to 12 months back (BLIK: 6 months),
otherwise `TRANSACTION_TOO_OLD_TO_REFUND`. A confirmed request is processed asynchronously (up to 30 minutes) and
can still fail.

The status of a refund is read with `POST /settlementapi/outDetails/v2` (JSON `serviceId, messageId,
method=TRANSACTION_REFUND, hash`; answer `serviceId, messageId, status, remoteOutId, hash, errorStatus...`, signed with
`serviceId|messageId|status|remoteOutId`). `DONE` is final; `CANCELED_NO_FUNDS_ON_BALANCE`,
`CANCELED_AMOUNT_EXCEEDS_TRANSACTION` and `CANCELED_MANUALLY_BY_SERVICES` are final failures; `NEW`,
`WAITING_FOR_TRANSFER_DATA`, `COMMISSIONS_CALCULATED`, `FAILED_ATTEMPT_OF_BALANCE_CHANGE` and `PROCESSING` are in progress.
The `refberg:autopay:refresh-refunds` command reads it.

### ISTN (settlement and refund notifications)

When Autopay enables ISTN for the service (it is off by default and has to be requested together with its address),
it notifies about every payout, including refunds made in the Autopay panel. The document has the same envelope as an
ITN; its transaction has `isRefund`, `orderID` (the refunded transaction), `orderOutID` (the `MessageID` of a refund
requested through the API), `remoteID`, `remoteOutID`, `amount`, `currency` and `transferStatus` (`PENDING`, `SUCCESS`,
`FAILURE`; the basic model sends only `SUCCESS`). The answer refers to `remoteOutID`.

The plugin maps `SUCCESS` to `DONE`, `FAILURE` to `CANCELED` and `PENDING` to `PENDING`. A refund requested by Sylius
is found by its `MessageID`; any other refund of the paid `OrderID` is recorded as external (made in the panel) and,
once done refunds cover the payment, the payment is moved to `refunded`. ISTNs about settlements are confirmed and
ignored.

- **A2** – The ISTN answer is signed with `serviceID|remoteOutID|confirmation` (the documentation shows the structure
  only).
- **A3** – Autopay can send ISTNs to the same address as ITNs (`/payment-methods/{code}`); otherwise ask Autopay to use it.

## Cancellation

`POST /webapi/transactionCancel` (`BmHeader: pay-bm`) with `ServiceID, MessageID, OrderID, Hash` cancels every
transaction of the `OrderID` that waits for a payment. Answer: `<transaction><serviceID/><messageID/><confirmation/>
<reason/><hash/></transaction>`, signed for `CONFIRMED`. Reasons: `CANCELED_FULLY`, `CANCELED_PARTIALLY` (some were
already paid), `INCORRECT_PAYMENT_STATUS` (none was waiting), `TRANSACTION_NOT_FOUND`. After a cancellation no new
transaction with the same `OrderID` can be started, and Autopay sends an ITN with `FAILURE`.

## Changing the payment method after the redirect

- **A5** – A transaction started before the customer switched to another method in Sylius can still be paid on the
  Autopay page until `ValidityTime`. The plugin handles it (the ITN of the old transaction completes the payment and
  restores the Autopay method); whether the Autopay page offers the payment in practice was not checked.

## Manual checks in the test environment

1. Payment accepted and rejected on the Autopay test page; ITN delivered to a public URL (tunnel).
2. Refund from the Sylius admin panel; ISTN (if enabled) or `bin/console refberg:autopay:refresh-refunds`.
3. Refund in the Autopay panel with ISTN enabled (A2, A3), then a refund from Sylius.
4. Cancellation of the payment in Sylius, then an attempt to pay the old link.
5. Switching the payment method after the redirect, then payment of the old transaction (A5).
