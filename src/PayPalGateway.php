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

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\DashboardLinkGateway;
use mindstellar\billing\Order;
use mindstellar\billing\RefundableGateway;
use mindstellar\http\HttpClient;
use mindstellar\http\HttpException;
use Throwable;

/**
 * PayPal Checkout (Orders v2) as a Shopclass payment gateway. Orders settle only from verified
 * webhooks or data fetched from PayPal, always through Billing::handleCallback() so core's checks apply.
 * Tests extend it to replace the four protected methods that touch the request, core and mail.
 */
class PayPalGateway implements RefundableGateway, DashboardLinkGateway
{
    /** Gateway id stored on every order. */
    public const ID = 'paypal';

    /** Order meta key for the PayPal order id. The order's own reference becomes the capture id once paid. */
    public const META_ORDER = 'paypal_order';

    /** Order meta key for the mode the PayPal order was made in. */
    public const META_MODE = 'paypal_mode';

    /** Order meta key set once a payment for an order already closed has been refunded. */
    public const META_CLOSED_REFUND = 'paypal_closed_refund';

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

    public function __construct(
        private Config $config,
        HttpClient $http,
        private OrderStore $orders,
        ?TokenCache $cache = null
    ) {
        $this->api = new PayPalApi($http, $config->apiBase(), $config->clientId, $config->secret, $cache ?? new KvTokenCache());
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
                'description'  => self::describe($order),
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

        return $this->settle(array(self::TRUSTED => Verified::event($this->api->event($eventId))));
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

        return $this->settle(array(self::TRUSTED => Verified::capture($paypalOrder, $orderId)));
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

        return $this->settle(array(self::TRUSTED => Verified::check($paypalOrder, $orderId, $closeUnpaid)));
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
     * The webhook: check the headers here and the event with PayPal, then settle it.
     *
     * @return CallbackResult
     */
    private function webhook(): CallbackResult
    {
        [$body, $headers] = $this->input();
        $event            = json_decode($body, true);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !self::isEventId($event['id']) || !is_string($event['event_type'] ?? null)) {
            return CallbackResult::ignored('malformed event');
        }
        if (!in_array($event['event_type'], self::EVENTS, true)) {
            return CallbackResult::ignored('event not handled');
        }
        if (!PayPalApi::plausibleHeaders($headers)) {
            return CallbackResult::ignored('bad signature');
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
            $result = $this->settle(array(self::TRUSTED => Verified::event($event)));
        } catch (Throwable $e) {
            try {
                $this->queue($event['id']);
            } catch (Throwable $ignored) {
                // The queue is down too. PayPal still retries the event.
            }

            return CallbackResult::ignored('could not settle, queued: ' . self::short($e->getMessage()), true);
        }

        return CallbackResult::ignored('settled as ' . $result->getOutcome());
    }

    /**
     * What one verified event means for its order. Only the ids are read from the event;
     * the state of the payment is asked of PayPal.
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
                return $this->fromOrderEvent((string) ($resource['id'] ?? ''), $resource['purchase_units'][0]['custom_id'] ?? null);

            case 'PAYMENT.CAPTURE.COMPLETED':
            case 'PAYMENT.CAPTURE.DENIED':
                return $this->fromOrderEvent(self::relatedOrder($resource), $resource['custom_id'] ?? null);

            case 'PAYMENT.CAPTURE.REFUNDED':
                return $this->fromRefund($resource);

            default:
                return CallbackResult::ignored('event not handled');
        }
    }

    /**
     * An event about one PayPal order: look the order up at PayPal and settle what it says.
     * An approved order is captured, unless the core order is closed.
     *
     * @param string $paypalId
     * @param mixed  $customId
     *
     * @return CallbackResult
     * @throws HttpException when PayPal cannot be reached or fails
     */
    private function fromOrderEvent(string $paypalId, $customId): CallbackResult
    {
        $order = $this->ourOrder($paypalId, $customId, null);
        if ($order === null) {
            return CallbackResult::ignored('not an order of this site');
        }

        return $this->check($paypalId, $order, false);
    }

    /**
     * A capture refunded in full reverses its order. The capture's state comes from PayPal;
     * a partial refund changes nothing.
     *
     * @param array<string,mixed> $refund
     *
     * @return CallbackResult
     * @throws HttpException when PayPal cannot be reached or fails
     */
    private function fromRefund(array $refund): CallbackResult
    {
        $captureId = null;
        foreach ((array) ($refund['links'] ?? array()) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'up'
                && preg_match('#/v2/payments/captures/([A-Z0-9]{10,40})$#', (string) ($link['href'] ?? ''), $m)
            ) {
                $captureId = $m[1];
            }
        }
        if ($captureId === null) {
            return CallbackResult::ignored('refund names no capture');
        }

        $order  = $this->orders->findByRef($captureId);
        $custom = $refund['custom_id'] ?? null;
        if ($order === null || (is_string($custom) && $custom !== (string) $order->getId())) {
            return CallbackResult::ignored('not an order of this site');
        }

        $capture = $this->api->capture($captureId);
        if (($capture['id'] ?? '') !== $captureId || ($capture['status'] ?? '') !== 'REFUNDED') {
            return CallbackResult::ignored('not refunded in full');
        }
        $refundId = (string) ($refund['id'] ?? '');

        return CallbackResult::refunded($order->getId(), self::isId($refundId) ? $refundId : null);
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
     * Look the PayPal order up and settle what it says; an approved one is captured while the order is pending.
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
        try {
            $paypal = $this->api->order($paypalId);
        } catch (HttpException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            // PayPal drops orders nobody approved.
            return $closeUnpaid && $order->isPending()
                ? CallbackResult::failed($order->getId(), 'PayPal no longer has this order')
                : CallbackResult::ignored('PayPal does not have this order');
        }
        if (($paypal['id'] ?? '') !== $paypalId) {
            return CallbackResult::ignored('PayPal answered with another order');
        }

        if (($paypal['status'] ?? '') === 'APPROVED') {
            if (!$order->isPending()) {
                return CallbackResult::ignored('order is no longer pending');
            }

            return $this->sameMoney($order, $paypal['purchase_units'][0]['amount'] ?? null)
                ? $this->capture($paypalId, $order)
                : CallbackResult::ignored('not an order of this site');
        }

        return $this->fromPayPalOrder($paypal, $order, $closeUnpaid && $order->isPending());
    }

    /**
     * What a full PayPal order, from a capture or a lookup, means for the core order.
     *
     * @param array<string,mixed> $paypal
     * @param Order               $order
     * @param bool                $closeUnpaid
     *
     * @return CallbackResult
     * @throws HttpException when a capture for a closed order cannot be refunded yet
     */
    private function fromPayPalOrder(array $paypal, Order $order, bool $closeUnpaid): CallbackResult
    {
        $captures = $paypal['purchase_units'][0]['payments']['captures'] ?? array();
        $captures = is_array($captures) ? array_filter($captures, 'is_array') : array();
        $statuses = array_map(static fn (array $c): string => (string) ($c['status'] ?? ''), $captures);

        foreach ($captures as $capture) {
            if (in_array($capture['status'] ?? '', array('COMPLETED', 'PARTIALLY_REFUNDED'), true)) {
                return $this->settleCapture($capture, $order);
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
     * The order is read again first: a cancel or the daily check may have closed it while
     * PayPal was asked, and then the money goes back to the buyer.
     *
     * @param array<string,mixed> $capture
     * @param Order               $order
     *
     * @return CallbackResult
     * @throws HttpException when a capture for a closed order cannot be refunded yet
     */
    private function settleCapture(array $capture, Order $order): CallbackResult
    {
        $id    = (string) ($capture['id'] ?? '');
        $money = Money::fromAmount($capture['amount'] ?? null);
        if (!self::isId($id) || $money === null) {
            return CallbackResult::ignored('malformed capture');
        }

        $now = $this->orders->find($order->getId()) ?? $order;
        if (in_array($now->getStatus(), array(Order::STATUS_FAILED, Order::STATUS_CANCELLED), true)) {
            return $this->refundClosed($now, $id);
        }

        return CallbackResult::paid($order->getId(), $id, $money[0], $money[1]);
    }

    /**
     * Give back money PayPal took for an order core had already closed, and tell the admin.
     * Done once per order; a refund PayPal could not be asked for throws, so it is retried.
     *
     * @param Order  $order
     * @param string $captureId
     *
     * @return CallbackResult
     * @throws HttpException when PayPal cannot be reached or fails
     */
    private function refundClosed(Order $order, string $captureId): CallbackResult
    {
        if ($order->meta(self::META_CLOSED_REFUND) !== null) {
            return CallbackResult::ignored('payment for a closed order was already handled');
        }

        $subject = __('PayPal: a payment arrived for a closed order', 'paypal');
        try {
            $refund = $this->api->refundCapture($captureId, 'closed-' . $this->config->siteId . '-' . $order->getId());
        } catch (HttpException $e) {
            $code = $e->getCode();
            if ($code < 400 || $code >= 500 || $code === 401) {
                throw $e;
            }
            $this->remember($order->getId(), self::META_CLOSED_REFUND, 'refused');
            $this->tell($subject, array(
                sprintf(__('PayPal payment %1$s for order #%2$d arrived after the order was closed, and PayPal refused to refund it: %3$s', 'paypal'), $captureId, $order->getId(), self::short($e->getMessage())),
                __('Refund it in your PayPal account. The order was not marked paid.', 'paypal'),
            ));

            return CallbackResult::ignored('payment for a closed order; refund refused');
        }

        $status = (string) ($refund['status'] ?? '');
        if (!in_array($status, array('COMPLETED', 'PENDING'), true)) {
            throw new HttpException('PayPal did not refund capture ' . $captureId . ($status !== '' ? ' (' . $status . ')' : ''));
        }
        $this->remember($order->getId(), self::META_CLOSED_REFUND, (string) ($refund['id'] ?? $status));
        $this->tell($subject, array(
            sprintf(__('PayPal payment %1$s for order #%2$d arrived after the order was closed. It was refunded to the buyer at PayPal.', 'paypal'), $captureId, $order->getId()),
            __('Nothing else needs doing. The order was not marked paid.', 'paypal'),
        ));

        return CallbackResult::ignored('payment for a closed order; refunded');
    }

    /**
     * Mail the admin, and log the same lines.
     *
     * @param string   $subject
     * @param string[] $lines
     *
     * @return void
     */
    private function tell(string $subject, array $lines): void
    {
        error_log('PayPal: ' . implode(' ', $lines));
        $this->notify($subject, $lines);
    }

    /**
     * The raw webhook body and its PAYPAL-* transmission headers.
     *
     * @return array{0:string,1:array<string,string>}
     */
    protected function input(): array
    {
        $headers = array();
        foreach (array('auth-algo', 'cert-url', 'transmission-id', 'transmission-sig', 'transmission-time') as $name) {
            $headers[$name] = (string) ($_SERVER['HTTP_PAYPAL_' . strtoupper(str_replace('-', '_', $name))] ?? '');
        }

        return array((string) file_get_contents('php://input'), $headers);
    }

    /**
     * Settle a verified request through core, so its checks on money, owner and replays apply.
     *
     * @param array<string,mixed> $request
     *
     * @return CallbackResult
     */
    protected function settle(array $request): CallbackResult
    {
        return Billing::handleCallback(self::ID, $request);
    }

    /**
     * Queue an event for a later retry. Only its id is kept; the job fetches it again from PayPal.
     *
     * @param string $eventId
     *
     * @return void
     */
    protected function queue(string $eventId): void
    {
        osc_job_enqueue(Plugin::JOB_EVENT, array('event' => $eventId), array('unique_key' => $eventId));
    }

    /**
     * E-mail the site's contact address.
     *
     * @param string   $subject
     * @param string[] $lines
     *
     * @return void
     */
    protected function notify(string $subject, array $lines): void
    {
        osc_mail_admin($subject, $lines);
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
     * What the buyer sees they are paying for: a goods order's label on a core that has one, else the credits.
     * PayPal takes up to 127 characters.
     *
     * @param Order $order
     *
     * @return string
     */
    private static function describe(Order $order): string
    {
        if (method_exists($order, 'isGoods') && $order->isGoods()) {
            return mb_substr($order->getLabel(), 0, 127);
        }

        return sprintf(__('%d credits', 'paypal'), $order->getCredits());
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
            $href  = (string) ($link['href'] ?? '');
            $parts = parse_url($href);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host === 'paypal.com' || str_ends_with($host, '.paypal.com')) {
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
