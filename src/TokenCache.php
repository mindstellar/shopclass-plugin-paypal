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

/**
 * Where the OAuth access token is kept between requests. KvTokenCache is the real one.
 */
interface TokenCache
{
    public function get(string $key): ?string;

    public function set(string $key, string $token, int $ttl): void;

    public function delete(string $key): void;
}
