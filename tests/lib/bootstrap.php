<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads the plugin's classes and core's billing value classes, with no database and no
 * network. Core is found through SHOPCLASS_CORE, or a sibling checkout named osclass.
 */

require_once __DIR__ . '/harness.php';

function core_path(): string
{
    $path = getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/osclass';
    if (!is_file($path . '/oc-includes/osclass/classes/billing/PaymentGatewayRegistry.php')) {
        fwrite(STDERR, "Shopclass core not found at $path. Set SHOPCLASS_CORE to a checkout of 7.0.0 or later.\n");
        exit(2);
    }

    return rtrim($path, '/');
}

if (!defined('ABS_PATH')) {
    define('ABS_PATH', core_path() . '/');
}

// Core classes the plugin names load from the same checkout.
spl_autoload_register(static function (string $class): void {
    foreach (array('mindstellar\\billing\\' => 'billing', 'mindstellar\\base\\' => 'base', 'mindstellar\\currency\\' => 'currency', 'mindstellar\\job\\' => 'job', 'mindstellar\\http\\' => 'http', 'mindstellar\\container\\' => 'container') as $prefix => $dir) {
        $file = core_path() . '/oc-includes/osclass/classes/' . $dir . '/' . substr($class, strlen($prefix)) . '.php';
        if (strncmp($class, $prefix, strlen($prefix)) === 0 && is_file($file)) {
            require $file;
        }
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\paypal\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

ini_set('error_log', '/dev/null');
ini_set('display_errors', '1');

if (!function_exists('__')) {
    function __($text, $domain = '')
    {
        return $text;
    }
}

$GLOBALS['flash'] = array();
if (!function_exists('osc_add_flash_error_message')) {
    function osc_add_flash_error_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flash'][] = array('error', $msg);
    }
    function osc_add_flash_ok_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flash'][] = array('ok', $msg);
    }
    function osc_add_flash_info_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flash'][] = array('info', $msg);
    }
    function osc_add_flash_warning_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flash'][] = array('warning', $msg);
    }
}

require_once __DIR__ . '/fakes.php';
