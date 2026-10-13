<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The queued event job and the daily check of pending orders.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\paypal\Http\HttpException;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->sent();
$id      = 'WH-2WR32451HC0233532-67976317FL4543714';

harness_section('the event job');

$http->on('GET', 'v1/notifications/webhooks-events/' . $id, 200, event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order), $id));
$r = $gateway->processEvent($id);
pin('the event is fetched from PayPal', 'https://api-m.sandbox.paypal.com/v1/notifications/webhooks-events/' . $id, $http->lastCall()['url']);
pin('and settled through the same path as the webhook', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('once', 1, count($GLOBALS['settled']));

$count = count($http->requests);
pin('a malformed id is refused', 'malformed event id', $gateway->processEvent('WH-../x')->getMessage());
pin('without calling PayPal', $count, count($http->requests));

$http->down('GET', 'v1/notifications/webhooks-events/WH-DOWN-1');
$threw = false;
try {
    $gateway->processEvent('WH-DOWN-1');
} catch (HttpException $e) {
    $threw = true;
}
check('PayPal down throws, so the queue retries', $threw);

harness_section('the daily check');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$hour    = date('Y-m-d H:i:s', time() - 7200);
$old     = date('Y-m-d H:i:s', time() - 2 * 86400);

$done      = $orders->sent(9_990_000, 'USD', $hour);
$approved  = $orders->sent(9_990_000, 'USD', $hour);
$waiting   = $orders->sent(9_990_000, 'USD', $hour);
$abandoned = $orders->sent(9_990_000, 'USD', $old);
$gone      = $orders->sent(9_990_000, 'USD', $old);
$fresh     = $orders->sent(9_990_000, 'USD', date('Y-m-d H:i:s'));
$live      = $orders->sent(9_990_000, 'USD', $hour, 'live');

$http->on('GET', 'v2/checkout/orders/' . pp_order_id($done->getId()), 200, pp_order($done));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($approved->getId()), 200, pp_order($approved, 'APPROVED', null));
$http->on('POST', 'v2/checkout/orders/' . pp_order_id($approved->getId()) . '/capture', 201, pp_order($approved));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($waiting->getId()), 200, pp_order($waiting, 'PAYER_ACTION_REQUIRED', null));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($abandoned->getId()), 200, pp_order($abandoned, 'PAYER_ACTION_REQUIRED', null));

pin('orders over an hour old in this mode are checked', 5, $gateway->reconcile());
pin('a completed PayPal order is paid', Order::STATUS_PAID, $orders->find($done->getId())->getStatus());
pin('an approved one is captured and paid', Order::STATUS_PAID, $orders->find($approved->getId())->getStatus());
pin('an unapproved one under a day old waits', Order::STATUS_PENDING, $orders->find($waiting->getId())->getStatus());
pin('one over a day old is closed', Order::STATUS_FAILED, $orders->find($abandoned->getId())->getStatus());
pin('so is one PayPal no longer has', Order::STATUS_FAILED, $orders->find($gone->getId())->getStatus());
pin('a fresh one is left alone', Order::STATUS_PENDING, $orders->find($fresh->getId())->getStatus());
pin('a live one is skipped in sandbox mode', Order::STATUS_PENDING, $orders->find($live->getId())->getStatus());
check('and PayPal was never asked about it', array_filter($http->requests, static fn ($r) => str_contains($r['url'], pp_order_id($live->getId()))) === array());

$young = $orders->sent(9_990_000, 'USD', $hour);
$GLOBALS['settled'] = array();
$gateway->reconcile();
pin('a young order PayPal does not have is not closed yet', Order::STATUS_PENDING, $orders->find($young->getId())->getStatus());

$http->down('GET', 'v2/checkout/orders/' . pp_order_id($waiting->getId()));
$threw = false;
try {
    $gateway->reconcile();
} catch (HttpException $e) {
    $threw = str_contains($e->getMessage(), '#' . $waiting->getId());
}
check('a failed lookup throws naming the order, so the job retries', $threw);

exit(harness_result());
