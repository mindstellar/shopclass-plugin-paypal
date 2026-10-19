# PayPal Payment

Take payments for ShopClass shop orders and credit packages through
[PayPal Checkout](https://developer.paypal.com/docs/checkout/). Buyers pay on PayPal's own
pages, so no card or account details reach your site. When the buyer comes back, the plugin
captures the payment and core adds the credits or marks the shop order paid. A verified webhook covers buyers who never
come back.

## Requirements

- ShopClass 7.0.0 or later, with billing turned on
- PHP 8.0 or later, with the curl extension
- Cron running, for retries and the daily check of pending orders

It works on shared hosting: the daily check and webhook retries run on cron or core's auto-cron.

## Install

From the admin: **Plugins → Manage plugins → Browse**, find *PayPal Payment*, then **Install** and activate it. Or upload the zip under **Plugins → Add new**.

## Settings

1. Open **Settings → Billing → Payment methods** and choose **Set up** next to PayPal Payment.
2. At [developer.paypal.com](https://developer.paypal.com), open **Apps & Credentials** with
   *Sandbox* selected and create an app (or open the default one).
3. Copy its **Client ID** and **Secret** into the *Sandbox app* fields.
4. In the same app, add a webhook with the URL the settings page shows:

   ```
   https://example.com/index.php?page=billing&action=callback&gateway=paypal
   ```

   and subscribe it to these events:

   - `CHECKOUT.ORDER.APPROVED`
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.DENIED`
   - `PAYMENT.CAPTURE.REFUNDED`

5. Copy the webhook's **ID** into *Webhook ID* and save. The *Connection* line on the
   settings page says whether the credentials and the webhook work. It is checked again
   after each save, and otherwise every ten minutes.

PayPal shows at checkout once the active mode has a client ID, a secret and a webhook ID,
and the site's billing currency is one PayPal takes.

The secret is never shown again. Leave its box blank to keep it.

## Currencies

PayPal takes AUD, BRL, CAD, CHF, CNY, CZK, DKK, EUR, GBP, HKD, HUF, ILS, JPY, MXN, MYR,
NOK, NZD, PHP, PLN, SEK, SGD, THB, TWD and USD. With any other billing currency the
gateway is not offered. HUF, JPY and TWD are charged in whole units, so a package priced
with a fraction in those currencies cannot be paid with PayPal.

## Sandbox mode

The plugin starts in sandbox mode, and an admin notice says so. In sandbox mode only a
signed-in admin sees PayPal at checkout, so a live site cannot give credits away. Pay with a
[sandbox buyer account](https://developer.paypal.com/tools/sandbox/accounts/).

To go live, switch PayPal's dashboard to *Live*, copy the live app's client ID and secret,
add the same webhook to the live app, paste its ID, then switch *Mode* to *Live*.

## How an order is paid

- When the buyer comes back from PayPal, the plugin captures the payment and settles the
  order at once.
- If the buyer closes the tab after approving, the `CHECKOUT.ORDER.APPROVED` webhook
  captures it. Captures that clear later, such as eChecks, arrive as
  `PAYMENT.CAPTURE.COMPLETED`.
- A buyer who cancels at PayPal comes back to the buy page and the order is closed.
- If the site cannot record a payment, the event is queued and retried, and PayPal sends
  it again. If it still fails, the site's contact address gets an e-mail.
- Once a day, pending PayPal orders over an hour old are checked with PayPal. Orders the
  buyer never approved are closed after a day.

- If a payment lands after its order was closed (the buyer cancelled in another tab, say),
  the plugin refunds it at PayPal at once and e-mails the site's contact address.

Every path goes through core's billing checks: amount, currency and owner must match, and a
replayed event credits nothing. The plugin never captures money for an order that is no
longer pending.

## Refunds

Open the order under **Billing → Orders** and press **Refund**. The plugin refunds the whole
payment at PayPal and core takes the credits back. If PayPal is still processing the
refund, the order changes once PayPal reports it done. A full refund made in your PayPal
account is recorded the same way. Partial refunds change nothing on the site.

## Several sites, one PayPal account

Each site stores the PayPal order it created and ignores events for any other, and invoice
ids carry a per-site prefix, so several sites can share an account. Give each site its own
webhook.

## What it stores

The credentials, mode and site id are preferences in the `paypal` section. An order keeps the PayPal order and mode in its meta. The access token is cached for its lifetime. Retries and the daily check are jobs in core's job queue. No table of its own.

## Hooks

Registers the gateway on `register_payment_gateways`, adds the return and cancel pages, and listens to `register_jobs`, `cron_daily` and `job_gave_up`. It fires no hooks.

## Uninstall

Uninstalling removes the plugin's settings, the saved secrets and the cached PayPal token.
The site id is kept, so orders still in flight settle after a reinstall.

## Translations

Copy `languages/paypal.pot` to `languages/<locale>/messages.po`, translate it, and compile
it to `messages.mo` in the same folder. After changing strings, run `php tools/make-pot.php`.

## Tests

```bash
./tests/run.sh                                  # unit tests, no network
php tests/integration/billing.php               # core's scratch MySQL on port 33061
```

Both load core from `SHOPCLASS_CORE`, or a sibling checkout named `osclass`.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
