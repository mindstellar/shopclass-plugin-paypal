<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * On a core older than 7.0 (no osc_mail_admin() here) the plugin registers nothing but a notice.
 */

require __DIR__ . '/lib/bootstrap.php';

$GLOBALS['__hooks'] = array();
$GLOBALS['__other'] = array();

function osc_plugin_path($file)
{
    return 'paypal/index.php';
}
function osc_register_plugin($path, $fn)
{
    $GLOBALS['__other'][] = 'register_plugin';
}
function osc_add_hook($name, $fn, $priority = 10)
{
    $GLOBALS['__hooks'][$name][] = $fn;
}
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['__other'][] = 'settings';
}
function osc_add_route_hook($id, $regexp, $url)
{
    $GLOBALS['__other'][] = 'route';
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

harness_section('an older core');

check('this run has no osc_mail_admin(), as on Shopclass 6.x', !function_exists('osc_mail_admin'));
require __DIR__ . '/../index.php';

pin('only the admin notice is hooked', array('admin_page_header'), array_keys($GLOBALS['__hooks']));
pin('no install, settings page or route is registered', array(), $GLOBALS['__other']);
ob_start();
($GLOBALS['__hooks']['admin_page_header'][0])();
$html = (string) ob_get_clean();
check('the notice says what is needed', str_contains($html, 'PayPal Payment needs Shopclass 7.0 or later'));

exit(harness_result());
