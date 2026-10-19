<?php

/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\paypal\PayPalGateway;
use mindstellar\paypal\Plugin;

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's
 * capability, validates each field and stores it under the "paypal" section.
 */

$pattern = static function (string $regex, string $error): Closure {
    return static function ($value) use ($regex, $error) {
        $value = trim((string) $value);

        return $value === '' || preg_match($regex, $value) ? null : $error;
    };
};

$account = static function (string $mode, string $where) use ($pattern): array {
    return array(
        array(
            'type'      => 'text',
            'name'      => $mode . '_client_id',
            'label'     => __('Client ID', 'paypal'),
            'help'      => $where,
            'maxlength' => 128,
            'attrs'     => array('autocomplete' => 'off', 'spellcheck' => 'false'),
            'validate'  => $pattern('/^[A-Za-z0-9_-]{20,128}$/', __('This does not look like a PayPal client ID.', 'paypal')),
        ),
        array(
            'type'        => 'secret',
            'name'        => $mode . '_secret',
            'label'       => __('Secret', 'paypal'),
            'help'        => __('Shown once in the PayPal app. It is never shown here again; leave blank to keep the saved one.', 'paypal'),
            'write_only'  => true,
            'masked'      => true,
            'attrs'       => array('autocomplete' => 'new-password', 'spellcheck' => 'false'),
            // A blank box keeps the stored secret.
            'persist'     => static fn ($value) => trim((string) $value) === '' ? null : trim((string) $value),
            'validate'    => $pattern('/^[A-Za-z0-9_-]{20,128}$/', __('This does not look like a PayPal secret.', 'paypal')),
        ),
        array(
            'type'      => 'text',
            'name'      => $mode . '_webhook_id',
            'label'     => __('Webhook ID', 'paypal'),
            'help'      => __('Under Webhooks in the same app, after you add the URL below.', 'paypal'),
            'maxlength' => 64,
            'attrs'     => array('autocomplete' => 'off', 'spellcheck' => 'false'),
            'validate'  => $pattern('/^[A-Za-z0-9-]{5,64}$/', __('This does not look like a PayPal webhook ID.', 'paypal')),
        ),
    );
};

$webhook = static function (): void {
    echo '<p><code>' . osc_esc_html(Plugin::webhookUrl()) . '</code></p>';
    echo '<p>' . osc_esc_html(__('In your PayPal app, add a webhook with this URL and subscribe it to these events:', 'paypal')) . '</p><ul>';
    foreach (PayPalGateway::EVENTS as $event) {
        echo '<li><code>' . osc_esc_html($event) . '</code></li>';
    }
    echo '</ul><p>' . osc_esc_html(__('Then copy the webhook ID into the field for the same mode. Sandbox and live apps each need their own webhook.', 'paypal')) . '</p>';
};

return array(
    'title'      => __('PayPal Payment', 'paypal'),
    'menu'       => '',
    'after_save' => array(Plugin::class, 'settingsSaved'),
    'intro'      => __('Take payments for credit packages through PayPal. Buyers pay on PayPal\'s own pages, so no card or account details reach this site.', 'paypal'),
    'groups'     => array(
        array(
            'title'  => __('Mode', 'paypal'),
            'fields' => array(
                array(
                    'type'    => 'radio',
                    'name'    => 'mode',
                    'label'   => __('Mode', 'paypal'),
                    'help'    => __('Sandbox uses your sandbox app and PayPal\'s test accounts. No real money moves.', 'paypal'),
                    'options' => array(
                        'sandbox' => __('Sandbox', 'paypal'),
                        'live'    => __('Live', 'paypal'),
                    ),
                    'default' => 'sandbox',
                ),
            ),
        ),
        array(
            'title'  => __('Sandbox app', 'paypal'),
            'fields' => $account('sandbox', __('From developer.paypal.com > Apps & Credentials, with Sandbox selected.', 'paypal')),
        ),
        array(
            'title'  => __('Live app', 'paypal'),
            'fields' => $account('live', __('From developer.paypal.com > Apps & Credentials, with Live selected.', 'paypal')),
        ),
        array(
            'title'  => __('Webhook', 'paypal'),
            'fields' => array(
                array(
                    'type'   => 'custom',
                    'name'   => 'webhook_info',
                    'label'  => __('Webhook URL', 'paypal'),
                    'render' => $webhook,
                ),
                array(
                    'type'   => 'custom',
                    'name'   => 'connection',
                    'label'  => __('Connection', 'paypal'),
                    'render' => array(Plugin::class, 'renderConnection'),
                ),
            ),
        ),
        array(
            'title'  => __('Checkout', 'paypal'),
            'fields' => array(
                array(
                    'type'      => 'text',
                    'name'      => 'name',
                    'label'     => __('Name at checkout', 'paypal'),
                    'default'   => 'PayPal',
                    'maxlength' => 60,
                    'required'  => true,
                ),
            ),
        ),
    ),
);
