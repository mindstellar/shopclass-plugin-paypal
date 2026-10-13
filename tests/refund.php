<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Refunds asked for from the admin.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\RefundableGateway;
use mindstellar\paypal\PayPalGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(9_990_000, 'USD', pp_capture_id(1), 'paid', 'paypal', '2026-01-01 00:00:00', array(PayPalGateway::META_ORDER => pp_order_id(1)));
$path    = 'v2/payments/captures/' . pp_capture_id(1) . '/refund';

harness_section('refund');

check('the gateway offers refunds', $gateway instanceof RefundableGateway);

$http->on('POST', $path, 201, array('id' => '1JU08902781691411', 'status' => 'COMPLETED'));
$r   = $gateway->refund($order);
$req = $http->lastCall();
pin('an accepted refund refunds the order', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('with the refund id', array(1, '1JU08902781691411'), array($r->getOrderId(), $r->getExternalRef()));
pin('of the order\'s capture', 'https://api-m.sandbox.paypal.com/' . $path, $req['url']);
pin('in full: an empty body', '{}', $req['body']);
pin('with a request id, so a double click refunds once', 'refund-' . SITE . '-1', $req['headers']['PayPal-Request-Id']);

$http->on('POST', $path, 201, array('id' => '1JU08902781691412', 'status' => 'PENDING'));
$r = $gateway->refund($order);
pin('a pending refund leaves the order for the webhook', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
pin('and says so', 'PayPal is still processing the refund; the order updates when it completes.', $r->getMessage());

$http->on('POST', $path, 201, array('id' => '1JU08902781691413', 'status' => 'CANCELLED'));
pin('a cancelled refund changes nothing, and says why', 'PayPal did not accept the refund (CANCELLED)', $gateway->refund($order)->getMessage());

$http->on('POST', $path, 422, array('name' => 'UNPROCESSABLE_ENTITY', 'details' => array(array('issue' => 'CAPTURE_FULLY_REFUNDED'))));
$r = $gateway->refund($order);
pin('a refusal is ignored', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
pin('with PayPal\'s reason for the admin', 'PayPal answered 422: UNPROCESSABLE_ENTITY (CAPTURE_FULLY_REFUNDED)', $r->getMessage());

$http->down('POST', $path);
pin('PayPal down is ignored with a reason, and can be tried again', 'PayPal could not be reached: timeout', $gateway->refund($order)->getMessage());

$http->on('POST', $path, 201, array('status' => 'COMPLETED'));
pin('an answer without a refund id is not a refund', 'PayPal did not accept the refund', $gateway->refund($order)->getMessage());

$held = $orders->add(9_990_000, 'USD', pp_order_id(2), 'paid', 'paypal', '2026-01-01 00:00:00', array(PayPalGateway::META_ORDER => pp_order_id(2)));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id(2), 200, pp_order($held));
$http->on('POST', 'v2/payments/captures/' . pp_capture_id(2) . '/refund', 201, array('id' => '1JU08902781691414', 'status' => 'COMPLETED'));
pin('an order still holding its PayPal order id is refunded through its capture', CallbackResult::OUTCOME_REFUNDED, $gateway->refund($held)->getOutcome());

$none  = $orders->add(9_990_000, 'USD', null, 'paid');
$count = count($http->requests);
pin('an order with no PayPal payment is refused', 'no PayPal payment found for this order', $gateway->refund($none)->getMessage());
pin('without calling PayPal', $count, count($http->requests));

exit(harness_result());
