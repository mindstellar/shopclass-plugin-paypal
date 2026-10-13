<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The gateway against core's real billing layer and a scratch database, with PayPal faked:
 * a webhook credits once, a replay credits nothing, a wrong amount is refused, the return
 * page and the daily check settle, a refund and its webhook take credits once, and the
 * token is cached in core's key-value store.
 *
 * Needs core's scratch MySQL (see core's tests/lib/scratchdb.php). Usage:
 *   SHOPCLASS_CORE=/path/to/core php tests/integration/billing.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/osclass', '/');
require_once $core . '/tests/lib/scratchdb.php';
require_once $core . '/tests/lib/harness.php';

$admin = scratchdb_session('osc_paypal_plugin');

if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
function osc_plugins_path()
{
    return PLUGINS_PATH;
}
function osc_register_render_target($id, $path)
{
}
function osc_base_url($with_index = false)
{
    return WEB_PATH . ($with_index ? 'index.php' : '');
}
function _m($key)
{
    return $key;
}
require_once $core . '/tests/lib/stubs.php';
function osc_route_url($id, $args = array())
{
    return WEB_PATH . 'index.php?page=route&route=' . $id . '&' . http_build_query($args);
}
function osc_core_url($name, $args = array())
{
    return \mindstellar\routing\CoreRoutes::url($name, $args);
}
if (!function_exists('osc_add_flash_ok_message')) {
    function osc_add_flash_error_message($msg, $section = 'pubMessages')
    {
    }
    function osc_add_flash_ok_message($msg, $section = 'pubMessages')
    {
    }
    function osc_add_flash_info_message($msg, $section = 'pubMessages')
    {
    }
    function osc_add_flash_warning_message($msg, $section = 'pubMessages')
    {
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\paypal\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require_once __DIR__ . '/../lib/fakes.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;
use mindstellar\paypal\CoreOrderStore;
use mindstellar\paypal\KvTokenCache;
use mindstellar\paypal\PayPalGateway;
use mindstellar\paypal\Plugin;

$status = static fn (Order $order): string => OrderStore::find($order->getId())->getStatus();
$ledger = static function (Order $order) use ($admin): int {
    return (int) $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_billing_ledger WHERE s_ref_type = 'order' AND i_ref_id = " . $order->getId()
    )->fetch_assoc()['c'];
};

harness_section('settings page declaration');

$threw = null;
try {
    osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/../../settings.php');
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
pin('core accepts the declaration', null, $threw);
pin('sandbox until an admin picks live', 'sandbox', osc_settings_value(Plugin::PAGE, 'mode'));
pin('no client id until an admin adds one', '', osc_settings_value(Plugin::PAGE, 'sandbox_client_id'));
osc_set_preference('sandbox_secret', 'EStoredSecret_aaaaaaaaaaaaaaaaaaaa', Plugin::PAGE);
osc_reset_preferences();
pin('a stored secret never reads back through the settings page', '', osc_settings_value(Plugin::PAGE, 'sandbox_secret'));
pin('but the gateway reads it', 'EStoredSecret_aaaaaaaaaaaaaaaaaaaa', \mindstellar\paypal\Config::fromSettings()->secret);
$fields = \mindstellar\settings\SettingsPageRegistry::instance()->fields(Plugin::PAGE);
check('core runs the client id check', osc_settings_validate($fields['sandbox_client_id'], 'not a client id!') !== null);

$id = \mindstellar\paypal\Config::siteId();
pin('the site id is made once and kept', $id, \mindstellar\paypal\Config::siteId());
Plugin::uninstall();
pin('uninstall removes every setting, the secret included, and keeps the site id', array('site_id'), array_column($admin->query('SELECT s_name FROM ' . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'paypal'")->fetch_all(MYSQLI_ASSOC), 's_name'));
Plugin::install();
pin('it survives an uninstall and reinstall', $id, \mindstellar\paypal\Config::siteId());

harness_section('the token in core\'s key-value store');

$http  = new FakeHttp();
$store = new CoreOrderStore();
$cache = new KvTokenCache();
$fresh = static fn (): PayPalGateway => new PayPalGateway(fake_config(), $http, $store, $cache, static fn (): array => $GLOBALS['input'], null, static function (string $id): void {
    $GLOBALS['queued'][] = $id;
});
$GLOBALS['queued'] = array();
$GLOBALS['input']  = array('', array());
$gateway           = $fresh();

$http->on('GET', 'v1/notifications/webhooks/' . HOOK_ID, 200, array('url' => '', 'event_types' => array()));
$gateway->diagnose('');
$fresh()->diagnose('');
pin('the connection check always asks for a fresh token', 2, $http->tokens);
$http->on('GET', 'v2/checkout/orders/X0000000000', 200, array('id' => 'X0000000000'));
(new \mindstellar\paypal\PayPalApi($http, \mindstellar\paypal\PayPalApi::SANDBOX, fake_config()->clientId, fake_config()->secret, $cache))->order('X0000000000');
(new \mindstellar\paypal\PayPalApi($http, \mindstellar\paypal\PayPalApi::SANDBOX, fake_config()->clientId, fake_config()->secret, $cache))->order('X0000000000');
pin('other calls share the stored token across requests', 2, $http->tokens);
check('it lives in the paypal group, with an expiry', (int) $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'paypal' AND dt_expires IS NOT NULL")->fetch_assoc()['c'] === 1);

harness_section('fixtures');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
PaymentGatewayRegistry::instance()->register($gateway);
check('offered at checkout for USD', isset(PaymentGatewayRegistry::instance()->available('USD')[PayPalGateway::ID]));
check('not for a currency PayPal does not take', !isset(PaymentGatewayRegistry::instance()->available('INR')[PayPalGateway::ID]));

$buyer    = seed_user($admin, 'buyer', 'buyer@example.test');
$newOrder = static fn (int $amount = 9_990_000, string $currency = 'USD'): Order => OrderStore::create($buyer, PayPalGateway::ID, $amount, $currency, 100);
$webhook  = static function (array $event) use ($http): CallbackResult {
    deliver($http, $event);

    // What core's callback route does with a POST from PayPal.
    return Billing::handleCallback(PayPalGateway::ID, array());
};
$checkout = static function (Order $order) use ($http, $gateway): Order {
    $http->on('POST', 'v2/checkout/orders', 201, pp_order($order, 'PAYER_ACTION_REQUIRED', null));
    $gateway->createCheckout($order);

    return OrderStore::find($order->getId());
};

harness_section('checkout');

$order = $checkout($newOrder());
pin('the PayPal order id is stored on the order', pp_order_id($order->getId()), $order->getExternalRef());
pin('and in its meta, with the mode', array(pp_order_id($order->getId()), 'sandbox'), array($order->meta(PayPalGateway::META_ORDER), $order->meta(PayPalGateway::META_MODE)));
pin('which is still pending', Order::STATUS_PENDING, $order->getStatus());

$other  = $newOrder();
$intent = $gateway->createCheckout($other); // PayPal answers with the first order's PayPal order
pin('core refuses a PayPal order another order holds, so the buyer is not sent to pay', 'https://shop.test/buy', $intent->getPayload());
pin('that order is closed', Order::STATUS_FAILED, $status($other));

harness_section('webhook: paid once, replay credits nothing');

$paid = event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order));
$r    = $webhook($paid);
check('the route answers 200', !$r->isRetryable());
pin('the credits land', 100, Wallet::balance($buyer));
pin('the order is paid', Order::STATUS_PAID, $status($order));
pin('the capture id is stored', pp_capture_id($order->getId()), OrderStore::find($order->getId())->getExternalRef());
pin('the admin order screen links to it at PayPal', 'https://www.sandbox.paypal.com/activity/payment/' . pp_capture_id($order->getId()), $gateway->dashboardUrl(OrderStore::find($order->getId())));

$webhook($paid);
$webhook(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order), 'WH-SECOND-DELIVERY'));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($order->getId()), 200, pp_order($order));
$http->on('POST', 'v2/checkout/orders/' . pp_order_id($order->getId()) . '/capture', 201, pp_order($order));
$webhook(event('CHECKOUT.ORDER.APPROVED', pp_order($order, 'APPROVED', null), 'WH-APPROVED-LATE'));
pin('a replay, a second delivery and a late approval credit nothing more', 100, Wallet::balance($buyer));
pin('one ledger row', 1, $ledger($order));

harness_section('webhook: refused');

$short = $checkout($newOrder());
$webhook(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($short, array('amount' => array('currency_code' => 'USD', 'value' => '0.01')))));
pin('a capture for another amount is refused by core', Order::STATUS_PENDING, $status($short));
$webhook(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($short, array('amount' => array('currency_code' => 'EUR', 'value' => '9.99')))));
pin('so is another currency', Order::STATUS_PENDING, $status($short));
deliver($http, event('PAYMENT.CAPTURE.COMPLETED', pp_capture($short)), 'FAILURE');
Billing::handleCallback(PayPalGateway::ID, array('raw' => event('PAYMENT.CAPTURE.COMPLETED', pp_capture($short))));
pin('so is a delivery PayPal does not vouch for', Order::STATUS_PENDING, $status($short));
pin('none credited anything', 100, Wallet::balance($buyer));

$webhook(event('PAYMENT.CAPTURE.DENIED', pp_capture($short, array('status' => 'DECLINED'))));
pin('a denied capture fails the order', Order::STATUS_FAILED, $status($short));
$webhook(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($short), 'WH-LATE-PAID'));
pin('and a late paid event cannot reopen it', Order::STATUS_FAILED, $status($short));

harness_section('return page');

$back = $checkout($newOrder());
$http->on('POST', 'v2/checkout/orders/' . pp_order_id($back->getId()) . '/capture', 201, pp_order($back));
pin('the return page captures and settles at once', Order::STATUS_PAID, Plugin::settleReturn($gateway, $store, $back->getId(), $buyer, pp_order_id($back->getId())));
pin('credits added', 200, Wallet::balance($buyer));
$webhook(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($back), 'WH-AFTER-RETURN'));
pin('and the webhook that follows adds nothing', 200, Wallet::balance($buyer));

harness_section('cancel page');

$gone = $checkout($newOrder());
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($gone->getId()), 200, pp_order($gone, 'PAYER_ACTION_REQUIRED', null));
pin('a cancelled order is closed', Order::STATUS_FAILED, Plugin::settleCancel($gateway, $store, $gone->getId(), $buyer, pp_order_id($gone->getId())));
$http->on('POST', 'v2/checkout/orders/' . pp_order_id($gone->getId()) . '/capture', 201, pp_order($gone));
$webhook(event('CHECKOUT.ORDER.APPROVED', pp_order($gone, 'APPROVED', null), 'WH-APPROVED-AFTER-CANCEL'));
pin('an approval that arrives after is not captured', 0, count(array_filter($http->calls(), static fn ($c) => str_ends_with($c['url'], pp_order_id($gone->getId()) . '/capture'))));

harness_section('daily check');

$stale = $checkout($newOrder());
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_billing_order SET dt_date = NOW() - INTERVAL 2 HOUR WHERE pk_i_id = ' . $stale->getId());
$fresh = $checkout($newOrder());
pin('only the stale order is due', array($stale->getId()), array_map(static fn (Order $o): int => $o->getId(), $store->stalePending(3600, 100)));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($stale->getId()), 200, pp_order($stale, 'APPROVED', null));
$http->on('POST', 'v2/checkout/orders/' . pp_order_id($stale->getId()) . '/capture', 201, pp_order($stale));
pin('it is checked', 1, $gateway->reconcile());
pin('captured and settled', Order::STATUS_PAID, $status($stale));
pin('the fresh one is left alone', Order::STATUS_PENDING, $status($fresh));

harness_section('refund, then its webhook');

$http->on('POST', 'v2/payments/captures/' . pp_capture_id($order->getId()) . '/refund', 201, array('id' => '1JU08902781691411', 'status' => 'COMPLETED'));
$r = Billing::refundThroughGateway(OrderStore::find($order->getId()));
pin('PayPal accepts the refund and core records it', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('core takes the credits back', 200, Wallet::balance($buyer));
$webhook(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($order), 'WH-REFUND-1'));
pin('the refund webhook that follows takes nothing more', 200, Wallet::balance($buyer));
pin('the order stays refunded', Order::STATUS_REFUNDED, $status($order));

$webhook(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($back), 'WH-REFUND-2'));
pin('a refund made at PayPal is recorded too', Order::STATUS_REFUNDED, $status($back));
pin('and takes its credits back', 100, Wallet::balance($buyer));

pin('nothing needed the queue', array(), $GLOBALS['queued']);

exit(harness_result());
