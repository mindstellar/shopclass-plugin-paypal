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

/**
 * The settings the gateway runs on, read once per request. Tests build one by hand.
 */
final class Config
{
    /**
     * @param string       $mode            'sandbox' or 'live'
     * @param string       $clientId        The active mode's client id
     * @param string       $secret          The active mode's secret
     * @param string       $webhookId       The active mode's webhook id
     * @param string       $name            Name at checkout
     * @param string       $billingCurrency The site's billing currency
     * @param string       $siteId          Random id of this install, so invoice ids stay unique on a shared PayPal account
     * @param Closure      $returnUrl       fn(int $orderId): string
     * @param Closure      $cancelUrl       fn(int $orderId): string
     * @param string       $buyUrl          Where a buyer whose checkout failed lands
     * @param Closure|null $isAdmin         fn(): bool; sandbox mode is offered only to a signed-in admin
     */
    public function __construct(
        public string $mode,
        public string $clientId,
        public string $secret,
        public string $webhookId,
        public string $name,
        public string $billingCurrency,
        public string $siteId,
        public Closure $returnUrl,
        public Closure $cancelUrl,
        public string $buyUrl,
        public ?Closure $isAdmin = null
    ) {
    }

    /**
     * Read the saved settings. The secret is write-only on the settings page, so it is read from its preference.
     *
     * @return self
     */
    public static function fromSettings(): self
    {
        $mode = osc_settings_value(Plugin::PAGE, 'mode') === 'live' ? 'live' : 'sandbox';

        return new self(
            $mode,
            trim((string) osc_settings_value(Plugin::PAGE, $mode . '_client_id')),
            trim((string) osc_get_preference($mode . '_secret', Plugin::PAGE)),
            trim((string) osc_settings_value(Plugin::PAGE, $mode . '_webhook_id')),
            (string) osc_settings_value(Plugin::PAGE, 'name'),
            strtoupper((string) osc_billing_currency()),
            self::siteId(),
            static fn (int $orderId): string => osc_route_url(Plugin::ROUTE_RETURN, array('order' => $orderId)),
            static fn (int $orderId): string => osc_route_url(Plugin::ROUTE_CANCEL, array('order' => $orderId)),
            osc_billing_buy_url(),
            // Core 7.1 also sees an admin on the site's own API calls; older cores only on pages.
            static fn (): bool => function_exists('osc_admin_is_browsing')
                ? osc_admin_is_browsing()
                : (function_exists('osc_is_admin_user_logged_in') && (bool) osc_is_admin_user_logged_in())
        );
    }

    /**
     * Whether payments are real.
     *
     * @return bool
     */
    public function live(): bool
    {
        return $this->mode === 'live';
    }

    /**
     * The REST API base for the active mode.
     *
     * @return string
     */
    public function apiBase(): string
    {
        return $this->live() ? PayPalApi::LIVE : PayPalApi::SANDBOX;
    }

    /**
     * This install's id, kept in the plugin's preference section across reinstalls.
     * The insert-if-absent keeps two first requests from making two.
     *
     * @return string
     */
    public static function siteId(): string
    {
        $id = (string) osc_get_preference('site_id', Plugin::PAGE);
        if (preg_match('/^[a-f0-9]{16}$/', $id)) {
            return $id;
        }

        $table = DB_TABLE_PREFIX . 't_preference';
        osc_db_execute(
            'INSERT IGNORE INTO ' . $table . " (s_section, s_name, s_value, e_type) VALUES (?, 'site_id', ?, 'STRING')",
            array(Plugin::PAGE, bin2hex(random_bytes(8)))
        );
        $id = (string) osc_db_scalar('SELECT s_value FROM ' . $table . " WHERE s_section = ? AND s_name = 'site_id'", array(Plugin::PAGE));
        if (function_exists('osc_reset_preferences')) {
            osc_reset_preferences();
        }

        return $id;
    }
}
