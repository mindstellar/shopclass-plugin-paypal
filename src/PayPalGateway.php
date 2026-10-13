<?php

/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\paypal;

use Closure;
use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\DashboardLinkGateway;
use mindstellar\billing\Order;
use mindstellar\billing\RefundableGateway;
use mindstellar\paypal\Http\HttpClient;
use mindstellar\paypal\Http\HttpException;
use Throwable;

/**
 * PayPal Checkout (Orders v2) as a Shopclass payment gateway. Orders settle only from verified
 * webhooks or data fetched from PayPal, always through Billing::handleCallback() so core's checks apply.
 */
final class PayPalGateway implements RefundableGateway, DashboardLinkGateway
{
    /** Gateway id stored on every order. */
    public const ID = 'paypal';

    /** Order meta key for the PayPal order id. The order's own reference becomes the capture id once paid. */
    public const META_ORDER = 'paypal_order';

    /** Order meta key for the mode the PayPal order was made in. */
    public const META_MODE = 'paypal_mode';

    /** Request key holding a Verified object. */
    public const TRUSTED = 'paypal_verified';

    /** Webhook events the plugin acts on. Subscribe to these in the PayPal app. */
    public const EVENTS = array(
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.REFUNDED',
    );

    /** A PayPal order the buyer has not approved after this many seconds is closed by the daily check. */
    public const ABANDONED_AFTER = 86400;

    /** PayPal's ids for orders, captures and refunds. */
    private const ID_PATTERN = '/^[A-Z0-9]{10,40}$/';

    private PayPalApi $api;

    /** fn(): array{0:string,1:array<string,string>} -- the raw body and the PAYPAL-* headers. */
    private Closure $input;

    /** fn(array $request): CallbackResult -- settle a verified request through core. */
    private Closure $settle;

    /** fn(string $eventId): void -- queue an event for a later retry. */
    private Closure $queue;

    public function __construct(
        private Config $config,
        HttpClient $http,
        private OrderStore $orders,
        ?TokenCache $cache = null,
        ?Closure $input = null,
        ?Closure $settle = null,
        ?Closure $queue = null
    ) {
        $this->api    = new PayPalApi($http, $config->apiBase(), $config->clientId, $config->secret, $cache ?? new KvTokenCache());
        $this->input  = $input ?? static function (): array {
            $headers = array();
            foreach (array('auth-algo', 'cert-url', 'transmission-id', 'transmission-sig', 'transmission-time') as $name) {
                $headers[$name] = (string) ($_SERVER['HTTP_PAYPAL_' . strtoupper(str_replace('-', '_', $name))] ?? '');
            }

            return array((string) file_get_contents('php://input'), $headers);
        };
        $this->settle = $settle ?? static fn (array $request): CallbackResult => Billing::handleCallback(self::ID, $request);
        $this->queue  = $queue ?? static function (string $eventId): void {
            osc_job_enqueue(Plugin::JOB_EVENT, array('event' => $eventId), array('unique_key' => $eventId));
        };
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return $this->config->name !== '' ? $this->config->name : 'PayPal';
    }

    /**
     * Every currency PayPal takes. Core offers the gateway only for an order in one of them.
     *
     * @return string[]
     */
    public function getSupportedCurrencies(): array
    {
        return Money::SUPPORTED;
    }

    /**
     * Offered at checkout once the active mode is set up and PayPal takes the billing currency.
     * In sandbox mode only a signed-in admin sees it, so a live site cannot hand out free credits.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        if (!$this->hasKeys() || !Money::supported($this->config->billingCurrency)) {
            return false;
        }

        return $this->config->live() || ($this->config->isAdmin !== null && ($this->config->isAdmin)());
    }

    /**
     * Whether the active mode has a client id, a secret and a webhook id. Webhooks and lookups need only this.
     *
     * @return bool
     */
    public function hasKeys(): bool
    {
        return $this->config->clientId !== '' && $this->config->secret !== '' && $this->config->webhookId !== '';
    }

    /**
     * Create a PayPal order for the core order and send the buyer to approve it.
     *
     * @param Order $order
     *
     * @return CheckoutIntent
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        $currency = strtoupper($order->getCurrency());
        $value    = Money::toValue($order->getAmount(), $currency);
        if ($value === null) {
            return $this->checkoutFailed($order, 'amount cannot be charged in ' . $currency);
        }

        $id   = $order->getId();
        $body = array(
            'intent'         => 'CAPTURE',
            'purchase_units' => array(array(
                'reference_id' => 'order-' . $id,
                'custom_id'    => (string) $id,
                'invoice_id'   => $this->config->siteId . '-' . $id,
                'description'  => sprintf(__('%d credits', 'paypal'), $order->getCredits()),
                'amount'       => array('currency_code' => $currency, 'value' => $value),
            )),
            'payment_source' => array('paypal' => array('experience_context' => array(
                'return_url'          => ($this->config->returnUrl)($id),
                'cancel_url'          => ($this->config->cancelUrl)($id),
                'user_action'         => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ))),
        );

        try {
            $paypal = $this->api->createOrder($body, 'order-' . $this->config->siteId . '-' . $id);
        } catch (HttpException $e) {
            return $this->checkoutFailed($order, $e->getMessage());
        }

        $paypalId = (string) ($paypal['id'] ?? '');
        $approve  = self::approveLink($paypal['links'] ?? null);
        if (!self::isId($paypalId) || $approve === null) {
            return $this->checkoutFailed($order, 'unexpected PayPal order');
        }

        // Without the PayPal order on the core order, the return page and the daily check cannot find it.
        // PayPal answers a retry with the same order, which the core order may already hold.
        if (!$this->orders->attachRef($id, $paypalId)) {
            $stored = $this->orders->find($id);
            if ($stored === null || !$stored->isPending() || $stored->getExternalRef() !== $paypalId) {
                return $this->checkoutFailed($order, 'could not store PayPal order ' . $paypalId . ' on the order');
            }
        }
        $this->remember($id, self::META_ORDER, $paypalId);
        $this->remember($id, self::META_MODE, $this->config->mode);

        return CheckoutIntent::redirect($approve);
    }

    /**
     * Read a callback: a webhook PayPal vouches for, or a Verified object from this plugin.
     *
     * @param array $request
     *
     * @return CallbackResult
     */
    public function handleCallback(array $request): CallbackResult
    {
        if (!$this->hasKeys()) {
            // Retryable: PayPal sends the event again once the plugin is set up.
            return CallbackResult::ignored('paypal is not configured', true);
        }

        $verified = $request[self::TRUSTED] ?? null;
        if (!$verified instanceof Verified) {
            return $this->webhook();
        }

        if ($verified->kind === Verified::EVENT) {
            return $this->fromEvent($verified->event);
        }

        $order = $this->ourOrder($verified->paypalOrder, null, $verified->orderId);
        if ($order === null) {
            return CallbackResult::ignored('not an order of this site');
        }

        return $verified->kind === Verified::CAPTURE
            ? $this->capture($verified->paypalOrder, $order)
            : $this->check($verified->paypalOrder, $order, $verified->closeUnpaid);
    }

    /**
     * Refund a paid order in full.
     *
     * @param Order $order
     *
     * @return CallbackResult
     */
    public function refund(Order $order): CallbackResult
    {
        try {
            $capture = $this->captureOf($order);
            if ($capture === null) {
                return CallbackResult::ignored('no PayPal payment found for this order');
            }
            $refund = $this->api->refundCapture($capture, 'refund-' . $this->config->siteId . '-' . $order->getId());
        } catch (HttpException $e) {
            return CallbackResult::ignored(self::short($e->getMessage()));
        }

        $status = (string) ($refund['status'] ?? '');
        $id     = (string) ($refund['id'] ?? '');
        if (!self::isId($id)) {
            return CallbackResult::ignored('PayPal did not accept the refund');
        }
        if ($status === 'COMPLETED') {
            return CallbackResult::refunded($order->getId(), $id);
        }
        if ($status === 'PENDING') {
            // The PAYMENT.CAPTURE.REFUNDED webhook settles the order once PayPal finishes.
            return CallbackResult::ignored('PayPal is still processing the refund; the order updates when it completes.');
        }

        return CallbackResult::ignored('PayPal did not accept the refund' . ($status !== '' ? ' (' . $status . ')' : ''));
    }

    /**
     * The order's payment in the PayPal account's activity, once it is paid.
     *
     * @param Order $order
     *
     * @return string|null
     */
    public function dashboardUrl(Order $order): ?string
    {
        $ref = (string) $order->getExternalRef();
        if (!self::isId($ref) || $ref === $order->meta(self::META_ORDER)) {
            return null;
        }
        if (!in_array($order->getStatus(), array(Order::STATUS_PAID, Order::STATUS_REFUNDED), true)) {
            return null;
        }

        $mode = $order->meta(self::META_MODE);
        $live = $mode === 'live' || ($mode !== 'sandbox' && $this->config->live());

        return 'https://www.' . ($live ? '' : 'sandbox.') . 'paypal.com/activity/payment/' . $ref;
    }

    /**
     * Fetch a webhook event from PayPal and settle it. The queued job runs this.
     *
     * @param string $eventId
     *
     * @return CallbackResult
     * @throws Throwable when PayPal or the database fails, so the job is retried
     */
    public function processEvent(string $eventId): CallbackResult
    {
        if (!self::isEventId($eventId)) {
            return CallbackResult::ignored('malformed event id');
        }

        return ($this->settle)(array(self::TRUSTED => Verified::event($this->api->event($eventId))));
    }

    /**
     * The buyer is back from PayPal: capture the order and settle it.
     *
     * @param string $paypalOrder
     * @param int    $orderId
     *
     * @return CallbackResult
     */
    public function processReturn(string $paypalOrder, int $orderId): CallbackResult
    {
        if (!self::isId($paypalOrder)) {
            return CallbackResult::ignored('malformed PayPal order id');
        }

        return ($this->settle)(array(self::TRUSTED => Verified::capture($paypalOrder, $orderId)));
    }

    /**
     * Look a PayPal order up and settle what it says. $closeUnpaid fails an order the buyer never approved.
     *
     * @param string $paypalOrder
     * @param int    $orderId
     * @param bool   $closeUnpaid
     *
     * @return CallbackResult
     */
    public function processCheck(string $paypalOrder, int $orderId, bool $closeUnpaid): CallbackResult
    {
        if (!self::isId($paypalOrder)) {
            return CallbackResult::ignored('malformed PayPal order id');
        }

        return ($this->settle)(array(self::TRUSTED => Verified::check($paypalOrder, $orderId, $closeUnpaid)));
    }

    /**
     * Check pending orders PayPal never told us about.
     *
     * @param int $olderThan Seconds
     * @param int $limit
     *
     * @return int how many were looked up
     * @throws HttpException when any lookup failed, so the job is retried
     */
    public function reconcile(int $olderThan = 3600, int $limit = 100): int
    {
        $checked = 0;
        $errors  = array();
        foreach ($this->orders->stalePending($olderThan, $limit) as $order) {
            $mode = $order->meta(self::META_MODE);
            if (is_string($mode) && $mode !== $this->config->mode) {
                // Made in the other mode: these credentials cannot see it.
                continue;
            }
            $paypalOrder = (string) ($order->meta(self::META_ORDER) ?? $order->getExternalRef());
            $abandoned   = strtotime($order->getDate()) <= time() - self::ABANDONED_AFTER;
            try {
                $this->processCheck($paypalOrder, $order->getId(), $abandoned);
                $checked++;
            } catch (Throwable $e) {
                $errors[] = '#' . $order->getId() . ': ' . self::short($e->getMessage());
            }
        }
        if ($errors !== array()) {
            throw new HttpException('Some PayPal orders could not be checked: ' . implode('; ', $errors));
        }

        return $checked;
    }

    /**
     * For the settings page: whether the credentials work and the webhook is set up.
     *
     * @param string $webhookUrl This site's webhook URL
     *
     * @return array<int,array{0:string,1:string}> level ('ok', 'warning' or 'error') and message
     */
    public function diagnose(string $webhookUrl): array
    {
        if ($this->config->clientId === '' || $this->config->secret === '') {
            return array(array('warning', __('Add the client id and secret for this mode, then save.', 'paypal')));
        }

        try {
            $this->api->token(true);
        } catch (HttpException $e) {
            return array(array('error', sprintf(__('PayPal refused the client id and secret: %s', 'paypal'), self::short($e->getMessage()))));
        }
        $lines = array(array('ok', $this->config->live()
            ? __('Connected to PayPal (live).', 'paypal')
            : __('Connected to the PayPal sandbox.', 'paypal')));

        if ($this->config->webhookId === '') {
            $lines[] = array('warning', __('No webhook id is set, so PayPal is not offered at checkout yet.', 'paypal'));

            return $lines;
        }

        try {
            $hook = $this->api->webhook($this->config->webhookId);
        } catch (HttpException $e) {
            $lines[] = array('error', __('PayPal does not know this webhook id. Copy it from the same app as the client id.', 'paypal'));

            return $lines;
        }

        $url = (string) ($hook['url'] ?? '');
        if ($url !== $webhookUrl) {
            $lines[] = array('warning', sprintf(__('The webhook points to %s, not to this site.', 'paypal'), $url));
        }
        $names = array();
        foreach ((array) ($hook['event_types'] ?? array()) as $type) {
            $names[] = is_array($type) ? (string) ($type['name'] ?? '') : '';
        }
        $missing = in_array('*', $names, true) ? array() : array_diff(self::EVENTS, $names);
        if ($missing !== array()) {
            $lines[] = array('warning', sprintf(__('The webhook is missing these events: %s', 'paypal'), implode(', ', $missing)));
        }
        if (count($lines) === 1) {
            $lines[] = array('ok', __('The webhook is set up.', 'paypal'));
        }

        return $lines;
    }

    /**
     * The webhook: check the event with PayPal, then settle it.
     *
     * @return CallbackResult
     */
    private function webhook(): CallbackResult
    {
        [$body, $headers] = ($this->input)();
        $event            = json_decode($body, true);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !self::isEventId($event['id']) || !is_string($event['event_type'] ?? null)) {
            return CallbackResult::ignored('malformed event');
        }
        if (!in_array($event['event_type'], self::EVENTS, true)) {
            return CallbackResult::ignored('event not handled');
        }

        try {
            $genuine = $this->api->verifyWebhook($headers, $this->config->webhookId, $body);
        } catch (HttpException $e) {
            // PayPal could not be asked. It sends the event again.
            return CallbackResult::ignored('could not verify: ' . self::short($e->getMessage()), true);
        }
        if (!$genuine) {
            return CallbackResult::ignored('bad signature');
        }

        try {
            $result = ($this->settle)(array(self::TRUSTED => Verified::event($event)));
        } catch (Throwable $e) {
            try {
                ($this->queue)($event['id']);
            } catch (Throwable $ignored) {
                // The queue is down too. PayPal still retries the event.
            }

            return CallbackResult::ignored('could not settle, queued: ' . self::short($e->getMessage()), true);
        }

        return CallbackResult::ignored('settled as ' . $result->getOutcome());
    }

    /**
     * What one verified event means for its order.
     *
     * @param array<string,mixed> $event
     *
     * @return CallbackResult
     */
    private function fromEvent(array $event): CallbackResult
    {
        $resource = $event['resource'] ?? null;
        if (!is_array($resource)) {
            return CallbackResult::ignored('event has no resource');
        }

        switch ($event['event_type'] ?? '') {
            case 'CHECKOUT.ORDER.APPROVED':
                return $this->fromApproved($resource);

            case 'PAYMENT.CAPTURE.COMPLETED':
                return $this->fromCapture($resource);

            case 'PAYMENT.CAPTURE.DENIED':
                return $this->captureDenied($resource);

            case 'PAYMENT.CAPTURE.REFUNDED':
                return $this->fromRefund($resource);

            default:
                return CallbackResult::ignored('event not handled');
        }
    }

    /**
     * The buyer approved but never came back: capture it here.
     *
     * @param array<string,mixed> $paypal The PayPal order
     *
     * @return CallbackResult
     */
    private function fromApproved(array $paypal): CallbackResult
    {
        $paypalId = (string) ($paypal['id'] ?? '');
        $unit     = $paypal['purchase_units'][0] ?? array();
        $order    = $this->ourOrder($paypalId, $unit['custom_id'] ?? null, null);
        if ($order === null || !$this->sameMoney($order, $unit['amount'] ?? null)) {
            return CallbackResult::ignored('not an order of this site');
        }

        return $this->capture($paypalId, $order);
    }

    /**
     * A completed capture pays its order. Core ignores it for an order already paid.
     *
     * @param array<string,mixed> $capture
     *
     * @return CallbackResult
     */
    private function fromCapture(array $capture): CallbackResult
    {
        $order = $this->ourOrder(self::relatedOrder($capture), $capture['custom_id'] ?? null, null);
        if ($order === null) {
            return CallbackResult::ignored('not an order of this site');
        }
        if (($capture['status'] ?? '') !== 'COMPLETED') {
            return CallbackResult::ignored('capture not completed');
        }

        return $this->paidFrom($capture, $order);
    }

    /**
     * A denied capture closes its order unpaid. Core does not compare money here, so this does.
     *
     * @param array<string,mixed> $capture
     *
     * @return CallbackResult
     */
    private function captureDenied(array $capture): CallbackResult
    {
        $order = $this->ourOrder(self::relatedOrder($capture), $capture['custom_id'] ?? null, null);
        if ($order === null || !$this->sameMoney($order, $capture['amount'] ?? null)) {
            return CallbackResult::ignored('not an order of this site');
        }

        return CallbackResult::failed($order->getId(), 'capture denied');
    }

    /**
     * A capture refunded in full reverses its order. A partial refund changes nothing.
     *
     * @param array<string,mixed> $refund
     *
     * @return CallbackResult
     */
    private function fromRefund(array $refund): CallbackResult
    {
        $capture = null;
        foreach ((array) ($refund['links'] ?? array()) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'up'
                && preg_match('#/v2/payments/captures/([A-Z0-9]{10,40})$#', (string) ($link['href'] ?? ''), $m)
            ) {
                $capture = $m[1];
            }
        }
        if ($capture === null) {
            return CallbackResult::ignored('refund names no capture');
        }

        $order  = $this->orders->findByRef($capture);
        $custom = $refund['custom_id'] ?? null;
        if ($order === null || (is_string($custom) && $custom !== (string) $order->getId())) {
            return CallbackResult::ignored('not an order of this site');
        }
        if (($refund['status'] ?? '') !== 'COMPLETED') {
            return CallbackResult::ignored('refund not completed');
        }

        $total = $refund['seller_payable_breakdown']['total_refunded_amount'] ?? $refund['amount'] ?? null;
        if (!$this->sameMoney($order, $total)) {
            return CallbackResult::ignored('partial refund');
        }

        return CallbackResult::refunded($order->getId(), (string) ($refund['id'] ?? ''));
    }

    /**
     * Capture a PayPal order and settle the core order from PayPal's answer. Capturing again is
     * safe: the same PayPal-Request-Id gets the same answer, and an order already captured is looked up.
     *
     * @param string $paypalId
     * @param Order  $order
     *
     * @return CallbackResult
     * @throws HttpException when PayPal cannot be reached or fails
     */
    private function capture(string $paypalId, Order $order): CallbackResult
    {
        if (!$order->isPending()) {
            // Never take money for an order core would not credit.
            return CallbackResult::ignored('order is no longer pending');
        }

        try {
            $paypal = $this->api->captureOrder($paypalId, 'capture-' . $this->config->siteId . '-' . $order->getId());
        } catch (HttpException $e) {
            if (str_contains($e->getMessage(), 'ORDER_ALREADY_CAPTURED')) {
                $paypal = $this->api->order($paypalId);
            } elseif ($e->getCode() >= 400 && $e->getCode() < 500 && $e->getCode() !== 401) {
                // Not approved yet, or the buyer's funding was declined. The order stays pending.
                return CallbackResult::ignored('PayPal refused the capture: ' . self::short($e->getMessage()));
            } else {
                throw $e;
            }
        }

        return $this->fromPayPalOrder($paypal, $order, false);
    }

    /**
     * Look the PayPal order up and settle what it says; an approved one is captured.
     *
     * @param string $paypalId
     * @param Order  $order
     * @param bool   $closeUnpaid
     *
     * @return CallbackResult
     * @throws HttpException when PayPal cannot be reached or fails
     */
    private function check(string $paypalId, Order $order, bool $closeUnpaid): CallbackResult
    {
        if (!$order->isPending()) {
            return CallbackResult::ignored('order is no longer pending');
        }

        try {
            $paypal = $this->api->order($paypalId);
        } catch (HttpException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            // PayPal drops orders nobody approved.
            return $closeUnpaid
                ? CallbackResult::failed($order->getId(), 'PayPal no longer has this order')
                : CallbackResult::ignored('PayPal does not have this order');
        }

        if (($paypal['status'] ?? '') === 'APPROVED') {
            return $this->sameMoney($order, $paypal['purchase_units'][0]['amount'] ?? null)
                ? $this->capture($paypalId, $order)
                : CallbackResult::ignored('not an order of this site');
        }

        return $this->fromPayPalOrder($paypal, $order, $closeUnpaid);
    }

    /**
     * What a full PayPal order, from a capture or a lookup, means for the core order.
     *
     * @param array<string,mixed> $paypal
     * @param Order               $order
     * @param bool                $closeUnpaid
     *
     * @return CallbackResult
     */
    private function fromPayPalOrder(array $paypal, Order $order, bool $closeUnpaid): CallbackResult
    {
        $captures = $paypal['purchase_units'][0]['payments']['captures'] ?? array();
        $captures = is_array($captures) ? array_filter($captures, 'is_array') : array();
        $statuses = array_map(static fn (array $c): string => (string) ($c['status'] ?? ''), $captures);

        foreach ($captures as $capture) {
            if (in_array($capture['status'] ?? '', array('COMPLETED', 'PARTIALLY_REFUNDED'), true)) {
                return $this->paidFrom($capture, $order);
            }
        }
        if (in_array('PENDING', $statuses, true)) {
            // An e-check or a review. PAYMENT.CAPTURE.COMPLETED or DENIED follows.
            return CallbackResult::ignored('payment is pending at PayPal');
        }
        if (array_intersect($statuses, array('DECLINED', 'FAILED')) !== array()) {
            return CallbackResult::failed($order->getId(), 'payment declined');
        }

        $status = (string) ($paypal['status'] ?? '');
        if ($status === 'VOIDED') {
            return CallbackResult::failed($order->getId(), 'PayPal order voided');
        }
        if ($closeUnpaid && in_array($status, array('CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED'), true)) {
            return CallbackResult::failed($order->getId(), 'buyer did not approve');
        }

        return CallbackResult::ignored('not paid yet');
    }

    /**
     * A paid result from a completed capture, with PayPal's own figures for core to check.
     *
     * @param array<string,mixed> $capture
     * @param Order               $order
     *
     * @return CallbackResult
     */
    private function paidFrom(array $capture, Order $order): CallbackResult
    {
        $id    = (string) ($capture['id'] ?? '');
        $money = Money::fromAmount($capture['amount'] ?? null);
        if (!self::isId($id) || $money === null) {
            return CallbackResult::ignored('malformed capture');
        }

        return CallbackResult::paid($order->getId(), $id, $money[0], $money[1]);
    }

    /**
     * The core order a PayPal order was made for, when it is this site's and a PayPal order.
     * The PayPal order id must be the one stored on it, which another site's events never match.
     *
     * @param string   $paypalId
     * @param mixed    $customId The custom_id PayPal echoes back, when present
     * @param int|null $expected The order the caller expects, if any
     *
     * @return Order|null
     */
    private function ourOrder(string $paypalId, $customId, ?int $expected): ?Order
    {
        if (!self::isId($paypalId)) {
            return null;
        }
        if ($customId !== null && (!is_string($customId) || !preg_match('/^[1-9][0-9]{0,18}$/', $customId))) {
            return null;
        }

        $id    = $expected ?? ($customId !== null ? (int) $customId : null);
        $order = $id !== null ? $this->orders->find($id) : $this->orders->findByRef($paypalId);
        if ($order === null || $order->getGateway() !== self::ID) {
            return null;
        }
        if ($customId !== null && $customId !== (string) $order->getId()) {
            return null;
        }
        if ($order->meta(self::META_ORDER) !== $paypalId && $order->getExternalRef() !== $paypalId) {
            return null;
        }

        return $order;
    }

    /**
     * Whether a PayPal amount is the order's amount and currency.
     *
     * @param Order $order
     * @param mixed $amount
     *
     * @return bool
     */
    private function sameMoney(Order $order, $amount): bool
    {
        $money = Money::fromAmount($amount);

        return $money !== null
            && $money[0] === $order->getAmount()
            && $money[1] === strtoupper($order->getCurrency());
    }

    /**
     * The capture behind a paid order: its reference, or a lookup when it still holds the PayPal order id.
     *
     * @param Order $order
     *
     * @return string|null
     * @throws HttpException
     */
    private function captureOf(Order $order): ?string
    {
        $ref = (string) $order->getExternalRef();
        if (!self::isId($ref)) {
            return null;
        }
        if ($ref !== $order->meta(self::META_ORDER)) {
            return $ref;
        }

        foreach ((array) ($this->api->order($ref)['purchase_units'][0]['payments']['captures'] ?? array()) as $capture) {
            if (is_array($capture) && in_array($capture['status'] ?? '', array('COMPLETED', 'PARTIALLY_REFUNDED'), true)) {
                $id = (string) ($capture['id'] ?? '');

                return self::isId($id) ? $id : null;
            }
        }

        return null;
    }

    /**
     * Store a meta value. Failing to is logged; the order still works through its reference.
     *
     * @param int    $orderId
     * @param string $key
     * @param string $value
     *
     * @return void
     */
    private function remember(int $orderId, string $key, string $value): void
    {
        try {
            if (!$this->orders->setMeta($orderId, $key, $value)) {
                error_log('PayPal: could not store ' . $key . ' on order #' . $orderId);
            }
        } catch (Throwable $e) {
            error_log('PayPal: could not store ' . $key . ' on order #' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * The link PayPal wants the buyer sent to, when it is an https PayPal address.
     *
     * @param mixed $links
     *
     * @return string|null
     */
    private static function approveLink($links): ?string
    {
        foreach (is_array($links) ? $links : array() as $link) {
            if (!is_array($link) || !in_array($link['rel'] ?? '', array('payer-action', 'approve'), true)) {
                continue;
            }
            $href = (string) ($link['href'] ?? '');
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if (str_starts_with($href, 'https://') && ($host === 'paypal.com' || str_ends_with($host, '.paypal.com'))) {
                return $href;
            }
        }

        return null;
    }

    /**
     * The PayPal order id a capture belongs to.
     *
     * @param array<string,mixed> $capture
     *
     * @return string
     */
    private static function relatedOrder(array $capture): string
    {
        return (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');
    }

    private static function isId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    private static function isEventId(string $id): bool
    {
        return preg_match('/^WH-[A-Z0-9-]{1,120}$/', $id) === 1;
    }

    /**
     * Close the order and send the buyer back to choose again.
     *
     * @param Order  $order
     * @param string $reason
     *
     * @return CheckoutIntent
     */
    private function checkoutFailed(Order $order, string $reason): CheckoutIntent
    {
        error_log('PayPal: checkout for order #' . $order->getId() . ' failed: ' . self::short($reason));
        $this->orders->markFailed($order->getId());
        if (function_exists('osc_add_flash_error_message')) {
            osc_add_flash_error_message(__('PayPal payment could not be started. Please try again later.', 'paypal'));
        }

        return CheckoutIntent::redirect($this->config->buyUrl);
    }

    /**
     * One line, at most 200 characters, for a log or the admin.
     *
     * @param string $message
     *
     * @return string
     */
    private static function short(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return strlen($message) > 200 ? substr($message, 0, 197) . '...' : $message;
    }
}
