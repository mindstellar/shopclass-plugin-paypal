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

use mindstellar\paypal\Http\HttpClient;
use mindstellar\paypal\Http\HttpException;

/**
 * The few PayPal REST calls the gateway makes. Every answer is decoded JSON.
 */
final class PayPalApi
{
    public const SANDBOX = 'https://api-m.sandbox.paypal.com/';
    public const LIVE    = 'https://api-m.paypal.com/';

    public function __construct(
        private HttpClient $http,
        private string $base,
        private string $clientId,
        private string $secret,
        private TokenCache $cache
    ) {
    }

    /**
     * An OAuth access token, from the cache or fresh from PayPal.
     *
     * @param bool $fresh Skip the cache
     *
     * @return string
     * @throws HttpException
     */
    public function token(bool $fresh = false): string
    {
        if ($this->clientId === '' || $this->secret === '') {
            throw new HttpException('No PayPal client id or secret is set');
        }

        $key = $this->cacheKey();
        if (!$fresh) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $response = $this->http->send('POST', $this->base . 'v1/oauth2/token', array(
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->secret),
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Accept'        => 'application/json',
        ), 'grant_type=client_credentials');
        $decoded = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($decoded) || !is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
            $reason = is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? '') : '';
            throw new HttpException('PayPal refused the client id and secret (' . $response['status'] . ($reason !== '' ? ': ' . $reason : '') . ')', $response['status']);
        }

        $token   = $decoded['access_token'];
        $expires = (int) ($decoded['expires_in'] ?? 0);
        // Kept a little shorter than PayPal keeps it, so a cached token is never stale.
        $ttl = $expires > 600 ? $expires - 300 : intdiv($expires, 2);
        if ($ttl > 0) {
            $this->cache->set($key, $token, $ttl);
        }

        return $token;
    }

    /**
     * @param array<string,mixed> $body
     * @param string              $requestId PayPal-Request-Id, so a retry makes one order
     *
     * @return array<string,mixed>
     */
    public function createOrder(array $body, string $requestId): array
    {
        return $this->call('POST', 'v2/checkout/orders', $body, $requestId);
    }

    /**
     * @param string $id PayPal order id
     *
     * @return array<string,mixed>
     */
    public function order(string $id): array
    {
        return $this->call('GET', 'v2/checkout/orders/' . rawurlencode($id));
    }

    /**
     * @param string $id        PayPal order id
     * @param string $requestId
     *
     * @return array<string,mixed>
     */
    public function captureOrder(string $id, string $requestId): array
    {
        return $this->call('POST', 'v2/checkout/orders/' . rawurlencode($id) . '/capture', array(), $requestId);
    }

    /**
     * Refund a capture in full.
     *
     * @param string $captureId
     * @param string $requestId
     *
     * @return array<string,mixed>
     */
    public function refundCapture(string $captureId, string $requestId): array
    {
        return $this->call('POST', 'v2/payments/captures/' . rawurlencode($captureId) . '/refund', array(), $requestId);
    }

    /**
     * @param string $id WH-...
     *
     * @return array<string,mixed>
     */
    public function event(string $id): array
    {
        return $this->call('GET', 'v1/notifications/webhooks-events/' . rawurlencode($id));
    }

    /**
     * @param string $id The webhook id from the PayPal app
     *
     * @return array<string,mixed>
     */
    public function webhook(string $id): array
    {
        return $this->call('GET', 'v1/notifications/webhooks/' . rawurlencode($id));
    }

    /**
     * Ask PayPal whether a webhook delivery is genuine.
     *
     * @param array<string,string> $headers   The five PAYPAL-* transmission headers, lower-case names without the prefix
     * @param string               $webhookId
     * @param string               $rawEvent  The request body, byte for byte; it must be a JSON object
     *
     * @return bool
     * @throws HttpException when PayPal cannot be asked
     */
    public function verifyWebhook(array $headers, string $webhookId, string $rawEvent): bool
    {
        $fields = array(
            'auth_algo'         => (string) ($headers['auth-algo'] ?? ''),
            'cert_url'          => (string) ($headers['cert-url'] ?? ''),
            'transmission_id'   => (string) ($headers['transmission-id'] ?? ''),
            'transmission_sig'  => (string) ($headers['transmission-sig'] ?? ''),
            'transmission_time' => (string) ($headers['transmission-time'] ?? ''),
            'webhook_id'        => $webhookId,
        );
        if ($webhookId === '' || in_array('', $fields, true)) {
            return false;
        }

        // The event goes in as sent: re-encoding it could change the bytes PayPal signed.
        $json = json_encode($fields, JSON_UNESCAPED_SLASHES);
        $body = substr((string) $json, 0, -1) . ',"webhook_event":' . $rawEvent . '}';

        return ($this->callRaw('POST', 'v1/notifications/verify-webhook-signature', $body)['verification_status'] ?? '') === 'SUCCESS';
    }

    /**
     * @param string                   $method
     * @param string                   $path
     * @param array<string,mixed>|null $body      JSON body, for POST
     * @param string|null              $requestId
     *
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $body = null, ?string $requestId = null): array
    {
        $json = null;
        if ($method === 'POST') {
            $json = $body === array() || $body === null ? '{}' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        return $this->callRaw($method, $path, $json, $requestId);
    }

    /**
     * Send a request with the access token. A 401 drops the cached token and tries once more.
     *
     * @param string      $method
     * @param string      $path
     * @param string|null $json
     * @param string|null $requestId
     *
     * @return array<string,mixed>
     * @throws HttpException on a transport error, an error status or a body that is not JSON
     */
    private function callRaw(string $method, string $path, ?string $json = null, ?string $requestId = null): array
    {
        $response = $this->send($method, $path, $json, $requestId, $this->token());
        if ($response['status'] === 401) {
            $this->cache->delete($this->cacheKey());
            $response = $this->send($method, $path, $json, $requestId, $this->token(true));
        }

        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HttpException('PayPal answered ' . $response['status'] . self::reason($decoded), $response['status']);
        }
        if (!is_array($decoded)) {
            throw new HttpException('PayPal sent a response that is not JSON');
        }

        return $decoded;
    }

    /**
     * @return array{status:int,body:string}
     */
    private function send(string $method, string $path, ?string $json, ?string $requestId, string $token): array
    {
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        );
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            // Full answers, so a capture carries its amount and status.
            $headers['Prefer'] = 'return=representation';
        }
        if ($requestId !== null) {
            $headers['PayPal-Request-Id'] = $requestId;
        }

        return $this->http->send($method, $this->base . $path, $headers, $json);
    }

    /**
     * ": NAME (ISSUE)" from a PayPal error body, or ''.
     *
     * @param mixed $decoded
     *
     * @return string
     */
    private static function reason($decoded): string
    {
        if (!is_array($decoded)) {
            return '';
        }
        $name  = (string) ($decoded['name'] ?? $decoded['error'] ?? '');
        $issue = (string) ($decoded['details'][0]['issue'] ?? '');

        return ($name !== '' ? ': ' . $name : '') . ($issue !== '' ? ' (' . $issue . ')' : '');
    }

    /**
     * One cache key per environment and credential pair, so new credentials never reuse an old token.
     *
     * @return string
     */
    private function cacheKey(): string
    {
        return 'token.' . substr(hash('sha256', $this->base . "\n" . $this->clientId . "\n" . $this->secret), 0, 32);
    }
}
