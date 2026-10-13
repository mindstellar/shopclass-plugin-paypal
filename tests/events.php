<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The webhook: verification, what each event means for its order, and idempotency.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\paypal\PayPalGateway;
use mindstellar\paypal\Verified;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->sent();

$decide = static fn (array $event): CallbackResult => $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event($event)));

harness_section('event mapping');

$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order)));
pin('a completed capture is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('for the order', 1, $r->getOrderId());
pin('with PayPal\'s amount in micros and currency', array(9_990_000, 'USD'), array($r->getAmount(), $r->getCurrency()));
pin('the capture id is the reference', pp_capture_id(1), $r->getExternalRef());

$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('amount' => array('currency_code' => 'USD', 'value' => '0.01')))));
pin('another amount is still reported as PayPal\'s figure, for core to refuse', 10_000, $r->getAmount());

$r = $decide(event('PAYMENT.CAPTURE.DENIED', pp_capture($order, array('status' => 'DECLINED'))));
pin('a denied capture fails the order', CallbackResult::OUTCOME_FAILED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.DENIED', pp_capture($order, array('amount' => array('currency_code' => 'USD', 'value' => '1.00')))));
pin('a denial for another amount is refused', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());

$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order));
$r = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($order, 'APPROVED', null)));
pin('an approved order the buyer never came back from is captured', 'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . pp_order_id(1) . '/capture', $http->lastCall()['url']);
pin('and paid', array(CallbackResult::OUTCOME_PAID, pp_capture_id(1)), array($r->getOutcome(), $r->getExternalRef()));

$count = count($http->requests);
$r     = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($order, 'APPROVED', null, array('purchase_units' => array(array('custom_id' => '1', 'amount' => array('currency_code' => 'USD', 'value' => '0.01')))))));
pin('an approval for another amount is not captured', array(CallbackResult::OUTCOME_IGNORED, $count), array($r->getOutcome(), count($http->requests)));

$paid  = $orders->sent();
$orders->set($paid->getId(), 'paid', pp_capture_id($paid->getId()));
$count = count($http->requests);
$r     = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($orders->find($paid->getId()), 'APPROVED', null)));
pin('an approval for an order already paid captures nothing', array('order is no longer pending', $count), array($r->getMessage(), count($http->requests)));
$failed = $orders->sent();
$orders->set($failed->getId(), 'failed', null);
$r = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($orders->find($failed->getId()), 'APPROVED', null)));
pin('nor for one already closed, so no money is taken for an order core would not credit', array('order is no longer pending', $count), array($r->getMessage(), count($http->requests)));

$refundable = $orders->find($paid->getId());
$r          = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable)));
pin('a full refund refunds the order', array(CallbackResult::OUTCOME_REFUNDED, $paid->getId()), array($r->getOutcome(), $r->getOrderId()));
pin('with the refund id', '1JU08902781691411', $r->getExternalRef());

$half = array('currency_code' => 'USD', 'value' => '5.00');
$r    = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable, array('amount' => $half, 'seller_payable_breakdown' => array('total_refunded_amount' => $half)))));
pin('a partial refund changes nothing', 'partial refund', $r->getMessage());
$r = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable, array('amount' => array('currency_code' => 'USD', 'value' => '4.99')))));
pin('the last of several partial refunds counts by the total refunded', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable, array('status' => 'PENDING'))));
pin('a pending refund waits', 'refund not completed', $r->getMessage());
$r = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable, array('custom_id' => '1'))));
pin('a refund whose custom id names another order is refused', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable, array('links' => array()))));
pin('so is one that names no capture', 'refund names no capture', $r->getMessage());

$r = $decide(event('PAYMENT.CAPTURE.PENDING', pp_capture($order)));
pin('anything else is ignored', 'event not handled', $r->getMessage());

harness_section('orders that are not ours');

$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('supplementary_data' => array('related_ids' => array('order_id' => '9ZZ99999999999999'))))));
pin('another site on the same PayPal account (same order number, other PayPal order)', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('custom_id' => '2'))));
pin('a custom id that disagrees with the PayPal order', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('custom_id' => '1 OR 1=1'))));
pin('a custom id that is not a number', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$test = $orders->add(9_990_000, 'USD', pp_order_id(count($orders->orders) + 1), 'pending', 'test');
$r    = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($test)));
pin('an order of another gateway', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('custom_id' => '999'))));
pin('an order that does not exist', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$noCustom = pp_capture($order);
unset($noCustom['custom_id']);
pin('without a custom id the order is found by its PayPal order', 1, $decide(event('PAYMENT.CAPTURE.COMPLETED', $noCustom))->getOrderId());

harness_section('the webhook');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->sent();
$gateway = settling_gateway($http, $orders);
$paidEvt = event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order));

deliver($http, $paidEvt);
$r = $gateway->handleCallback(array());
pin('a verified event is settled', array('paid'), array_map(static fn ($x) => $x->getOutcome(), $GLOBALS['settled']));
check('and the route answers 200', !$r->isRetryable());
pin('PayPal was asked with this site\'s webhook id', HOOK_ID, json_decode($http->lastCall()['body'], true)['webhook_id']);
pin('the order is paid', Order::STATUS_PAID, $orders->find(1)->getStatus());

harness_section('idempotency');

deliver($http, $paidEvt);
$gateway->handleCallback(array());
deliver($http, event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order), 'WH-OTHER-DELIVERY-1'));
$gateway->handleCallback(array());
$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order));
$gateway->processReturn(pp_order_id(1), 1);
pin('a replay and a second delivery name the same order; a late return does nothing', array(1, 1, 1, null), array_map(static fn ($x) => $x->getOrderId(), $GLOBALS['settled']));
pin('the late return did not capture again', 0, count(array_filter($http->calls(), static fn ($c) => str_ends_with($c['url'], '/capture'))));
pin('but it is credited once', array(1), $GLOBALS['credited']);

harness_section('refused before anything is settled');

$GLOBALS['settled'] = array();
deliver($http, $paidEvt, 'FAILURE');
$r = $gateway->handleCallback(array());
pin('a delivery PayPal does not vouch for is ignored', 'bad signature', $r->getMessage());
check('and never retried', !$r->isRetryable());
pin('nothing settled', array(), $GLOBALS['settled']);

$GLOBALS['input'] = array(json_encode($paidEvt), array());
pin('without the transmission headers', 'bad signature', $gateway->handleCallback(array())->getMessage());

$GLOBALS['input'] = array(json_encode($paidEvt), pp_headers());
pin('a forged trust key in the request is not trusted', 'bad signature', $gateway->handleCallback(array('raw' => $paidEvt, PayPalGateway::TRUSTED => 'yes'))->getMessage());
pin('still nothing settled', array(), $GLOBALS['settled']);

$count = count($http->requests);
deliver($http, event('PAYMENT.SALE.COMPLETED', pp_capture($order)));
pin('an event type we do not handle is ignored', 'event not handled', $gateway->handleCallback(array())->getMessage());
$GLOBALS['input'] = array('not json', pp_headers());
pin('a body that is not an event is ignored', 'malformed event', $gateway->handleCallback(array())->getMessage());
deliver($http, event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order), 'evt_1'));
pin('so is a malformed event id', 'malformed event', $gateway->handleCallback(array())->getMessage());
pin('none of these asked PayPal', $count, count($http->requests));

$http->down('POST', 'v1/notifications/verify-webhook-signature');
$GLOBALS['input'] = array(json_encode($paidEvt), pp_headers());
$r = $gateway->handleCallback(array());
check('PayPal unreachable for the check: it is told to retry', $r->isRetryable());
pin('and nothing is queued, since nothing was verified', array(), $GLOBALS['queued']);

harness_section('when settling fails');

$broken = fake_gateway($http, $orders, array(), static function (array $request): CallbackResult {
    throw new RuntimeException('MySQL server has gone away');
});
deliver($http, $paidEvt);
$r = $broken->handleCallback(array());
check('PayPal is told to retry', $r->isRetryable());
pin('and the event id alone is queued', array($paidEvt['id']), $GLOBALS['queued']);

$noQueue = new PayPalGateway(fake_config(), $http, $orders, new MemoryCache(), static fn () => $GLOBALS['input'], static function (array $r): CallbackResult {
    throw new RuntimeException('db down');
}, static function (string $id): void {
    throw new RuntimeException('queue down too');
});
deliver($http, $paidEvt);
check('even when the queue is down too, PayPal is told to retry', $noQueue->handleCallback(array())->isRetryable());

harness_section('not configured');

$bare = fake_gateway($http, $orders, array('webhook' => ''));
deliver($http, $paidEvt);
$r = $bare->handleCallback(array());
pin('without a webhook id nothing is settled', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
check('but PayPal keeps the event for later', $r->isRetryable());

exit(harness_result());
