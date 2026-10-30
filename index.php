<?php
/*
Plugin Name: PayPal Payment
Plugin URI: https://github.com/mindstellar/shopclass-plugin-paypal
Description: Take payments for shop orders and credit packages through PayPal Checkout. Buyers pay on PayPal's own pages; the order is captured on return and confirmed by a verified webhook. Refunds from the admin.
Version: 0.1.8
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: paypal
Requires Shopclass: 7.0.0
Tested up to: 7.0
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-paypal/issues
Modes: classifieds, shop
*/

/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\paypal\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\paypal\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Shopclass before 7.0 lacks the lazy gateway hook, core's HTTP client and osc_mail_admin(), so the plugin stays off there.
if (!defined(PaymentGatewayRegistry::class . '::HOOK')
    || !interface_exists('mindstellar\\http\\HttpClient')
    || !function_exists('osc_mail_admin')
) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('PayPal Payment needs Shopclass 7.0 or later and is switched off. Update Shopclass to use it.', 'paypal'))
            . '</div>';
    }, 10);

    return;
}

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PAGE));
});

// The gateway, while billing is on, built the first time a page asks for payment methods.
osc_add_hook(PaymentGatewayRegistry::HOOK, array(Plugin::class, 'register'));

// The buyer comes back here from PayPal, after paying or cancelling.
osc_add_route_hook(Plugin::ROUTE_RETURN, 'paypal/return/([0-9]+)', 'paypal/return/{order}');
osc_add_hook(Plugin::ROUTE_RETURN, array(Plugin::class, 'returnPage'));
osc_add_route_hook(Plugin::ROUTE_CANCEL, 'paypal/cancel/([0-9]+)', 'paypal/cancel/{order}');
osc_add_hook(Plugin::ROUTE_CANCEL, array(Plugin::class, 'cancelPage'));

// Retries for webhooks that could not be settled, and a daily check of pending orders.
osc_add_hook('register_jobs', array(Plugin::class, 'registerJobs'));
osc_add_hook('cron_daily', array(Plugin::class, 'daily'));
osc_add_hook('job_gave_up', array(Plugin::class, 'jobGaveUp'));

// Core 7.1 shows one shared notice for gateways in test mode; older cores get this plugin's own banner.
if (class_exists(\mindstellar\billing\event\PaymentTestModes::class)) {
    osc_add_filter('admin_payment_test_modes', static fn (array $modes): array => !osc_billing_enabled() || osc_settings_value(Plugin::PAGE, 'mode') === 'live'
        ? $modes
        : $modes + array('PayPal' => osc_settings_page_url(Plugin::PAGE)));
} else {
    osc_add_hook('admin_page_header', array(Plugin::class, 'adminNotice'), 10);
}
