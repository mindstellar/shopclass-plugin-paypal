<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The REST client: the OAuth token and its cache, the 401 retry, and webhook verification.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\http\HttpException;
use mindstellar\paypal\PayPalApi;

$client = 'AYSq3RDGsmBLJE-otTkBtM-jBRd1TCQwFf9RGfwddNXWz0uFU9ztymylOhRS';
$secret = 'EGnHDxD_qRPdaLdZz8iCr8N7_MzF-YHPTkjs6NKYQvQSBngp4PTTVWkPZRbL';

harness_section('the access token');

$http  = new FakeHttp();
$cache = new MemoryCache();
$api   = new PayPalApi($http, PayPalApi::SANDBOX, $client, $secret, $cache);
$http->on('GET', 'v2/checkout/orders/X', 200, array('id' => 'X'));

$api->order('X');
$req = $http->requests[0];
pin('the first call fetches a token', array('POST', 'https://api-m.sandbox.paypal.com/v1/oauth2/token'), array($req['method'], $req['url']));
pin('with the client id and secret as basic auth', 'Basic ' . base64_encode($client . ':' . $secret), $req['headers']['Authorization']);
pin('for client credentials', 'grant_type=client_credentials', $req['body']);
pin('then sends it as a bearer token', 'Bearer A21AA1', $http->requests[1]['headers']['Authorization']);
pin('the token is cached', 1, count($cache->items));
$ttl = array_values($cache->items)[0][1];
check('for a bit under its lifetime of 32400 seconds', $ttl < 32400 && $ttl >= 32400 - 600, (string) $ttl);
check('the cache key does not hold the secret or the client id', !str_contains((string) array_key_first($cache->items), $secret) && !str_contains((string) array_key_first($cache->items), $client));

$api->order('X');
$api->order('X');
pin('later calls reuse it', 1, $http->tokens);

$fresh = new PayPalApi($http, PayPalApi::SANDBOX, $client, $secret, $cache);
$fresh->order('X');
pin('across requests too, through the cache', 1, $http->tokens);

$other = new PayPalApi($http, PayPalApi::SANDBOX, $client, 'EOtherSecret_aaaaaaaaaaaaaaaaaaaa', $cache);
$other->order('X');
pin('a new secret gets its own token', 2, $http->tokens);
$live = new PayPalApi($http, PayPalApi::LIVE, $client, $secret, $cache);
$live->order('X');
pin('so does the other mode', 3, $http->tokens);
pin('which calls the live API', 'https://api-m.paypal.com/v2/checkout/orders/X', end($http->requests)['url']);

$short = new FakeHttp();
$short->on('POST', 'v1/oauth2/token', 200, array('access_token' => 'A21short', 'expires_in' => 100));
$c = new MemoryCache();
(new PayPalApi($short, PayPalApi::SANDBOX, $client, $secret, $c))->token();
pin('a short-lived token is cached for half its life', 50, array_values($c->items)[0][1]);
$c = new MemoryCache();
$short->on('POST', 'v1/oauth2/token', 200, array('access_token' => 'A21none'));
(new PayPalApi($short, PayPalApi::SANDBOX, $client, $secret, $c))->token();
pin('one without a lifetime is not cached', array(), $c->items);

harness_section('a token PayPal no longer accepts');

$http  = new FakeHttp();
$cache = new MemoryCache();
$api   = new PayPalApi($http, PayPalApi::SANDBOX, $client, $secret, $cache);
$http->on('GET', 'v2/checkout/orders/X', 200, array('id' => 'X'));
$api->order('X');
$http->onceOn('GET', 'v2/checkout/orders/X', 401, array('error' => 'invalid_token'));
pin('a 401 is retried once with a new token', array('id' => 'X'), $api->order('X'));
pin('two tokens fetched in all', 2, $http->tokens);
pin('the retry carries the new token', 'Bearer A21AA2', end($http->requests)['headers']['Authorization']);
pin('which replaces the cached one', 'A21AA2', array_values($cache->items)[0][0]);

$http->on('GET', 'v2/checkout/orders/X', 401, array('error' => 'invalid_token'));
$count = count($http->requests);
$code  = null;
try {
    $api->order('X');
} catch (HttpException $e) {
    $code = $e->getCode();
}
pin('a second 401 is an error, not a loop', 401, $code);
pin('after one retry: two calls and one token', 3, count($http->requests) - $count);
$message = '';
try {
    $api->order('X');
} catch (HttpException $e) {
    $message = $e->getMessage();
}
check('the error names no token and no secret', $message !== '' && !str_contains($message, 'A21AA') && !str_contains($message, $secret) && !str_contains($message, base64_encode($client . ':' . $secret)));
pin('every call has a timeout', array(PayPalApi::TIMEOUT), array_values(array_unique(array_column($http->requests, 'timeout'))));

harness_section('errors');

$http = new FakeHttp();
$http->on('POST', 'v1/oauth2/token', 401, array('error' => 'invalid_client', 'error_description' => 'Client Authentication failed'));
$api     = new PayPalApi($http, PayPalApi::SANDBOX, $client, $secret, new MemoryCache());
$message = '';
try {
    $api->order('X');
} catch (HttpException $e) {
    $message = $e->getMessage();
}
pin('bad credentials say so', 'PayPal refused the client id and secret (401: Client Authentication failed)', $message);
check('without the secret in the message', !str_contains($message, $secret));
pin('and no call is made', 1, count($http->requests));

$http = new FakeHttp();
$http->on('POST', 'v2/checkout/orders/X/capture', 422, array('name' => 'UNPROCESSABLE_ENTITY', 'details' => array(array('issue' => 'ORDER_ALREADY_CAPTURED'))));
$api = new PayPalApi($http, PayPalApi::SANDBOX, $client, $secret, new MemoryCache());
try {
    $api->captureOrder('X', 'capture-1');
} catch (HttpException $e) {
    $message = $e->getMessage();
    $code    = $e->getCode();
}
pin('an API error names its issue', 'PayPal answered 422: UNPROCESSABLE_ENTITY (ORDER_ALREADY_CAPTURED)', $message);
pin('with the status as its code', 422, $code);
$req = end($http->requests);
pin('a capture has an empty JSON body', '{}', $req['body']);
pin('and its request id', 'capture-1', $req['headers']['PayPal-Request-Id']);
pin('and asks for the full answer', 'return=representation', $req['headers']['Prefer']);

$threw = false;
try {
    (new PayPalApi($http, PayPalApi::SANDBOX, '', '', new MemoryCache()))->order('X');
} catch (HttpException $e) {
    $threw = true;
}
check('no credentials throws before any request', $threw);

$http = new FakeHttp();
$http->down('GET', 'v2/checkout/orders/X');
$threw = false;
try {
    (new PayPalApi($http, PayPalApi::SANDBOX, $client, $secret, new MemoryCache()))->order('X');
} catch (HttpException $e) {
    $threw = $e->getCode() === 0;
}
check('PayPal unreachable throws with code 0', $threw);

harness_section('webhook verification');

$http = new FakeHttp();
$api  = new PayPalApi($http, PayPalApi::SANDBOX, $client, $secret, new MemoryCache());
$raw  = "{\"id\":\"WH-1\",\"event_type\":\"PAYMENT.CAPTURE.COMPLETED\",\"resource\":{\"amount\":{\"value\":\"9.90\"},\"note\":\"a/b \\u00e9\"}}";
$http->on('POST', 'v1/notifications/verify-webhook-signature', 200, array('verification_status' => 'SUCCESS'));
check('a delivery PayPal vouches for passes', $api->verifyWebhook(pp_headers(), HOOK_ID, $raw));
$body = end($http->requests)['body'];
check('the event is sent byte for byte, not re-encoded', str_ends_with($body, ',"webhook_event":' . $raw . '}'));
$sent = json_decode($body, true);
pin('with the transmission headers and webhook id', array(
    'auth_algo'         => 'SHA256withRSA',
    'cert_url'          => pp_headers()['cert-url'],
    'transmission_id'   => pp_headers()['transmission-id'],
    'transmission_sig'  => pp_headers()['transmission-sig'],
    'transmission_time' => pp_headers()['transmission-time'],
    'webhook_id'        => HOOK_ID,
), array_diff_key($sent, array('webhook_event' => 1)));
pin('and the event decodes as sent', '9.90', $sent['webhook_event']['resource']['amount']['value']);

$http->on('POST', 'v1/notifications/verify-webhook-signature', 200, array('verification_status' => 'FAILURE'));
check('a delivery PayPal does not vouch for fails', !$api->verifyWebhook(pp_headers(), HOOK_ID, $raw));

$count = count($http->requests);
$h     = pp_headers();
unset($h['transmission-sig']);
check('a missing header fails', !$api->verifyWebhook($h, HOOK_ID, $raw));
check('no webhook id fails', !$api->verifyWebhook(pp_headers(), '', $raw));
pin('both without asking PayPal', $count, count($http->requests));

exit(harness_result());
