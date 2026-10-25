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
use mindstellar\billing\Order;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\http\CurlHttpClient;
use mindstellar\job\Job;
use mindstellar\settings\SettingsPageRegistry;
use Params;
use RuntimeException;
use Throwable;

/**
 * The wiring: registration, the return and cancel pages, background jobs and admin notices.
 */
final class Plugin
{
    /** Settings page id, and the preference section its values live in. */
    public const PAGE = 'paypal';

    /** Route the buyer comes back to after approving at PayPal. */
    public const ROUTE_RETURN = 'paypal-return';

    /** Route the buyer comes back to after cancelling at PayPal. */
    public const ROUTE_CANCEL = 'paypal-cancel';

    /** Job that re-fetches one webhook event from PayPal and settles it. */
    public const JOB_EVENT = 'paypal.event';

    /** Daily job that checks pending orders PayPal never told us about. */
    public const JOB_RECONCILE = 'paypal.reconcile';

    /** Key-value entry holding the settings page's last connection check. */
    public const KV_CONNECTION = 'connection';

    /** Seconds the connection check is kept. */
    public const CONNECTION_TTL = 600;

    /** fn(): PayPalGateway -- replaces the real gateway in tests. */
    public static ?Closure $factory = null;

    /**
     * Make this install's site id, unless one survives from an earlier install.
     *
     * @return void
     */
    public static function install(): void
    {
        Config::siteId();
    }

    /**
     * Remove the stored settings and the cached token. The site id stays, so orders in flight settle after a reinstall.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        foreach (array_keys(SettingsPageRegistry::getInstance()->fields(self::PAGE)) as $name) {
            osc_delete_preference($name, self::PAGE);
        }
        try {
            osc_kv_delete_group(KvTokenCache::GROUP);
        } catch (Throwable $e) {
            // Cached tokens expire on their own.
        }
    }

    /**
     * The gateway, built on the current settings.
     *
     * @return PayPalGateway
     */
    public static function gateway(): PayPalGateway
    {
        if (self::$factory !== null) {
            return (self::$factory)();
        }

        return new PayPalGateway(Config::fromSettings(), new CurlHttpClient(), new CoreOrderStore());
    }

    /**
     * Register the gateway while billing is on, and hand it back. Core calls this the first time a page asks for payment methods.
     *
     * @param PaymentGatewayRegistry|null $registry
     *
     * @return PayPalGateway|null
     */
    public static function register($registry = null): ?PayPalGateway
    {
        if (!osc_billing_enabled()) {
            return null;
        }
        $gateway = self::gateway();
        ($registry instanceof PaymentGatewayRegistry ? $registry : PaymentGatewayRegistry::getInstance())->register($gateway);

        return $gateway;
    }

    /**
     * The URL to paste into the PayPal app as the webhook.
     *
     * @return string
     */
    public static function webhookUrl(): string
    {
        return osc_base_url(true) . '?page=billing&action=callback&gateway=' . PayPalGateway::ID;
    }

    /**
     * The buyer approved at PayPal and is back. Capture the payment and settle it.
     *
     * @return void
     */
    public static function returnPage(): void
    {
        if (!osc_is_web_user_logged_in()) {
            osc_redirect_to(osc_user_login_url());
        }

        $status = self::settleReturn(
            self::register(),
            new CoreOrderStore(),
            Params::getParamInt('order'),
            (int) osc_logged_user_id(),
            Params::getParamString('token')
        );
        if (!osc_billing_enabled()) {
            osc_redirect_to(osc_base_url());
        }
        $orderId = Params::getParamInt('order');
        osc_redirect_to($status !== '' && function_exists('osc_billing_order_return_url') ? osc_billing_order_return_url($orderId) : osc_billing_orders_url());
    }

    /**
     * The buyer cancelled at PayPal. Close the order unless PayPal says it was paid after all.
     *
     * @return void
     */
    public static function cancelPage(): void
    {
        if (!osc_is_web_user_logged_in()) {
            osc_redirect_to(osc_user_login_url());
        }

        self::settleCancel(
            self::register(),
            new CoreOrderStore(),
            Params::getParamInt('order'),
            (int) osc_logged_user_id(),
            Params::getParamString('token')
        );
        osc_redirect_to(osc_billing_enabled() ? osc_billing_buy_url() : osc_base_url());
    }

    /**
     * The return page's work: check the buyer owns the order, capture it, flash where it stands.
     * PayPal is asked only for a pending order whose PayPal order is the one in the URL.
     *
     * @param PayPalGateway|null $gateway Null while billing is off
     * @param OrderStore         $orders
     * @param int                $orderId
     * @param int                $userId  The signed-in buyer
     * @param string             $token   The PayPal order id PayPal put in the URL
     *
     * @return string the order's status afterwards, or '' when it was refused
     */
    public static function settleReturn(?PayPalGateway $gateway, OrderStore $orders, int $orderId, int $userId, string $token): string
    {
        $order = self::buyerOrder($gateway, $orders, $orderId, $userId);
        if ($order === null) {
            return '';
        }

        $before = $order->getStatus();
        if ($order->isPending() && self::matches($order, $token)) {
            try {
                $gateway->processReturn($token, $order->getId());
            } catch (Throwable $e) {
                // PayPal or the database did not answer. The webhook and the daily check settle it later.
                error_log('PayPal: return page for order #' . $order->getId() . ': ' . $e->getMessage());
            }
        }

        $after = self::statusOf($orders, $order->getId());
        self::flashOutcome($order->getId(), $before, $after, method_exists($order, 'isGoods') && $order->isGoods());

        return $after;
    }

    /**
     * The cancel page's work: the same checks, then a lookup that closes an unpaid order.
     *
     * @param PayPalGateway|null $gateway
     * @param OrderStore         $orders
     * @param int                $orderId
     * @param int                $userId
     * @param string             $token
     *
     * @return string the order's status afterwards, or '' when it was refused
     */
    public static function settleCancel(?PayPalGateway $gateway, OrderStore $orders, int $orderId, int $userId, string $token): string
    {
        $order = self::buyerOrder($gateway, $orders, $orderId, $userId);
        if ($order === null) {
            return '';
        }

        $before = $order->getStatus();
        if ($order->isPending() && self::matches($order, $token)) {
            try {
                $gateway->processCheck($token, $order->getId(), true);
            } catch (Throwable $e) {
                error_log('PayPal: cancel page for order #' . $order->getId() . ': ' . $e->getMessage());
            }
        }

        $after = self::statusOf($orders, $order->getId());
        if ($after === Order::STATUS_FAILED && $before === Order::STATUS_PENDING) {
            osc_add_flash_info_message(sprintf(__('You cancelled the PayPal payment for order #%d. Nothing was charged.', 'paypal'), $order->getId()));
        } else {
            self::flashOutcome($order->getId(), $before, $after, method_exists($order, 'isGoods') && $order->isGoods());
        }

        return $after;
    }

    /**
     * Tell the buyer where their order stands.
     *
     * @param int    $orderId
     * @param string $before Status before the page ran
     * @param string $after  Status now
     * @param bool   $goods  The order is for goods, not credits
     *
     * @return void
     */
    public static function flashOutcome(int $orderId, string $before, string $after, bool $goods = false): void
    {
        $settled = defined('mindstellar\\billing\\Order::SETTLED') ? Order::SETTLED : array(Order::STATUS_PAID);
        if (in_array($after, $settled, true)) {
            osc_add_flash_ok_message(in_array($before, $settled, true)
                ? sprintf(__('Order #%d is paid.', 'paypal'), $orderId)
                : ($goods
                    ? sprintf(__('Payment received for order #%d.', 'paypal'), $orderId)
                    : sprintf(__('Payment for order #%d received. Credits added.', 'paypal'), $orderId)));
        } elseif ($after === Order::STATUS_PENDING) {
            osc_add_flash_info_message(sprintf(__('Payment for order #%d is processing. Credits are added once PayPal confirms it.', 'paypal'), $orderId));
        } else {
            osc_add_flash_warning_message(sprintf(__('Order #%d was not paid.', 'paypal'), $orderId));
        }
    }

    /**
     * Register the job handlers. Cron does not ask for payment methods, so each registers the gateway itself.
     *
     * @return void
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB_EVENT, static function (Job $job): void {
            $gateway = self::register();
            if ($gateway === null) {
                throw new RuntimeException('billing is switched off');
            }
            $gateway->processEvent((string) $job->get('event', ''));
        });
        osc_job_register_handler(self::JOB_RECONCILE, static function (Job $job): void {
            $gateway = self::register();
            if ($gateway !== null && $gateway->hasKeys()) {
                $gateway->reconcile();
            }
        });

        osc_job_describe(self::JOB_EVENT, __('Record a PayPal payment', 'paypal'), static function (array $payload): string {
            return (string) ($payload['event'] ?? '');
        });
        osc_job_describe(self::JOB_RECONCILE, __('Check pending PayPal orders', 'paypal'));
    }

    /**
     * Queue the daily check, once.
     *
     * @return void
     */
    public static function daily(): void
    {
        if (osc_billing_enabled()) {
            osc_job_ensure(self::JOB_RECONCILE);
        }
    }

    /**
     * Mail the site's contact address when a PayPal job stops retrying.
     *
     * @param mixed $type
     * @param mixed $payload
     * @param mixed $error
     * @param mixed $id
     *
     * @return void
     */
    public static function jobGaveUp($type, $payload = array(), $error = '', $id = 0): void
    {
        if ($type !== self::JOB_EVENT && $type !== self::JOB_RECONCILE) {
            return;
        }

        $event = is_array($payload) ? (string) ($payload['event'] ?? '') : '';
        error_log('PayPal: job #' . (int) $id . ' (' . $type . ') gave up: ' . $error);
        osc_mail_admin(__('PayPal: a payment needs checking', 'paypal'), array(
            __('A PayPal payment could not be recorded after several tries.', 'paypal'),
            $event !== '' ? sprintf(__('PayPal event: %s', 'paypal'), $event) : '',
            sprintf(__('Error: %s', 'paypal'), (string) $error),
            sprintf(__('Retry it under Tools > System info > Jobs (job #%d), and check the order in Billing > Orders.', 'paypal'), (int) $id),
        ));
    }

    /**
     * A notice on every admin page while sandbox mode is on.
     *
     * @return void
     */
    public static function adminNotice(): void
    {
        if (!osc_billing_enabled() || osc_settings_value(self::PAGE, 'mode') === 'live') {
            return;
        }
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('PayPal is in sandbox mode: no real money is taken. Switch to live mode before the site takes payments.', 'paypal'))
            . ' <a href="' . osc_esc_html(osc_settings_page_url(self::PAGE)) . '">'
            . osc_esc_html(__('PayPal settings', 'paypal')) . '</a></div>';
    }

    /**
     * The settings page's connection check.
     *
     * @return void
     */
    public static function renderConnection(): void
    {
        echo self::connectionHtml(Connection::fromSettings());
    }

    /**
     * The connection check as a list. The answer is kept for ten minutes per mode and
     * credentials, so the settings page does not wait on PayPal at every load.
     *
     * @param Connection $connection
     *
     * @return string
     */
    public static function connectionHtml(Connection $connection): string
    {
        $key    = $connection->key();
        $cached = null;
        try {
            $cached = osc_kv_get(KvTokenCache::GROUP, self::KV_CONNECTION);
        } catch (Throwable $e) {
            // Check again below.
        }
        if (is_array($cached) && ($cached['key'] ?? null) === $key && is_array($cached['lines'] ?? null)) {
            $lines = $cached['lines'];
        } else {
            $lines = $connection->check(self::webhookUrl());
            try {
                osc_kv_set(KvTokenCache::GROUP, self::KV_CONNECTION, array('key' => $key, 'lines' => $lines), self::CONNECTION_TTL);
            } catch (Throwable $e) {
                // Checked again on the next load.
            }
        }

        $lines   = array_merge($lines, $connection->offerNotes());
        $classes = array('ok' => 'text-success', 'warning' => 'text-warning', 'error' => 'text-danger', 'info' => 'text-muted');
        $html    = '<ul class="list-unstyled">';
        foreach ($lines as $line) {
            if (is_array($line) && count($line) === 2) {
                $html .= '<li class="' . osc_esc_html($classes[$line[0]] ?? '') . '">' . osc_esc_html((string) $line[1]) . '</li>';
            }
        }

        return $html . '</ul>';
    }

    /**
     * After the settings page saves: drop the kept connection check, so the next load checks again.
     *
     * @return void
     */
    public static function settingsSaved(): void
    {
        try {
            osc_kv_delete(KvTokenCache::GROUP, self::KV_CONNECTION);
        } catch (Throwable $e) {
            // It expires on its own.
        }
    }

    /**
     * The buyer's own PayPal order, or null with an error flashed.
     *
     * @return Order|null
     */
    private static function buyerOrder(?PayPalGateway $gateway, OrderStore $orders, int $orderId, int $userId): ?Order
    {
        $order = $orders->find($orderId);
        if ($gateway === null
            || $order === null
            || $order->getUserId() !== $userId
            || $order->getGateway() !== PayPalGateway::ID
        ) {
            osc_add_flash_error_message(__('That order is not available.', 'paypal'));

            return null;
        }

        return $order;
    }

    /**
     * Whether the PayPal order in the URL is the one stored on the order.
     */
    private static function matches(Order $order, string $token): bool
    {
        $stored = (string) ($order->meta(PayPalGateway::META_ORDER) ?? $order->getExternalRef());

        return $token !== '' && $stored !== '' && hash_equals($stored, $token);
    }

    private static function statusOf(OrderStore $orders, int $orderId): string
    {
        $order = $orders->find($orderId);

        return $order === null ? '' : $order->getStatus();
    }
}
