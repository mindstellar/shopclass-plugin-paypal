<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Part refunds asked for from the admin (Shopclass 7.1).
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\PartialRefundableGateway;

if (!interface_exists(PartialRefundableGateway::class)) {
    check('skipped: part refunds need Shopclass 7.1', true);
    exit(harness_result());
}
use mindstellar\paypal\PayPalGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(9_990_000, 'USD', pp_capture_id(1), 'paid', 'paypal', '2026-01-01 00:00:00', array(PayPalGateway::META_ORDER => pp_order_id(1)));
$path    = 'v2/payments/captures/' . pp_capture_id(1) . '/refund';

harness_section('refund part');

check('the gateway offers part refunds', $gateway instanceof PartialRefundableGateway);

$http->on('POST', $path, 201, array('id' => '1JU08902781691421', 'status' => 'COMPLETED'));
$r   = $gateway->refundPart($order, 2_500_000, 'order:1:refund:1');
$req = $http->lastCall();
pin('an accepted part refund is reported as refunded', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('with the refund id', array(1, '1JU08902781691421'), array($r->getOrderId(), $r->getExternalRef()));
pin('of the order\'s capture', 'https://api-m.sandbox.paypal.com/' . $path, $req['url']);
pin('for the amount', array('amount' => array('value' => '2.50', 'currency_code' => 'USD')), $http->lastJson());
pin('with core\'s key as the request id, per site', 'refund-' . SITE . '-order:1:refund:1', $req['headers']['PayPal-Request-Id']);

$gateway->refundPart($order, 2_500_000, 'order:1:refund:2');
pin('the next part refund has its own request id', 'refund-' . SITE . '-order:1:refund:2', $http->lastCall()['headers']['PayPal-Request-Id']);

$http->on('POST', $path, 201, array('id' => '1JU08902781691422', 'status' => 'PENDING'));
$r = $gateway->refundPart($order, 1_000_000, 'order:1:refund:3');
pin('a pending part refund is accepted with its id', array(CallbackResult::OUTCOME_PENDING, '1JU08902781691422', 'PayPal is still processing the refund.'), array($r->getOutcome(), $r->getExternalRef(), $r->getMessage()));

$http->on('POST', $path, 201, array('id' => '1JU08902781691423', 'status' => 'CANCELLED'));
pin('a cancelled part refund changes nothing, and says why', array(CallbackResult::OUTCOME_IGNORED, 'PayPal did not accept the refund (CANCELLED)'), array(
    $gateway->refundPart($order, 1_000_000, 'order:1:refund:4')->getOutcome(),
    $gateway->refundPart($order, 1_000_000, 'order:1:refund:4')->getMessage(),
));

$http->on('POST', $path, 422, array('name' => 'UNPROCESSABLE_ENTITY', 'details' => array(array('issue' => 'REFUND_AMOUNT_EXCEEDED'))));
pin('a refusal comes back with PayPal\'s reason', 'PayPal answered 422: UNPROCESSABLE_ENTITY (REFUND_AMOUNT_EXCEEDED)', $gateway->refundPart($order, 9_000_000, 'order:1:refund:5')->getMessage());

$throws = static function (callable $fn): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return true;
    }

    return false;
};
$http->down('POST', $path);
check('PayPal down throws, so core keeps the key for the retry', $throws(static fn () => $gateway->refundPart($order, 1_000_000, 'order:1:refund:6')));
$http->on('POST', $path, 503, array('name' => 'INTERNAL_SERVER_ERROR'));
check('a PayPal 5xx throws too', $throws(static fn () => $gateway->refundPart($order, 1_000_000, 'order:1:refund:6')));

harness_section('refused before PayPal is asked');

$http->on('POST', $path, 201, array('id' => '1JU08902781691429', 'status' => 'COMPLETED'));
$count = count($http->requests);
foreach (array(0, -1_000_000, 9_990_001) as $amount) {
    pin('amount ' . $amount . ' is refused', 'the refund must be more than 0 and at most the order total', $gateway->refundPart($order, $amount, 'order:1:refund:7')->getMessage());
}
pin('a part of a cent is refused', 'amount cannot be refunded in USD', $gateway->refundPart($order, 1_234_567, 'order:1:refund:7')->getMessage());
foreach (array('', 'order 1', str_repeat('k', 41)) as $key) {
    pin('key "' . $key . '" is refused', 'malformed refund key', $gateway->refundPart($order, 1_000_000, $key)->getMessage());
}
$none = $orders->add(9_990_000, 'USD', null, 'paid');
pin('an order with no PayPal payment is refused', 'no PayPal payment found for this order', $gateway->refundPart($none, 1_000_000, 'order:2:refund:1')->getMessage());
pin('without calling PayPal', $count, count($http->requests));

harness_section('other currencies and orders');

$yen  = $orders->add(1_000_000_000, 'JPY', pp_capture_id(3), 'paid', 'paypal', '2026-01-01 00:00:00', array(PayPalGateway::META_ORDER => pp_order_id(3)));
$http->on('POST', 'v2/payments/captures/' . pp_capture_id(3) . '/refund', 201, array('id' => '1JU08902781691431', 'status' => 'COMPLETED'));
$gateway->refundPart($yen, 300_000_000, 'order:3:refund:1');
pin('a zero-decimal currency is sent in whole units', array('value' => '300', 'currency_code' => 'JPY'), $http->lastJson()['amount']);

$held = $orders->add(9_990_000, 'USD', pp_order_id(4), 'paid', 'paypal', '2026-01-01 00:00:00', array(PayPalGateway::META_ORDER => pp_order_id(4)));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id(4), 200, pp_order($held));
$http->on('POST', 'v2/payments/captures/' . pp_capture_id(4) . '/refund', 201, array('id' => '1JU08902781691441', 'status' => 'COMPLETED'));
pin('an order still holding its PayPal order id is refunded through its capture', CallbackResult::OUTCOME_REFUNDED, $gateway->refundPart($held, 1_000_000, 'order:4:refund:1')->getOutcome());

exit(harness_result());
