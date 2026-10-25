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

use mindstellar\http\CurlHttpClient;
use mindstellar\http\HttpClient;
use mindstellar\http\HttpException;

/**
 * The settings page's connection check: whether the saved credentials and the webhook work.
 */
final class Connection
{
    private PayPalApi $api;

    public function __construct(
        private Config $config,
        HttpClient $http,
        ?TokenCache $cache = null
    ) {
        $this->api = new PayPalApi($http, $config->apiBase(), $config->clientId, $config->secret, $cache ?? new KvTokenCache());
    }

    /**
     * The check on the saved settings, with core's HTTP client and its short default timeouts.
     *
     * @return self
     */
    public static function fromSettings(): self
    {
        return new self(Config::fromSettings(), new CurlHttpClient());
    }

    /**
     * Names the active mode and credentials without revealing them, so a kept answer is never reused for others.
     *
     * @return string
     */
    public function key(): string
    {
        return hash('sha256', implode("\n", array($this->config->mode, $this->config->clientId, $this->config->secret, $this->config->webhookId)));
    }

    /**
     * Ask PayPal for a token and for the webhook, and say what is wrong, if anything.
     *
     * @param string $webhookUrl This site's webhook URL
     *
     * @return array<int,array{0:string,1:string}> level ('ok', 'warning' or 'error') and message
     */
    public function check(string $webhookUrl): array
    {
        if ($this->config->clientId === '' || $this->config->secret === '') {
            return array(array('warning', __('Add the client id and secret for this mode, then save.', 'paypal')));
        }

        try {
            $this->api->token(true);
        } catch (HttpException $e) {
            return array(array('error', sprintf(__('PayPal refused the client id and secret: %s', 'paypal'), $e->getMessage())));
        }
        $lines = array(array('ok', $this->config->live()
            ? __('Connected to PayPal (live).', 'paypal')
            : __('Connected to the PayPal sandbox.', 'paypal')));

        if ($this->config->webhookId === '') {
            $lines[] = $this->config->live()
                ? array('warning', __('No webhook id is set, so PayPal is not offered at checkout yet.', 'paypal'))
                : array('warning', __('No webhook id is set. Sandbox payments still work when the buyer returns to the site; add a webhook before going live.', 'paypal'));

            return $lines;
        }

        try {
            $hook = $this->api->webhook($this->config->webhookId);
        } catch (HttpException $e) {
            $lines[] = array('error', __('PayPal does not know this webhook id. Copy it from the same app as the client id.', 'paypal'));

            return $lines;
        }

        $url = (string) ($hook['url'] ?? '');
        if ($url !== $webhookUrl) {
            $lines[] = array('warning', sprintf(__('The webhook points to %s, not to this site.', 'paypal'), $url));
        }
        $names = array();
        foreach ((array) ($hook['event_types'] ?? array()) as $type) {
            $names[] = is_array($type) ? (string) ($type['name'] ?? '') : '';
        }
        $missing = in_array('*', $names, true) ? array() : array_diff(PayPalGateway::EVENTS, $names);
        if ($missing !== array()) {
            $lines[] = array('warning', sprintf(__('The webhook is missing these events: %s', 'paypal'), implode(', ', $missing)));
        }
        if (count($lines) === 1) {
            $lines[] = array('ok', __('The webhook is set up.', 'paypal'));
        }

        return $lines;
    }
}
