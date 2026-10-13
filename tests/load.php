<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin loads without a database and registers what it should, and the settings page declares what it should.
 */

require __DIR__ . '/lib/bootstrap.php';

$GLOBALS['__hooks']    = array();
$GLOBALS['__routes']   = array();
$GLOBALS['__settings'] = array();
$GLOBALS['__install']  = null;

function osc_plugin_path($file)
{
    return 'paypal/index.php';
}
function osc_register_plugin($path, $fn)
{
    $GLOBALS['__install'] = array($path, $fn);
}
function osc_add_hook($name, $fn, $priority = 10)
{
    $GLOBALS['__hooks'][$name][] = $fn;
}
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['__settings'][$id] = $spec;
}
function osc_add_route_hook($id, $regexp, $url)
{
    $GLOBALS['__routes'][$id] = array($regexp, $url);
}

function osc_mail_admin(string $subject, array $lines): bool
{
    return true;
}

require __DIR__ . '/../index.php';

use mindstellar\paypal\Plugin;

harness_section('what the plugin registers');

pin('install runs Plugin::install', array('paypal/index.php', array(Plugin::class, 'install')), $GLOBALS['__install']);
pin('uninstall runs Plugin::uninstall', array(array(Plugin::class, 'uninstall')), $GLOBALS['__hooks']['paypal/index.php_uninstall'] ?? null);
check('the configure link has a handler', isset($GLOBALS['__hooks']['paypal/index.php_configure']));
pin('the gateway registers when a page first asks for payment methods', array(array(Plugin::class, 'register')), $GLOBALS['__hooks']['register_payment_gateways'] ?? null);
pin('and not on every page', array(null, null), array($GLOBALS['__hooks']['init'] ?? null, $GLOBALS['__hooks']['init_billing_non_secure'] ?? null));
pin('the return route', array('paypal/return/([0-9]+)', 'paypal/return/{order}'), $GLOBALS['__routes'][Plugin::ROUTE_RETURN] ?? null);
pin('answered by Plugin::returnPage', array(array(Plugin::class, 'returnPage')), $GLOBALS['__hooks'][Plugin::ROUTE_RETURN] ?? null);
pin('the cancel route', array('paypal/cancel/([0-9]+)', 'paypal/cancel/{order}'), $GLOBALS['__routes'][Plugin::ROUTE_CANCEL] ?? null);
pin('answered by Plugin::cancelPage', array(array(Plugin::class, 'cancelPage')), $GLOBALS['__hooks'][Plugin::ROUTE_CANCEL] ?? null);
pin('jobs, the daily check and the give-up mail', array(
    array(array(Plugin::class, 'registerJobs')),
    array(array(Plugin::class, 'daily')),
    array(array(Plugin::class, 'jobGaveUp')),
), array($GLOBALS['__hooks']['register_jobs'] ?? null, $GLOBALS['__hooks']['cron_daily'] ?? null, $GLOBALS['__hooks']['job_gave_up'] ?? null));
pin('the sandbox notice, and no old-core notice', array(array(Plugin::class, 'adminNotice')), $GLOBALS['__hooks']['admin_page_header'] ?? null);
check('job types are namespaced', preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/', Plugin::JOB_EVENT) && preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/', Plugin::JOB_RECONCILE));

harness_section('the settings page');

pin('declared under the plugin id', array('paypal'), array_keys($GLOBALS['__settings']));
$fields = array();
foreach ($GLOBALS['__settings']['paypal']['groups'] as $group) {
    foreach ($group['fields'] as $field) {
        $fields[$field['name']] = $field;
    }
}
pin('its fields', array('mode', 'sandbox_client_id', 'sandbox_secret', 'sandbox_webhook_id', 'live_client_id', 'live_secret', 'live_webhook_id', 'webhook_info', 'connection', 'name'), array_keys($fields));
pin('sandbox until an admin picks live', 'sandbox', $fields['mode']['default']);
foreach (array('sandbox_secret', 'live_secret') as $name) {
    pin($name . ' is a password field', 'secret', $fields[$name]['type']);
    check($name . ' is write-only, so it is never read back into the page', $fields[$name]['write_only'] === true);
    pin($name . ': a blank box keeps the stored one', null, ($fields[$name]['persist'])('  '));
    pin($name . ': a new one is stored trimmed', 'ESecretValue_aaaaaaaaaaaaaaaaaa', ($fields[$name]['persist'])(' ESecretValue_aaaaaaaaaaaaaaaaaa '));
}
pin('a client id is accepted', null, ($fields['sandbox_client_id']['validate'])(fake_config()->clientId));
check('junk in the client id box is refused', ($fields['sandbox_client_id']['validate'])('not a client id!') !== null);
pin('an empty client id is allowed until the admin has one', null, ($fields['live_client_id']['validate'])(''));
pin('a webhook id is accepted', null, ($fields['live_webhook_id']['validate'])(HOOK_ID));
check('a URL in the webhook id box is refused', ($fields['live_webhook_id']['validate'])('https://example.com/hook') !== null);
check('a secret with spaces is refused', ($fields['live_secret']['validate'])('two words') !== null);
pin('saving the page drops the kept connection check', array(Plugin::class, 'settingsSaved'), $GLOBALS['__settings']['paypal']['after_save'] ?? null);
$deprecated = array();
foreach (glob(__DIR__ . '/../src/*.php') as $file) {
    if (preg_match('/Registry::instance\(/', (string) file_get_contents($file))) {
        $deprecated[] = basename($file);
    }
}
pin('core registries are reached through getInstance(), not the deprecated instance()', array(), $deprecated);
check('the webhook info and connection check store nothing', $fields['webhook_info']['type'] === 'custom' && $fields['connection']['type'] === 'custom');

exit(harness_result());
