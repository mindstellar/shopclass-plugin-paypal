# PayPal Payment

Take payments for shop orders and credit packages through [PayPal Checkout](https://developer.paypal.com/docs/checkout/). Buyers pay on PayPal's own pages, so no card details reach your site.

## Requirements

- Shopclass 7.0.0 or later, with billing turned on
- PHP 8.0 or later
- A PayPal business account with a REST app (Client ID and Secret)
- Cron or core's auto-cron, for retries and the daily check of pending orders

## Install

1. Open **Plugins → Browse** (or upload the zip from the GitHub release).
2. Install.
3. Activate.

## Set up

1. Open **Settings → Billing → Payment methods** and choose **Set up** next to PayPal Payment.
2. At [developer.paypal.com](https://developer.paypal.com), open **Apps & Credentials** with *Sandbox* selected and create an app.
3. Copy its Client ID and Secret into the **Sandbox app** fields.
4. In the same app, add a webhook with the URL shown under **Webhook URL**:

   ```
   https://example.com/index.php?page=billing&action=callback&gateway=paypal
   ```

   Subscribe it to `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED` and `PAYMENT.CAPTURE.REFUNDED`.
5. Copy the webhook's ID into **Webhook ID** and save. The **Connection** line says whether the credentials and webhook work.
6. To go live, repeat steps 2 to 5 with *Live* selected, fill the **Live app** fields, then set **Mode** to *Live*.

The secret is never shown again. Leave its box blank to keep it. **Name at checkout** sets the label buyers see (default `PayPal`).

## How it works

- PayPal shows at checkout once the active mode has a Client ID and Secret, and the billing currency is supported.
- The buyer pays on PayPal. On return, the plugin captures the payment and settles the order.
- If the buyer never returns, the `CHECKOUT.ORDER.APPROVED` webhook captures it.
- A buyer who cancels at PayPal returns to the buy page and the order is closed.
- Once a day, pending orders over an hour old are checked with PayPal. Orders never approved are closed after a day.
- If a payment arrives for an order already closed, the plugin refunds it and e-mails the site's contact address.
- To refund, open the order under **Billing → Orders** and press **Refund**. The whole payment is refunded and core takes the credits back. A full refund made in your PayPal account is recorded the same way.

## Notes / limits

- The plugin starts in sandbox mode, and an admin notice says so. In sandbox mode only a signed-in admin sees PayPal at checkout.
- Webhook ID is optional in sandbox and required in live mode.
- Supported currencies: AUD, BRL, CAD, CHF, CNY, CZK, DKK, EUR, GBP, HKD, HUF, ILS, JPY, MXN, MYR, NOK, NZD, PHP, PLN, SEK, SGD, THB, TWD, USD. Other currencies hide PayPal.
- HUF, JPY and TWD are charged in whole units. A price with a fraction in them cannot be paid.
- Partial refunds made in PayPal change nothing on the site.
- Several sites can share one PayPal account. Give each site its own webhook.
- Uninstalling removes the settings, saved secrets and cached token. The site id is kept, so orders in flight settle after a reinstall.
- No tables of its own. Retries and the daily check use core's job queue.

## For developers

Registers the gateway on `register_payment_gateways` and listens to `register_jobs`, `cron_daily` and `job_gave_up`. It fires no hooks.

Translations: copy `languages/paypal.pot` to `languages/<locale>/messages.po`, translate, and compile to `messages.mo`. Rebuild the template with `php tools/make-pot.php`.

Tests: `./tests/run.sh` (unit) and `php tests/integration/billing.php` (needs core's scratch MySQL on port 33061). Core is loaded from `SHOPCLASS_CORE` or a sibling `osclass` checkout.

## Support

[GitHub issues](https://github.com/mindstellar/shopclass-plugin-paypal/issues)

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
