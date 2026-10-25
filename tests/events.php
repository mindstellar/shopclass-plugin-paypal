<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The webhook: header checks, verification, settling from PayPal's own answer, payments
 * for orders already closed, and idempotency.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\paypal\PayPalApi;
use mindstellar\paypal\PayPalGateway;
use mindstellar\paypal\Verified;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->sent();

$decide = static fn (array $event): CallbackResult => $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event($event)));

harness_section('events are settled from PayPal\'s answer, not the event body');

pp_lookup($http, $order);
$forged = pp_capture($order, array('id' => '9AAAAAAAAAAAAAAAA', 'amount' => array('currency_code' => 'USD', 'value' => '999.00')));
$r      = $decide(event('PAYMENT.CAPTURE.COMPLETED', $forged));
pin('a completed capture looks the PayPal order up', 'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . pp_order_id(1), $http->lastCall()['url']);
pin('and is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('with PayPal\'s capture id, amount and currency, not the event\'s', array(1, pp_capture_id(1), 9_990_000, 'USD'), array($r->getOrderId(), $r->getExternalRef(), $r->getAmount(), $r->getCurrency()));

pp_lookup($http, $order, 'COMPLETED', array(pp_capture($order, array('status' => 'PENDING'))));
pin('a capture PayPal still holds waits, whatever the event says', 'payment is pending at PayPal', $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order)))->getMessage());

pp_lookup($http, $order, 'COMPLETED', array(pp_capture($order, array('status' => 'DECLINED'))));
pin('a denied capture fails the order', CallbackResult::OUTCOME_FAILED, $decide(event('PAYMENT.CAPTURE.DENIED', pp_capture($order, array('status' => 'DECLINED'))))->getOutcome());
pp_lookup($http, $order);
pin('a denial PayPal does not confirm pays instead', CallbackResult::OUTCOME_PAID, $decide(event('PAYMENT.CAPTURE.DENIED', pp_capture($order, array('status' => 'DECLINED'))))->getOutcome());

$http->on('GET', 'v2/checkout/orders/' . pp_order_id(1), 200, pp_order($order, 'APPROVED', null));
$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order));
$r = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($order, 'APPROVED', null)));
pin('an approved order the buyer never came back from is captured', 'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . pp_order_id(1) . '/capture', $http->lastCall()['url']);
pin('and paid', array(CallbackResult::OUTCOME_PAID, pp_capture_id(1)), array($r->getOutcome(), $r->getExternalRef()));

$http->on('GET', 'v2/checkout/orders/' . pp_order_id(1), 200, pp_order($order, 'APPROVED', null, array('purchase_units' => array(array('custom_id' => '1', 'amount' => array('currency_code' => 'USD', 'value' => '0.01'))))));
$count = count($http->requests);
$r     = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($order, 'APPROVED', null)));
pin('an approval PayPal shows for another amount is not captured', array(CallbackResult::OUTCOME_IGNORED, $count + 1), array($r->getOutcome(), count($http->requests)));

$http->on('GET', 'v2/checkout/orders/' . pp_order_id(1), 200, pp_order($order, 'APPROVED', null, array('purchase_units' => array(array('custom_id' => '1', 'amount' => array('currency_code' => 'EUR', 'value' => '9.99'))))));
$count = count($http->requests);
$r     = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($order, 'APPROVED', null)));
pin('nor one for the same figure in another currency', array(CallbackResult::OUTCOME_IGNORED, $count + 1), array($r->getOutcome(), count($http->requests)));

$http->on('GET', 'v2/checkout/orders/' . pp_order_id(1), 200, pp_order($order, 'COMPLETED', null, array('id' => '9ZZ99999999999999')));
$count = count($http->requests);
$r     = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order)));
pin('PayPal answering with another order settles nothing', array('PayPal answered with another order', $count + 1), array($r->getMessage(), count($http->requests)));

$refOnly = $orders->add(9_990_000, 'USD', pp_order_id(count($orders->orders) + 1));
pp_lookup($http, $refOnly);
pin('an order holding its PayPal order only as its reference, no meta, still settles', array(CallbackResult::OUTCOME_PAID, $refOnly->getId()), array(($r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($refOnly))))->getOutcome(), $r->getOrderId()));

$paid = $orders->sent();
$orders->set($paid->getId(), 'paid', pp_capture_id($paid->getId()));
$http->on('GET', 'v2/checkout/orders/' . pp_order_id($paid->getId()), 200, pp_order($paid, 'APPROVED', null));
$r = $decide(event('CHECKOUT.ORDER.APPROVED', pp_order($paid, 'APPROVED', null)));
pin('an approval for an order already paid captures nothing', 'order is no longer pending', $r->getMessage());
check('no capture was sent', array_filter($http->calls(), static fn ($c) => str_ends_with($c['url'], pp_order_id($paid->getId()) . '/capture')) === array());

$refundable = $orders->find($paid->getId());
$capPath    = 'v2/payments/captures/' . pp_capture_id($paid->getId());
$http->on('GET', $capPath, 200, pp_capture($refundable, array('status' => 'REFUNDED')));
$r = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable)));
pin('a refund PayPal confirms in full refunds the order', array(CallbackResult::OUTCOME_REFUNDED, $paid->getId()), array($r->getOutcome(), $r->getOrderId()));
pin('the capture was looked up', 'https://api-m.sandbox.paypal.com/' . $capPath, $http->lastCall()['url']);
$http->on('GET', $capPath, 200, pp_capture($refundable, array('status' => 'REFUNDED', 'id' => '7XX77777777777777')));
pin('a capture lookup that answers with another capture is ignored', 'not refunded in full', $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable)))->getMessage());
$http->on('GET', $capPath, 200, pp_capture($refundable, array('status' => 'PARTIALLY_REFUNDED')));
pin('a partial refund changes nothing, even if the event claims the full amount', 'not refunded in full', $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable)))->getMessage());
$r = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable, array('custom_id' => '1'))));
pin('a refund whose custom id names another order is refused', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.REFUNDED', pp_refund($refundable, array('links' => array()))));
pin('so is one that names no capture', 'refund names no capture', $r->getMessage());

pin('anything else is ignored', 'event not handled', $decide(event('PAYMENT.CAPTURE.PENDING', pp_capture($order)))->getMessage());

harness_section('orders that are not ours');

$count = count($http->requests);
$r     = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('supplementary_data' => array('related_ids' => array('order_id' => '9ZZ99999999999999'))))));
pin('another site on the same PayPal account (same order number, other PayPal order)', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('custom_id' => '2'))));
pin('a custom id that disagrees with the PayPal order', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('custom_id' => '1 OR 1=1'))));
pin('a custom id that is not a number', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$test = $orders->add(9_990_000, 'USD', pp_order_id(count($orders->orders) + 1), 'pending', 'test');
pin('an order of another gateway', CallbackResult::OUTCOME_IGNORED, $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($test)))->getOutcome());
pin('an order that does not exist', CallbackResult::OUTCOME_IGNORED, $decide(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order, array('custom_id' => '999'))))->getOutcome());
pin('none of these asked PayPal', $count, count($http->requests));

harness_section('a payment for an order closed meanwhile');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$order   = $orders->sent();
$refund  = 'v2/payments/captures/' . pp_capture_id(1) . '/refund';
$http->on('POST', 'v2/checkout/orders/' . pp_order_id(1) . '/capture', 201, pp_order($order));
$http->on('POST', $refund, 201, array('id' => '1JU08902781691499', 'status' => 'COMPLETED'));
// The buyer cancels in another tab while PayPal is capturing.
$http->during['POST v2/checkout/orders/' . pp_order_id(1) . '/capture'] = static function () use ($orders): void {
    $orders->set(1, 'failed', null);
};

$r = $gateway->processReturn(pp_order_id(1), 1);
pin('the capture is not reported as paid', 'payment for a closed order; refunded', $r->getMessage());
pin('it is refunded at PayPal at once', 'https://api-m.sandbox.paypal.com/' . $refund, $http->lastCall()['url']);
pin('with a request id that is the same every time', 'closed-' . SITE . '-1', $http->lastCall()['headers']['PayPal-Request-Id']);
pin('the order stays closed and nothing is credited', array('failed', array()), array($orders->find(1)->getStatus(), $GLOBALS['credited']));
pin('the admin is told', 1, count($GLOBALS['notified']));
check('naming the order and the capture', str_contains(implode(' ', $GLOBALS['notified'][0][1]), '#1') && str_contains(implode(' ', $GLOBALS['notified'][0][1]), pp_capture_id(1)));

unset($http->during['POST v2/checkout/orders/' . pp_order_id(1) . '/capture']);
pp_lookup($http, $order);
$count = count($http->calls());
$r     = $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order)))));
pin('the COMPLETED webhook that follows does not refund or mail again', array('payment for a closed order was already handled', 1, 1), array($r->getMessage(), count($http->calls()) - $count, count($GLOBALS['notified'])));

$late = $orders->sent();
$orders->set($late->getId(), 'cancelled', null);
pp_lookup($http, $late);
$http->on('POST', 'v2/payments/captures/' . pp_capture_id($late->getId()) . '/refund', 201, array('id' => '1JU08902781691498', 'status' => 'PENDING'));
$r = $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($late)))));
pin('a COMPLETED capture for an order already closed is refunded too', 'payment for a closed order; refunded', $r->getMessage());
pin('and nothing is credited', array(), $GLOBALS['credited']);

$down = $orders->sent();
$orders->set($down->getId(), 'failed', null);
pp_lookup($http, $down);
$http->down('POST', 'v2/payments/captures/' . pp_capture_id($down->getId()) . '/refund');
$threw = false;
try {
    $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($down)))));
} catch (\mindstellar\http\HttpException $e) {
    $threw = true;
}
check('PayPal down for the refund throws, so the event is queued and retried', $threw);
pin('without telling the admin yet', 2, count($GLOBALS['notified']));

$refused = $orders->sent();
$orders->set($refused->getId(), 'failed', null);
pp_lookup($http, $refused);
$http->on('POST', 'v2/payments/captures/' . pp_capture_id($refused->getId()) . '/refund', 422, array('name' => 'UNPROCESSABLE_ENTITY', 'details' => array(array('issue' => 'REFUND_NOT_ALLOWED'))));
$r = $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($refused)))));
pin('a refund PayPal refuses is reported to the admin to do by hand', array('payment for a closed order; refund refused', 3), array($r->getMessage(), count($GLOBALS['notified'])));
check('with PayPal\'s reason', str_contains(implode(' ', $GLOBALS['notified'][2][1]), 'REFUND_NOT_ALLOWED'));
$count = count($http->calls());
$r     = $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($refused), 'WH-REFUSED-AGAIN'))));
pin('a second delivery after a refusal sends no refund and no mail', array('payment for a closed order was already handled', 3, 1), array($r->getMessage(), count($GLOBALS['notified']), count($http->calls()) - $count));

foreach (array('FAILED', 'CANCELLED', '') as $status) {
    $odd = $orders->sent();
    $orders->set($odd->getId(), 'failed', null);
    pp_lookup($http, $odd);
    $http->on('POST', 'v2/payments/captures/' . pp_capture_id($odd->getId()) . '/refund', 201, array('id' => '1JU08902781691497', 'status' => $status));
    $threw = false;
    try {
        $gateway->handleCallback(array(PayPalGateway::TRUSTED => Verified::event(event('PAYMENT.CAPTURE.COMPLETED', pp_capture($odd)))));
    } catch (\mindstellar\http\HttpException $e) {
        $threw = true;
    }
    pin('a refund PayPal answers ' . json_encode($status) . ' for is retried: throws, no meta, no mail', array(true, null, 3), array($threw, $orders->find($odd->getId())->meta(PayPalGateway::META_CLOSED_REFUND), count($GLOBALS['notified'])));
}

harness_section('the webhook');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->sent();
$gateway = settling_gateway($http, $orders);
$paidEvt = event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order));
pp_lookup($http, $order);

deliver($http, $paidEvt);
$r = $gateway->handleCallback(array());
pin('a verified event is settled', array('paid'), array_map(static fn ($x) => $x->getOutcome(), $GLOBALS['settled']));
check('and the route answers 200', !$r->isRetryable());
$verify = current(array_filter($http->calls(), static fn ($c) => str_ends_with($c['url'], 'verify-webhook-signature')));
pin('PayPal was asked with this site\'s webhook id', HOOK_ID, json_decode($verify['body'], true)['webhook_id']);
pin('with a short timeout', PayPalApi::VERIFY_TIMEOUT, $verify['timeout']);
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
pin('it is credited once', array(1), $GLOBALS['credited']);
pin('and a paid order is never refunded as closed', 0, count(array_filter($http->calls(), static fn ($c) => str_ends_with($c['url'], '/refund'))));

harness_section('refused before anything is settled');

$GLOBALS['settled'] = array();
deliver($http, $paidEvt, 'FAILURE');
$r = $gateway->handleCallback(array());
pin('a delivery PayPal does not vouch for is ignored', 'bad signature', $r->getMessage());
check('and never retried', !$r->isRetryable());
pin('nothing settled', array(), $GLOBALS['settled']);

$GLOBALS['input'] = array(json_encode($paidEvt), pp_headers());
pin('a forged trust key in the request is not trusted', 'bad signature', $gateway->handleCallback(array('raw' => $paidEvt, PayPalGateway::TRUSTED => 'yes'))->getMessage());
pin('still nothing settled', array(), $GLOBALS['settled']);

// PayPal would vouch for all of these, so only the local check can refuse them.
$http->on('POST', 'v1/notifications/verify-webhook-signature', 200, array('verification_status' => 'SUCCESS'));
$count = count($http->requests);
$bad   = array(
    'without the transmission headers' => array(),
    'another algorithm'                 => array('auth-algo' => 'SHA1withRSA') + pp_headers(),
    'a certificate off PayPal\'s hosts' => array('cert-url' => 'https://evil.test/v1/notifications/certs/CERT-1') + pp_headers(),
    'a lookalike host'                  => array('cert-url' => 'https://api.paypal.com.evil.test/v1/notifications/certs/CERT-1') + pp_headers(),
    'a certificate on another port'     => array('cert-url' => 'https://api.paypal.com:8443/v1/notifications/certs/CERT-1') + pp_headers(),
    'a certificate with a user name'    => array('cert-url' => 'https://x@api.paypal.com/v1/notifications/certs/CERT-1') + pp_headers(),
    'a certificate over http'           => array('cert-url' => 'http://api.paypal.com/v1/notifications/certs/CERT-1') + pp_headers(),
    'the api-m host is not where PayPal serves certificates' => array('cert-url' => 'https://api-m.paypal.com/v1/notifications/certs/CERT-1') + pp_headers(),
    'a transmission eleven minutes old' => array('transmission-time' => gmdate('Y-m-d\TH:i:s\Z', time() - 660)) + pp_headers(),
    'one from the future'               => array('transmission-time' => gmdate('Y-m-d\TH:i:s\Z', time() + 660)) + pp_headers(),
    'a time that is not a time'         => array('transmission-time' => 'yesterday-ish') + pp_headers(),
);
foreach ($bad as $label => $headers) {
    $GLOBALS['input'] = array(json_encode($paidEvt), $headers);
    $r                = $gateway->handleCallback(array());
    pin('refused, not retried: ' . $label, array('bad signature', false), array($r->getMessage(), $r->isRetryable()));
}
pin('none of these asked PayPal', $count, count($http->requests));
check('a live certificate host and nine minutes of skew are fine', PayPalApi::plausibleHeaders(array('cert-url' => 'https://api.paypal.com/v1/notifications/certs/CERT-1', 'transmission-time' => gmdate('Y-m-d\TH:i:s\Z', time() - 540)) + pp_headers()));

deliver($http, event('PAYMENT.SALE.COMPLETED', pp_capture($order)));
pin('an event type we do not handle is ignored', 'event not handled', $gateway->handleCallback(array())->getMessage());
$GLOBALS['input'] = array('not json', pp_headers());
pin('a body that is not an event is ignored', 'malformed event', $gateway->handleCallback(array())->getMessage());
deliver($http, event('PAYMENT.CAPTURE.COMPLETED', pp_capture($order), 'evt_1'));
pin('so is a malformed event id', 'malformed event', $gateway->handleCallback(array())->getMessage());

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

$noQueue = fake_gateway($http, $orders, array(), static function (array $r): CallbackResult {
    throw new RuntimeException('db down');
});
$noQueue->queueDown = true;
deliver($http, $paidEvt);
check('even when the queue is down too, PayPal is told to retry', $noQueue->handleCallback(array())->isRetryable());

$GLOBALS['enqueued'] = array();
function osc_job_enqueue($type, $payload = array(), $options = array())
{
    $GLOBALS['enqueued'][] = array($type, $payload, $options);

    return 1;
}
/** The fake, but queueing through the gateway's own queue(). */
final class RealQueueGateway extends FakeGateway
{
    protected function queue(string $eventId): void
    {
        PayPalGateway::queue($eventId);
    }
}
$real           = new RealQueueGateway(fake_config(), $http, $orders, new MemoryCache());
$real->onSettle = static function (array $r): CallbackResult {
    throw new RuntimeException('db down');
};
deliver($http, $paidEvt);
$real->handleCallback(array());
pin('the real queue holds the event id alone, keyed on it so a redelivery folds in', array(array(\mindstellar\paypal\Plugin::JOB_EVENT, array('event' => $paidEvt['id']), array('unique_key' => $paidEvt['id']))), $GLOBALS['enqueued']);

harness_section('not configured');

$bare = fake_gateway($http, $orders, array('mode' => 'live', 'webhook' => ''));
deliver($http, $paidEvt);
$r = $bare->handleCallback(array());
pin('without a webhook id nothing is settled', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
check('but PayPal keeps the event for later', $r->isRetryable());

exit(harness_result());
