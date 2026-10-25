<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Config::fromSettings(): the mode, which mode's credentials are read, and who counts as an admin.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\paypal\Config;

$GLOBALS['settings'] = array();
$GLOBALS['prefs']    = array('site_id' => 'a1b2c3d4e5f60718');
$GLOBALS['admin']    = false;
function osc_settings_value($page, $name)
{
    return $GLOBALS['settings'][$name] ?? ($name === 'mode' ? 'sandbox' : '');
}
function osc_get_preference($name, $section = 'osclass')
{
    return $GLOBALS['prefs'][$name] ?? '';
}
function osc_billing_currency(): string
{
    return 'usd';
}
function osc_route_url($id, $args = array())
{
    return 'https://shop.test/' . $id . '/' . ($args['order'] ?? '');
}
function osc_billing_buy_url(): string
{
    return 'https://shop.test/buy';
}
function osc_is_admin_user_logged_in()
{
    return $GLOBALS['admin'];
}

harness_section('the mode');

$GLOBALS['settings'] = array('sandbox_client_id' => ' sandbox-id ', 'live_client_id' => 'live-id', 'sandbox_webhook_id' => 'HOOKS', 'live_webhook_id' => 'HOOKL');
$GLOBALS['prefs'] += array('sandbox_secret' => ' sandbox-secret ', 'live_secret' => 'live-secret');
$c = Config::fromSettings();
pin('sandbox reads the sandbox app, trimmed', array('sandbox', 'sandbox-id', 'sandbox-secret', 'HOOKS'), array($c->mode, $c->clientId, $c->secret, $c->webhookId));
pin('against the sandbox API', 'https://api-m.sandbox.paypal.com/', $c->apiBase());

$GLOBALS['settings']['mode'] = 'live';
$c = Config::fromSettings();
pin('live reads the live app', array('live', 'live-id', 'live-secret', 'HOOKL'), array($c->mode, $c->clientId, $c->secret, $c->webhookId));
pin('against the live API', 'https://api-m.paypal.com/', $c->apiBase());

foreach (array('garbage', 'LIVE', '', 'production') as $mode) {
    $GLOBALS['settings']['mode'] = $mode;
    pin('a stored mode of ' . json_encode($mode) . ' falls back to sandbox', 'sandbox', Config::fromSettings()->mode);
}

harness_section('the rest');

$c = Config::fromSettings();
pin('the billing currency is upper case', 'USD', $c->billingCurrency);
pin('the site id', 'a1b2c3d4e5f60718', $c->siteId);
pin('return and cancel routes for the order', array('https://shop.test/paypal-return/5', 'https://shop.test/paypal-cancel/5'), array(($c->returnUrl)(5), ($c->cancelUrl)(5)));

$GLOBALS['admin'] = false;
pin('a web user is not an admin', false, ($c->isAdmin)());
$GLOBALS['admin'] = true;
pin('a signed-in admin is', true, ($c->isAdmin)());
$GLOBALS['admin'] = 1;
pin('whatever core returns is read as true or false', true, ($c->isAdmin)());

harness_section('a core with osc_admin_is_browsing()');

if (!function_exists('osc_admin_is_browsing')) {
    function osc_admin_is_browsing(): bool
    {
        return $GLOBALS['browsing'];
    }
}
$GLOBALS['admin']    = false;
$GLOBALS['browsing'] = true;
pin('an admin browsing the shop counts, also on its API calls', true, ($c->isAdmin)());
$GLOBALS['admin']    = true;
$GLOBALS['browsing'] = false;
pin('...and that helper is the one read', false, ($c->isAdmin)());

exit(harness_result());
