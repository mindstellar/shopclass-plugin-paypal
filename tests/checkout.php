<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Creating the PayPal order, capturing it when the buyer returns, and isConfigured().
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\paypal\Http\HttpException;
use mindstellar\paypal\PayPalGateway;

harness_section('isConfigured');

$http   = new FakeHttp();
$orders = new MemoryOrders();
check('ready with a client id, a secret and a webhook id', fake_gateway($http, $orders)->isConfigured());
check('not without the client id', !fake_gateway($http, $orders, array('client' => ''))->isConfigured());
check('not without the secret', !fake_gateway($http, $orders, array('secret' => ''))->isConfigured());
check('not without the webhook id', !fake_gateway($http, $orders, array('webhook' => ''))->isConfigured());
check('not when PayPal does not take the billing currency', !fake_gateway($http, $orders, array('billing' => 'INR'))->isConfigured());
check('yes for a zero-decimal one', fake_gateway($http, $orders, array('billing' => 'JPY'))->isConfigured());
check('in sandbox mode, not offered to a buyer who is not an admin', !fake_gateway($http, $orders, array('admin' => false))->isConfigured());
check('but webhooks and lookups still work for them', fake_gateway($http, $orders, array('admin' => false))->hasKeys());
check('in live mode, offered to everyone', fake_gateway($http, $orders, array('mode' => 'live', 'admin' => false))->isConfigured());
check('only PayPal currencies are listed, so core offers it for no other', !in_array('INR', fake_gateway($http, $orders)->getSupportedCurrencies(), true));
pin('no API call was needed for any of this', array(), $http->requests);

harness_section('createCheckout');

$order = $orders->add(9_990_000, 'USD');
$http->on('POST', 'v2/checkout/orders', 200, pp_order($order, 'PAYER_ACTION_REQUIRED', null));
$gateway = fake_gateway($http, $orders);
$intent  = $gateway->createCheckout($order);

check('the buyer is sent to PayPal', $intent->isRedirect() && $intent->getPayload() === 'https://www.sandbox.paypal.com/checkoutnow?token=' . pp_order_id(1));
$req  = $http->lastCall();
$body = $http->lastJson();
pin('one POST to v2/checkout/orders', array('POST', 'https://api-m.sandbox.paypal.com/v2/checkout/orders'), array($req['method'], $req['url']));
pin('with a bearer token', 'Bearer A21AA1', $req['headers']['Authorization']);
pin('a request id per order and site, so a retry makes one order', 'order-' . SITE . '-1', $req['headers']['PayPal-Request-Id']);
pin('intent CAPTURE', 'CAPTURE', $body['intent']);
pin('one purchase unit for the order', array(
    'reference_id' => 'order-1',
    'custom_id'    => '1',
    'invoice_id'   => SITE . '-1',
    'description'  => '100 credits',
    'amount'       => array('currency_code' => 'USD', 'value' => '9.99'),
), $body['purchase_units'][0]);
pin('return and cancel pages', array(
    'https://shop.test/index.php?page=route&route=paypal-return&order=1',
    'https://shop.test/index.php?page=route&route=paypal-cancel&order=1',
), array($body['payment_source']['paypal']['experience_context']['return_url'], $body['payment_source']['paypal']['experience_context']['cancel_url']));
pin('pay now, no shipping', array('PAY_NOW', 'NO_SHIPPING'), array($body['payment_source']['paypal']['experience_context']['user_action'], $body['payment_source']['paypal']['experience_context']['shipping_preference']));
pin('the PayPal order is remembered on the order', pp_order_id(1), $orders->find(1)->getExternalRef());
pin('and in its meta, with the mode', array(PayPalGateway::META_ORDER => pp_order_id(1), PayPalGateway::META_MODE => 'sandbox'), $orders->find(1)->getMeta());
pin('which stays pending', Order::STATUS_PENDING, $orders->find(1)->getStatus());

$jpy = $orders->add(1_000_000_000, 'JPY');
$http->on('POST', 'v2/checkout/orders', 200, pp_order($jpy, 'PAYER_ACTION_REQUIRED', null));
$gateway->createCheckout($jpy);
pin('yen go as whole yen', array('currency_code' => 'JPY', 'value' => '1000'), $http->lastJson()['purchase_units'][0]['amount']);

$huf = $orders->add(2_500_000_000, 'HUF');
$http->on('POST', 'v2/checkout/orders', 200, pp_order($huf, 'PAYER_ACTION_REQUIRED', null, array('links' => array(array('rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=' . pp_order_id($huf->getId()))))));
pin('the older "approve" link works too', 'https://www.paypal.com/checkoutnow?token=' . pp_order_id($huf->getId()), $gateway->createCheckout($huf)->getPayload());
pin('forints go as whole forints', '2500', $http->lastJson()['purchase_units'][0]['amount']['value']);

harness_section('createCheckout when it cannot');

$GLOBALS['flash'] = array();
$down = $orders->add();
$http->down('POST', 'v2/checkout/orders');
$intent = $gateway->createCheckout($down);
check('PayPal down sends the buyer back to the buy page', $intent->isRedirect() && $intent->getPayload() === 'https://shop.test/buy');
pin('with an error message', 'error', $GLOBALS['flash'][0][0] ?? null);
pin('and the order is closed', Order::STATUS_FAILED, $orders->find($down->getId())->getStatus());

$http->on('POST', 'v2/checkout/orders', 422, array('name' => 'UNPROCESSABLE_ENTITY', 'details' => array(array('issue' => 'CURRENCY_NOT_SUPPORTED'))));
$refused = $orders->add();
$gateway->createCheckout($refused);
pin('an API error closes the order too', Order::STATUS_FAILED, $orders->find($refused->getId())->getStatus());

$count = count($http->requests);
$odd   = $orders->add(9_995_000, 'USD');
$gateway->createCheckout($odd);
pin('an amount PayPal cannot charge never reaches PayPal', $count, count($http->requests));
pin('and closes the order', Order::STATUS_FAILED, $orders->find($odd->getId())->getStatus());
$gateway->createCheckout($orders->add(1_500_000, 'JPY'));
pin('half a yen neither', $count, count($http->requests));
$gateway->createCheckout($orders->add(5_000_000, 'INR'));
pin('nor a currency PayPal does not take', $count, count($http->requests));

$evil = $orders->add();
$http->on('POST', 'v2/checkout/orders', 200, pp_order($evil, 'PAYER_ACTION_REQUIRED', null, array('links' => array(array('rel' => 'payer-action', 'href' => 'https://evil.test/paypal.com')))));
pin('an approve link off paypal.com is refused', 'https://shop.test/buy', $gateway->createCheckout($evil)->getPayload());
$http->on('POST', 'v2/checkout/orders', 200, pp_order($evil, 'PAYER_ACTION_REQUIRED', null, array('links' => array(array('rel' => 'payer-action', 'href' => 'http://www.paypal.com/checkoutnow')))));
pin('so is a plain http one', 'https://shop.test/buy', $gateway->createCheckout($orders->add())->getPayload());

harness_section('storing the PayPal order');

$again = $orders->sent();
$http->on('POST', 'v2/checkout/orders', 200, pp_order($again, 'PAYER_ACTION_REQUIRED', null));
pin('a retry that gets back the PayPal order the core order holds still goes to PayPal', 'https://www.sandbox.paypal.com/checkoutnow?token=' . pp_order_id($again->getId()), $gateway->createCheckout($again)->getPayload());

$taken = $orders->add();
$intent = $gateway->createCheckout($taken);
pin('a PayPal order another order holds is refused', 'https://shop.test/buy', $intent->getPayload());
pin('and this order is closed', Order::STATUS_FAILED, $orders->find($taken->getId())->getStatus());
pin('the other order keeps its PayPal order', pp_order_id($again->getId()), $orders->find($again->getId())->getExternalRef());

$orders->metaFails = true;
$noMeta = $orders->add();
$http->on('POST', 'v2/checkout/orders', 200, pp_order($noMeta, 'PAYER_ACTION_REQUIRED', null));
check('a meta write that throws does not stop the checkout', $gateway->createCheckout($noMeta)->getPayload() !== 'https://shop.test/buy');
$orders->metaFails = null;

harness_section('the return page: capture');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->sent();
$gateway = fake_gateway($http, $orders);
$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order));

$r = $gateway->processReturn(pp_order_id(1), 1);
pin('a captured order is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('with PayPal\'s figures', array(1, 9_990_000, 'USD'), array($r->getOrderId(), $r->getAmount(), $r->getCurrency()));
pin('the capture id is the reference', pp_capture_id(1), $r->getExternalRef());
pin('a capture request id per order and site', 'capture-' . SITE . '-1', $http->lastCall()['headers']['PayPal-Request-Id']);

$other = $orders->sent();
pin('another order\'s PayPal order cannot pay this one', CallbackResult::OUTCOME_IGNORED, $gateway->processReturn(pp_order_id(1), $other->getId())->getOutcome());

$count = count($http->requests);
pin('a malformed PayPal id is refused', 'malformed PayPal order id', $gateway->processReturn('../x', 1)->getMessage());
pin('without asking PayPal', $count, count($http->requests));

$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 422, array('name' => 'UNPROCESSABLE_ENTITY', 'details' => array(array('issue' => 'ORDER_ALREADY_CAPTURED'))));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id(1), 200, pp_order($order));
$r = $gateway->processReturn(pp_order_id(1), 1);
pin('an order already captured is looked up and paid', array(CallbackResult::OUTCOME_PAID, pp_capture_id(1)), array($r->getOutcome(), $r->getExternalRef()));

$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 422, array('name' => 'UNPROCESSABLE_ENTITY', 'details' => array(array('issue' => 'INSTRUMENT_DECLINED'))));
$r = $gateway->processReturn(pp_order_id(1), 1);
pin('a declined funding source leaves the order pending', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
check('and says why', str_contains($r->getMessage(), 'INSTRUMENT_DECLINED'));

$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order, 'COMPLETED', array(pp_capture($order, array('status' => 'PENDING')))));
pin('a pending capture waits', 'payment is pending at PayPal', $gateway->processReturn(pp_order_id(1), 1)->getMessage());

$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order, 'COMPLETED', array(pp_capture($order, array('status' => 'DECLINED')))));
pin('a declined capture fails the order', CallbackResult::OUTCOME_FAILED, $gateway->processReturn(pp_order_id(1), 1)->getOutcome());

$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order, 'COMPLETED', array(pp_capture($order, array('amount' => array('currency_code' => 'USD', 'value' => '0.01'))))));
pin('a capture for another amount is reported as PayPal\'s own figure, for core to refuse', 10_000, $gateway->processReturn(pp_order_id(1), 1)->getAmount());

$http->down('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture');
$threw = false;
try {
    $gateway->processReturn(pp_order_id(1), 1);
} catch (HttpException $e) {
    $threw = true;
}
check('PayPal down throws, so the caller can say "processing"', $threw);

$paid  = $orders->add(9_990_000, 'USD', pp_capture_id(3), 'paid', 'paypal', '2026-01-01 00:00:00', array(PayPalGateway::META_ORDER => pp_order_id(3)));
$count = count($http->requests);
pin('an order no longer pending is never captured', 'order is no longer pending', $gateway->processReturn(pp_order_id(3), $paid->getId())->getMessage());
pin('PayPal is not asked', $count, count($http->requests));

exit(harness_result());
