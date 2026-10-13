<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads index.php on a core missing one 7.0 piece, named by $missing ('hook', 'http' or 'mail'),
 * and checks the plugin registers nothing but its notice. Each tests/oldcore-*.php runs this in its own process.
 *
 * @var string $missing
 */

require_once __DIR__ . '/harness.php';

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/osclass', '/');
define('ABS_PATH', $core . '/');

$dirs = array('mindstellar\\base\\' => 'base', 'mindstellar\\currency\\' => 'currency');
if ($missing === 'hook') {
    require __DIR__ . '/old-registry.php';
} else {
    $dirs['mindstellar\\billing\\'] = 'billing';
}
if ($missing !== 'http') {
    $dirs['mindstellar\\http\\'] = 'http';
}
spl_autoload_register(static function (string $class) use ($core, $dirs): void {
    foreach ($dirs as $prefix => $dir) {
        $file = $core . '/oc-includes/osclass/classes/' . $dir . '/' . substr($class, strlen($prefix)) . '.php';
        if (strncmp($class, $prefix, strlen($prefix)) === 0 && is_file($file)) {
            require $file;
        }
    }
});
if ($missing !== 'mail') {
    function osc_mail_admin(string $subject, array $lines): bool
    {
        return true;
    }
}

$GLOBALS['__hooks'] = array();
$GLOBALS['__other'] = array();
function __($text, $domain = '')
{
    return $text;
}
function osc_plugin_path($file)
{
    return 'paypal/index.php';
}
function osc_register_plugin($path, $fn)
{
    $GLOBALS['__other'][] = 'install';
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

harness_section('a core without ' . $missing);

$present = array(
    'hook' => defined('mindstellar\\billing\\PaymentGatewayRegistry::HOOK'),
    'http' => interface_exists('mindstellar\\http\\HttpClient'),
    'mail' => function_exists('osc_mail_admin'),
);
pin('only that piece is missing', array($missing), array_keys(array_filter($present, static fn ($p) => !$p)));

require __DIR__ . '/../../index.php';

pin('only the admin notice is hooked', array('admin_page_header'), array_keys($GLOBALS['__hooks']));
pin('no install, settings page or route is registered', array(), $GLOBALS['__other']);
ob_start();
($GLOBALS['__hooks']['admin_page_header'][0])();
check('the notice says what is needed', str_contains((string) ob_get_clean(), 'PayPal Payment needs Shopclass 7.0 or later'));

exit(harness_result());
