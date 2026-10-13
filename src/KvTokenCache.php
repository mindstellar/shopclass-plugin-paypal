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

use Throwable;

/**
 * The token in core's key-value store. A store that fails only costs a fresh token.
 */
final class KvTokenCache implements TokenCache
{
    public const GROUP = 'paypal';

    public function get(string $key): ?string
    {
        try {
            $token = osc_kv_get(self::GROUP, $key);
        } catch (Throwable $e) {
            return null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function set(string $key, string $token, int $ttl): void
    {
        try {
            osc_kv_set(self::GROUP, $key, $token, $ttl);
        } catch (Throwable $e) {
            error_log('PayPal: could not cache the access token: ' . get_class($e));
        }
    }

    public function delete(string $key): void
    {
        try {
            osc_kv_delete(self::GROUP, $key);
        } catch (Throwable $e) {
            // It expires on its own.
        }
    }
}
