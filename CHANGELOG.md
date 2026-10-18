# Changelog

## 0.1.4

### Changed
- Tests load the core container the registries now sit on.

## 0.1.3

### Changed
- The check for unanswered payments reads its orders through core's order store, oldest first.
- After paying, the buyer returns to the theme's own order page when the theme has one.
- The description and README say the plugin pays for shop orders as well as credit packages.
- The README covers install, settings, what the plugin stores and its hooks.
- The CI tests run against the core branch named by the `SHOPCLASS_CORE_REF` variable, `develop` by default.

## 0.1.2

### Fixed
- Shipped and delivered orders keep the payment link, a late payment for them is not reported as stuck, and goods orders are not told credits were added.

## 0.1.1

### Changed
- A shop order shows what was bought at PayPal, not a credits count.
- The admin mail about a payment for a closed order no longer speaks of credits.

## 0.1.0

### New
- Payments for credit packages through PayPal Checkout (Orders v2); buyers pay on PayPal's pages.
- The order is captured when the buyer returns, so it shows as paid at once.
- Webhooks are checked locally and then with PayPal; the payment's state is always read from PayPal, never the event body.
- A payment that lands after its order was closed is refunded at PayPal, and the admin is told.
- Approved orders the buyer never returned from are captured from the webhook.
- Denied captures close the order; refunds made at PayPal are recorded.
- An event the site could not record is queued and retried; the admin is e-mailed if it still fails.
- A daily check settles pending orders PayPal never reported and closes abandoned ones.
- Refunds from the admin order screen, and a "View payment" link to the PayPal activity page.
- Sandbox and live apps with separate credentials; sandbox is offered only to signed-in admins.
- A connection check on the settings page for the credentials and the webhook, kept for ten minutes.
- Only PayPal's 24 currencies are offered; HUF, JPY and TWD are charged in whole units.
- Translation template in `languages/paypal.pot`.
