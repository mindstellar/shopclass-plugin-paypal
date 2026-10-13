<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The return and cancel pages, the job handlers, the give-up mail and the connection check,
 * run for real against fakes.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\Order;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\job\Job;
use mindstellar\paypal\PayPalGateway;
use mindstellar\paypal\Plugin;

$GLOBALS['billing'] = true;
$GLOBALS['jobs']    = array();
$GLOBALS['mails']   = array();
function osc_billing_enabled()
{
    return $GLOBALS['billing'];
}
function osc_job_register_handler($type, $handler)
{
    $GLOBALS['jobs'][$type] = $handler;
}
function osc_job_describe($type, $name, $detail = null)
{
}
function osc_run_hook($hook, ...$args)
{
}
function osc_sendMail($params)
{
    $GLOBALS['mails'][] = $params;
}
function osc_contact_email()
{
    return 'admin@shop.test';
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function osc_base_url($withIndex = false)
{
    return 'https://shop.test/' . ($withIndex ? 'index.php' : '');
}

harness_section('the return page');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$order   = $orders->sent();
$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order));

$GLOBALS['flash'] = array();
pin('another user\'s order is refused', '', Plugin::settleReturn($gateway, $orders, 1, 99, pp_order_id(1)));
pin('PayPal is not asked', array(), $http->requests);
pin('the order is unchanged', Order::STATUS_PENDING, $orders->find(1)->getStatus());
pin('with an error message', 'error', $GLOBALS['flash'][0][0] ?? null);

pin('billing off is refused', '', Plugin::settleReturn(null, $orders, 1, 7, pp_order_id(1)));
pin('an order that does not exist is refused', '', Plugin::settleReturn($gateway, $orders, 999, 7, pp_order_id(1)));
$other = $orders->add(9_990_000, 'USD', pp_order_id(2), 'pending', 'test');
pin('another gateway\'s order is refused', '', Plugin::settleReturn($gateway, $orders, $other->getId(), 7, pp_order_id(2)));
pin('still without asking PayPal', array(), $http->requests);

$GLOBALS['flash'] = array();
pin('a token that is not the order\'s own PayPal order is not captured', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, 1, 7, '9ZZ99999999999999'));
pin('PayPal is not asked', array(), $http->requests);
pin('the buyer is told it is processing', 'info', $GLOBALS['flash'][0][0] ?? null);

$http->down('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture');
$GLOBALS['flash'] = array();
pin('PayPal down is caught; the order stays pending', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, 1, 7, pp_order_id(1)));
pin('and the buyer is told it is processing', 'info', $GLOBALS['flash'][0][0] ?? null);

$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order));
$GLOBALS['flash'] = array();
pin('the owner with the order\'s own PayPal order ends paid', Order::STATUS_PAID, Plugin::settleReturn($gateway, $orders, 1, 7, pp_order_id(1)));
pin('and is told credits were added', array('ok', 'Payment for order #1 received. Credits added.'), $GLOBALS['flash'][0] ?? null);

$count            = count($http->requests);
$GLOBALS['flash'] = array();
Plugin::settleReturn($gateway, $orders, 1, 7, pp_order_id(1));
pin('a reload of a paid order does not call PayPal again', $count, count($http->requests));
pin('and just shows it paid', array('ok', 'Order #1 is paid.'), $GLOBALS['flash'][0] ?? null);

harness_section('the cancel page');

$cancel = $orders->sent();
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($cancel->getId()), 200, pp_order($cancel, 'PAYER_ACTION_REQUIRED', null));
$GLOBALS['flash'] = array();
pin('an unapproved order is closed', Order::STATUS_FAILED, Plugin::settleCancel($gateway, $orders, $cancel->getId(), 7, pp_order_id($cancel->getId())));
pin('and the buyer is told nothing was charged', array('info', 'You cancelled the PayPal payment for order #' . $cancel->getId() . '. Nothing was charged.'), $GLOBALS['flash'][0] ?? null);

$late = $orders->sent();
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($late->getId()), 200, pp_order($late));
pin('one PayPal says was paid after all is paid', Order::STATUS_PAID, Plugin::settleCancel($gateway, $orders, $late->getId(), 7, pp_order_id($late->getId())));

$GLOBALS['flash'] = array();
$foreign          = $orders->sent();
pin('someone else\'s order is refused', '', Plugin::settleCancel($gateway, $orders, $foreign->getId(), 99, pp_order_id($foreign->getId())));
pin('and stays pending', Order::STATUS_PENDING, $orders->find($foreign->getId())->getStatus());
pin('a token that does not match closes nothing', Order::STATUS_PENDING, Plugin::settleCancel($gateway, $orders, $foreign->getId(), 7, pp_order_id(1)));

harness_section('the job handlers');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->sent();
$gateway = settling_gateway($http, $orders);
$http->on('GET', 'v1/notifications/webhooks-events/WH-JOB-1', 200, event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order), 'WH-JOB-1'));
Plugin::$factory = static fn (): PayPalGateway => $gateway;

Plugin::registerJobs();
check('both job types have a handler', isset($GLOBALS['jobs'][Plugin::JOB_EVENT], $GLOBALS['jobs'][Plugin::JOB_RECONCILE]));

($GLOBALS['jobs'][Plugin::JOB_EVENT])(new Job(array('pk_i_id' => 1), array('event' => 'WH-JOB-1')));
pin('the event job fetches that event from PayPal', 'https://api-m.sandbox.paypal.com/v1/notifications/webhooks-events/WH-JOB-1', $http->lastCall()['url'] ?? null);
pin('and settles its order', Order::STATUS_PAID, $orders->find(1)->getStatus());
check('the gateway was registered for the job', PaymentGatewayRegistry::instance()->get('paypal') === $gateway);

$GLOBALS['billing'] = false;
$threw              = false;
try {
    ($GLOBALS['jobs'][Plugin::JOB_EVENT])(new Job(array('pk_i_id' => 2), array('event' => 'WH-JOB-1')));
} catch (RuntimeException $e) {
    $threw = true;
}
check('with billing off the job throws, so it is retried later', $threw);

$count = count($http->requests);
($GLOBALS['jobs'][Plugin::JOB_RECONCILE])(new Job(array('pk_i_id' => 3), array()));
pin('and the daily check does nothing', $count, count($http->requests));

$GLOBALS['billing'] = true;
$stale              = $orders->sent(9_990_000, 'USD', date('Y-m-d H:i:s', time() - 7200));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($stale->getId()), 200, pp_order($stale));
($GLOBALS['jobs'][Plugin::JOB_RECONCILE])(new Job(array('pk_i_id' => 4), array()));
pin('the daily check job settles a stale order', Order::STATUS_PAID, $orders->find($stale->getId())->getStatus());

harness_section('when a job gives up');

Plugin::jobGaveUp('storage.upload', array(), 'x', 1);
pin('another plugin\'s job sends nothing', array(), $GLOBALS['mails']);
Plugin::jobGaveUp(Plugin::JOB_EVENT, array('event' => 'WH-JOB-9'), 'PayPal answered 500', 42);
pin('a PayPal job mails the site\'s contact address', 'admin@shop.test', $GLOBALS['mails'][0]['to'] ?? null);
check('naming the event, the error and the job', str_contains($GLOBALS['mails'][0]['body'], 'WH-JOB-9') && str_contains($GLOBALS['mails'][0]['body'], 'PayPal answered 500') && str_contains($GLOBALS['mails'][0]['body'], 'job #42'));
Plugin::jobGaveUp(Plugin::JOB_EVENT, array('event' => '<script>'), '<b>', 43);
check('escaped', !str_contains($GLOBALS['mails'][1]['body'], '<script>') && !str_contains($GLOBALS['mails'][1]['body'], '<b>'));

harness_section('the connection check');

$http = new FakeHttp();
$url  = Plugin::webhookUrl();
pin('the webhook URL is core\'s callback route for this gateway', 'https://shop.test/index.php?page=billing&action=callback&gateway=paypal', $url);
$http->on('GET', 'v1/notifications/webhooks/' . HOOK_ID, 200, array('id' => HOOK_ID, 'url' => $url, 'event_types' => array_map(static fn ($n) => array('name' => $n), PayPalGateway::EVENTS)));
$lines = fake_gateway($http, new MemoryOrders())->diagnose($url);
pin('all good', array(array('ok', 'Connected to the PayPal sandbox.'), array('ok', 'The webhook is set up.')), $lines);

$http->on('GET', 'v1/notifications/webhooks/' . HOOK_ID, 200, array('id' => HOOK_ID, 'url' => 'https://old.test/hook', 'event_types' => array(array('name' => 'PAYMENT.CAPTURE.COMPLETED'))));
$lines = fake_gateway($http, new MemoryOrders())->diagnose($url);
pin('a webhook for another URL, missing events, is reported', array('ok', 'warning', 'warning'), array_column($lines, 0));
check('naming the missing events', str_contains($lines[2][1], 'CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED'));
$http->on('GET', 'v1/notifications/webhooks/' . HOOK_ID, 200, array('id' => HOOK_ID, 'url' => $url, 'event_types' => array(array('name' => '*'))));
pin('all events ("*") is enough', 'The webhook is set up.', fake_gateway($http, new MemoryOrders())->diagnose($url)[1][1]);

$http->on('GET', 'v1/notifications/webhooks/' . HOOK_ID, 404, array('name' => 'INVALID_RESOURCE_ID'));
pin('an unknown webhook id is an error', 'error', fake_gateway($http, new MemoryOrders())->diagnose($url)[1][0]);

$http->on('POST', 'v1/oauth2/token', 401, array('error' => 'invalid_client', 'error_description' => 'Client Authentication failed'));
$lines = fake_gateway($http, new MemoryOrders())->diagnose($url);
pin('bad credentials are one error line', array('error'), array_column($lines, 0));
check('that never shows the secret', !str_contains($lines[0][1], fake_config()->secret));

pin('no credentials: one hint, and PayPal is not asked', array('warning'), array_column(fake_gateway(new FakeHttp(), new MemoryOrders(), array('secret' => ''))->diagnose($url), 0));

$http = new FakeHttp();
Plugin::$factory = static fn (): PayPalGateway => fake_gateway($http, new MemoryOrders(), array('webhook' => ''));
ob_start();
Plugin::renderConnection();
$html = (string) ob_get_clean();
check('the settings page renders the check as a list', str_contains($html, '<li class="text-success">Connected to the PayPal sandbox.</li>') && str_contains($html, 'text-warning'));

exit(harness_result());
