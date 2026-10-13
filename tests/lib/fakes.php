<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Stand-ins for PayPal, the token cache and core's order table.
 */

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\http\HttpClient;
use mindstellar\http\HttpException;
use mindstellar\paypal\Config;
use mindstellar\paypal\OrderStore;
use mindstellar\paypal\PayPalGateway;
use mindstellar\paypal\TokenCache;

/** Answers from a script and records every request. The token endpoint answers by default. */
final class FakeHttp implements HttpClient
{
    /** @var array<int,array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = array();

    /** @var array<string,array{status:int,body:string}|HttpException> "METHOD path" => answer */
    public array $routes = array();

    /** @var array<string,array<int,array{status:int,body:string}>> answers used once each, before $routes */
    public array $once = array();

    public int $tokens = 0;

    /** @var array<string,Closure> "METHOD path" => run while that request is in flight */
    public array $during = array();

    public function __construct()
    {
        $this->routes['POST v1/oauth2/token'] = array('status' => 200, 'body' => '');
    }

    public function on(string $method, string $path, int $status, array $body): void
    {
        $this->routes[$method . ' ' . $path] = array('status' => $status, 'body' => json_encode($body));
    }

    public function onceOn(string $method, string $path, int $status, array $body): void
    {
        $this->once[$method . ' ' . $path][] = array('status' => $status, 'body' => json_encode($body));
    }

    public function down(string $method, string $path): void
    {
        $this->routes[$method . ' ' . $path] = new HttpException('PayPal could not be reached: timeout');
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $options['timeout'] ?? null);
        $path = (string) preg_replace('#^https://api-m\.(sandbox\.)?paypal\.com/#', '', $url);
        $key  = $method . ' ' . $path;
        if (isset($this->during[$key])) {
            ($this->during[$key])();
        }

        if (!empty($this->once[$key])) {
            return array_shift($this->once[$key]) + array('headers' => array());
        }
        $answer = $this->routes[$key] ?? array('status' => 404, 'body' => '{"name":"RESOURCE_NOT_FOUND","details":[{"issue":"INVALID_RESOURCE_ID"}]}');
        if ($answer instanceof HttpException) {
            throw $answer;
        }
        if ($key === 'POST v1/oauth2/token' && $answer['body'] === '') {
            $this->tokens++;

            return array('status' => 200, 'headers' => array(), 'body' => json_encode(array('access_token' => 'A21AA' . $this->tokens, 'token_type' => 'Bearer', 'expires_in' => 32400)));
        }

        return $answer + array('headers' => array());
    }

    /** Requests other than token requests. */
    public function calls(): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => !str_ends_with($r['url'], 'v1/oauth2/token')));
    }

    /** The last call's JSON body, decoded. */
    public function lastJson(): array
    {
        $calls = $this->calls();

        return (array) json_decode((string) end($calls)['body'], true);
    }

    public function lastCall(): array
    {
        $calls = $this->calls();

        return end($calls) ?: array();
    }
}

/** Tokens in an array. */
final class MemoryCache implements TokenCache
{
    /** @var array<string,array{0:string,1:int}> key => token, ttl */
    public array $items = array();

    public function get(string $key): ?string
    {
        return $this->items[$key][0] ?? null;
    }

    public function set(string $key, string $token, int $ttl): void
    {
        $this->items[$key] = array($token, $ttl);
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}

/** Orders in an array. */
final class MemoryOrders implements OrderStore
{
    /** @var array<int,Order> */
    public array $orders = array();

    /** @var bool|null true makes setMeta throw, false makes it refuse */
    public ?bool $metaFails = null;

    public function add(int $amount = 9_990_000, string $currency = 'USD', ?string $ref = null, string $status = Order::STATUS_PENDING, string $gateway = 'paypal', string $date = '2026-01-01 00:00:00', array $meta = array()): Order
    {
        $id                = count($this->orders) + 1;
        $this->orders[$id] = new Order($id, 7, $gateway, $ref, $amount, $currency, 100, $status, $meta, $date);

        return $this->orders[$id];
    }

    /** A pending order already sent to PayPal, as createCheckout() leaves it. */
    public function sent(int $amount = 9_990_000, string $currency = 'USD', string $date = '2026-01-01 00:00:00', string $mode = 'sandbox'): Order
    {
        $id = count($this->orders) + 1;

        return $this->add($amount, $currency, pp_order_id($id), Order::STATUS_PENDING, 'paypal', $date, array(PayPalGateway::META_ORDER => pp_order_id($id), PayPalGateway::META_MODE => $mode));
    }

    public function find(int $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function findByRef(string $ref): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->getExternalRef() === $ref && $order->getGateway() === 'paypal') {
                return $order;
            }
        }

        return null;
    }

    /** As core's OrderStore::attachRef(): pending only, unique per gateway, false when nothing changed. */
    public function attachRef(int $orderId, string $ref): bool
    {
        $order = $this->orders[$orderId] ?? null;
        if ($order === null || !$order->isPending() || $order->getExternalRef() === $ref) {
            return false;
        }
        $owner = $this->findByRef($ref);
        if ($owner !== null && $owner->getId() !== $orderId) {
            return false;
        }
        $this->set($orderId, Order::STATUS_PENDING, $ref);

        return true;
    }

    public function setMeta(int $orderId, string $key, $value): bool
    {
        if ($this->metaFails === true) {
            throw new RuntimeException('database is down');
        }
        $o = $this->orders[$orderId] ?? null;
        if ($o === null || $this->metaFails === false) {
            return false;
        }
        $meta = $o->getMeta();
        if ($value === null) {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }
        $this->orders[$orderId] = new Order($o->getId(), $o->getUserId(), $o->getGateway(), $o->getExternalRef(), $o->getAmount(), $o->getCurrency(), $o->getCredits(), $o->getStatus(), $meta, $o->getDate());

        return true;
    }

    public function markFailed(int $orderId): void
    {
        $this->set($orderId, Order::STATUS_FAILED, null);
    }

    public function stalePending(int $seconds, int $limit): array
    {
        return array_values(array_filter($this->orders, static fn (Order $o): bool => $o->isPending()
            && (string) $o->getExternalRef() !== ''
            && strtotime($o->getDate()) <= time() - $seconds));
    }

    public function set(int $id, string $status, ?string $ref): void
    {
        $o                 = $this->orders[$id];
        $this->orders[$id] = new Order($o->getId(), $o->getUserId(), $o->getGateway(), $ref ?? $o->getExternalRef(), $o->getAmount(), $o->getCurrency(), $o->getCredits(), $status, $o->getMeta(), $o->getDate());
    }
}

const SITE    = 'a1b2c3d4e5f60718';
const HOOK_ID = '8PT597110X687430LKGECATA';

function pp_order_id(int $orderId): string
{
    return sprintf('5O190127TN3%06d', $orderId);
}

function pp_capture_id(int $orderId): string
{
    return sprintf('3C679366HH9%06d', $orderId);
}

function fake_config(array $over = array()): Config
{
    $v = $over + array(
        'mode'    => 'sandbox',
        'client'  => 'AYSq3RDGsmBLJE-otTkBtM-jBRd1TCQwFf9RGfwddNXWz0uFU9ztymylOhRS',
        'secret'  => 'EGnHDxD_qRPdaLdZz8iCr8N7_MzF-YHPTkjs6NKYQvQSBngp4PTTVWkPZRbL',
        'webhook' => HOOK_ID,
        'billing' => 'USD',
        'admin'   => true,
    );
    $admin = $v['admin'];

    return new Config(
        $v['mode'],
        $v['client'],
        $v['secret'],
        $v['webhook'],
        'PayPal',
        $v['billing'],
        SITE,
        static fn (int $id): string => 'https://shop.test/index.php?page=route&route=paypal-return&order=' . $id,
        static fn (int $id): string => 'https://shop.test/index.php?page=route&route=paypal-cancel&order=' . $id,
        'https://shop.test/buy',
        static fn (): bool => $admin
    );
}

/**
 * The gateway with its four outside seams replaced: the webhook input comes from
 * $GLOBALS['input'], queued ids go to $GLOBALS['queued'], mails to $GLOBALS['notified'],
 * and settling runs $onSettle, or core's real Billing::handleCallback() when it is null.
 */
class FakeGateway extends PayPalGateway
{
    public ?Closure $onSettle = null;

    public bool $queueDown = false;

    protected function input(): array
    {
        return $GLOBALS['input'];
    }

    protected function settle(array $request): CallbackResult
    {
        return $this->onSettle !== null ? ($this->onSettle)($request) : parent::settle($request);
    }

    protected function queue(string $eventId): void
    {
        if ($this->queueDown) {
            throw new RuntimeException('queue down');
        }
        $GLOBALS['queued'][] = $eventId;
    }

    protected function notify(string $subject, array $lines): void
    {
        $GLOBALS['notified'][] = array($subject, $lines);
    }
}

/**
 * A gateway on fakes. $GLOBALS['settled'] records what reached the settle step.
 */
function fake_gateway(FakeHttp $http, MemoryOrders $orders, array $config = array(), ?Closure $settle = null, ?MemoryCache $cache = null): FakeGateway
{
    $GLOBALS['settled']  = array();
    $GLOBALS['queued']   = array();
    $GLOBALS['notified'] = array();
    $GLOBALS['input']    = array('', array());

    $gateway           = new FakeGateway(fake_config($config), $http, $orders, $cache ?? new MemoryCache());
    $gateway->onSettle = $settle ?? static function (array $request) use ($gateway): CallbackResult {
        $result               = $gateway->handleCallback($request);
        $GLOBALS['settled'][] = $result;

        return $result;
    };

    return $gateway;
}

/**
 * A gateway whose settle step writes the outcome to the in-memory orders, as core would:
 * paid and failed only from pending, refunded only from paid.
 */
function settling_gateway(FakeHttp $http, MemoryOrders $orders, array $config = array()): FakeGateway
{
    $gateway = null;
    $gateway = fake_gateway($http, $orders, $config, static function (array $request) use (&$gateway, $orders): CallbackResult {
        $result               = $gateway->handleCallback($request);
        $GLOBALS['settled'][] = $result;
        $id                   = $result->getOrderId();
        $order                = $id === null ? null : $orders->find($id);
        if ($order === null) {
            return $result;
        }
        $outcome = $result->getOutcome();
        if ($order->isPending() && $outcome === 'paid'
            && $result->getAmount() === $order->getAmount() && $result->getCurrency() === $order->getCurrency()
        ) {
            $orders->set($id, 'paid', $result->getExternalRef());
            $GLOBALS['credited'][] = $id;
        } elseif ($order->isPending() && $outcome === 'failed') {
            $orders->set($id, 'failed', $result->getExternalRef());
        } elseif ($order->isPaid() && $outcome === 'refunded') {
            $orders->set($id, 'refunded', null);
        }

        return $result;
    });
    $GLOBALS['credited'] = array();

    return $gateway;
}

/** A completed capture of the order, as PayPal returns it. */
function pp_capture(Order $order, array $over = array()): array
{
    return $over + array(
        'id'                 => pp_capture_id($order->getId()),
        'status'             => 'COMPLETED',
        'amount'             => array('currency_code' => $order->getCurrency(), 'value' => \mindstellar\paypal\Money::toValue($order->getAmount(), $order->getCurrency())),
        'custom_id'          => (string) $order->getId(),
        'invoice_id'         => SITE . '-' . $order->getId(),
        'supplementary_data' => array('related_ids' => array('order_id' => pp_order_id($order->getId()))),
    );
}

/** A PayPal order for the core order, as a GET or a capture returns it. */
function pp_order(Order $order, string $status = 'COMPLETED', ?array $captures = null, array $over = array()): array
{
    $unit = array(
        'reference_id' => 'order-' . $order->getId(),
        'custom_id'    => (string) $order->getId(),
        'invoice_id'   => SITE . '-' . $order->getId(),
        'amount'       => array('currency_code' => $order->getCurrency(), 'value' => \mindstellar\paypal\Money::toValue($order->getAmount(), $order->getCurrency())),
    );
    if ($captures === null && $status === 'COMPLETED') {
        $captures = array(pp_capture($order));
    }
    if ($captures !== null) {
        $unit['payments'] = array('captures' => $captures);
    }

    return $over + array(
        'id'             => pp_order_id($order->getId()),
        'intent'         => 'CAPTURE',
        'status'         => $status,
        'purchase_units' => array($unit),
        'links'          => array(
            array('href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . pp_order_id($order->getId()), 'rel' => 'payer-action', 'method' => 'GET'),
        ),
    );
}

/** A completed refund of the order's capture. */
function pp_refund(Order $order, array $over = array()): array
{
    $value = \mindstellar\paypal\Money::toValue($order->getAmount(), $order->getCurrency());

    return $over + array(
        'id'                       => '1JU08902781691411',
        'status'                   => 'COMPLETED',
        'amount'                   => array('currency_code' => $order->getCurrency(), 'value' => $value),
        'custom_id'                => (string) $order->getId(),
        'seller_payable_breakdown' => array('total_refunded_amount' => array('currency_code' => $order->getCurrency(), 'value' => $value)),
        'links'                    => array(
            array('href' => 'https://api.sandbox.paypal.com/v2/payments/refunds/1JU08902781691411', 'rel' => 'self'),
            array('href' => 'https://api.sandbox.paypal.com/v2/payments/captures/' . pp_capture_id($order->getId()), 'rel' => 'up'),
        ),
    );
}

function event(string $type, array $resource, string $id = 'WH-2WR32451HC0233532-67976317FL4543714'): array
{
    return array('id' => $id, 'event_version' => '1.0', 'resource_type' => 'capture', 'event_type' => $type, 'resource' => $resource);
}

/** The headers PayPal sends with a webhook. */
function pp_headers(): array
{
    return array(
        'auth-algo'         => 'SHA256withRSA',
        'cert-url'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-1d93a270',
        'transmission-id'   => '69cd13f0-d67a-11e5-baa3-778b53f4ae55',
        'transmission-sig'  => 'lmI95Jx3Y9nhR5SJWlHVIWpg4AgFk7n9bCHSRxbrd8A9zrhdu2rMyFrmz+Zjh3s3boXB07VXCXUZy/UFzUlnGJn0wDugt7FlSvdKeIJenLRemUxYCPVoEZzg9VFNqOa48gMkvF+XTpxBeUx/kWy6B5cp7GkT2+pOowfRK7OaynuxUoKW3JcMWw272VKjLTtTAShncla7tGF+55rxyt2KNZIIqxNMJ48RDZheGU5w1npu9dZHnPgTXB9iomeVRoD8O/jhRpnKsGrDschyNdkeh81BJJMH4Ctc6lnCCquoP/GzCzz33MMsNdid7vL/NIWaCsekQpW26FpWPi/tfj8nLA==',
        'transmission-time' => gmdate('Y-m-d\\TH:i:s\\Z'),
    );
}

/**
 * Make PayPal answer lookups of the order: its PayPal order (completed with a capture unless
 * $status says otherwise) and that capture.
 */
function pp_lookup(FakeHttp $http, Order $order, string $status = 'COMPLETED', ?array $captures = null): void
{
    $paypal = pp_order($order, $status, $captures);
    $http->on('GET', 'v2/checkout/orders/' . pp_order_id($order->getId()), 200, $paypal);
    foreach ($paypal['purchase_units'][0]['payments']['captures'] ?? array() as $capture) {
        $http->on('GET', 'v2/payments/captures/' . $capture['id'], 200, $capture);
    }
}

/** Put a webhook on the fake input, and make PayPal's verify call answer $status. */
function deliver(FakeHttp $http, array $event, string $status = 'SUCCESS'): string
{
    $body             = json_encode($event);
    $GLOBALS['input'] = array($body, pp_headers());
    $http->on('POST', 'v1/notifications/verify-webhook-signature', 200, array('verification_status' => $status));

    return $body;
}
