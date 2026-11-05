# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org):
deprecations are added in 1.x releases, and deprecated code is removed in 2.0.

## Unreleased

### Added

- Autopay (Blue Media) online payments gateway (`refberg_autopay`) for Sylius 2.2.12+ and 2.3.2+, built on Sylius
  Payment Requests, without Payum.
- Shop and API checkout with the transaction started in the background and a redirect to the Autopay page.
- Status check after the customer returns, based on the Autopay transaction status API.
- ITN endpoint with double hash verification, a signed confirmation and amount, currency, service ID and OrderID checks;
  idempotent and order-independent. Temporary and authorization errors answer HTTP 5xx, so Autopay repeats the ITN.
- Full refunds from the admin panel, idempotent by `MessageID`, with their status from ISTN notifications or the
  `refberg:autopay:refresh-refunds` command.
- Refunds made in the Autopay panel are recorded from ISTN notifications; a full one marks the payment as refunded.
- Cancelling a payment in Sylius cancels its open Autopay transactions (`transactionCancel`).
- A second paid transaction of the same OrderID or payment is flagged with the `duplicate_payment` warning.
- A payment paid with a transaction started before the customer changed the payment method gets the Autopay method
  back and the `paid_with_previous_payment_method` warning.
- Container parameters `refberg_autopay.http_timeout` and `refberg_autopay.transaction_validity`.
- Optional integration with the Sylius shop, admin panel and Twig Hooks.
- Autopay transaction, refunds and warnings shown next to the payment on the admin order page.
- Currency setting of the Autopay service; the method is offered only for payments in that currency.
- Container parameter `refberg_autopay.allowed_return_hosts`; a `return_url` payload must point to the channel's
  hostname or a listed host.
- Refunds that did not reach Autopay are requested again by `refberg:autopay:refresh-refunds` with the same
  `MessageID`.
- After a transaction pays the payment, the other open transactions of the payment are cancelled at Autopay.
- The shop shows an error message when Autopay refuses to start a transaction or cannot be reached.
- A failed Autopay transaction does not fail the Sylius payment, so a later attempt of the same OrderID can pay it.
- Correctly signed notifications for unknown OrderIDs are confirmed and logged instead of answered with HTTP 404.

### Changed

### Deprecated

### Fixed

### Removed
