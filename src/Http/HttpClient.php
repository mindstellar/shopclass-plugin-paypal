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

namespace mindstellar\paypal\Http;

/**
 * Sends one HTTP request. Tests swap in a fake, so nothing here touches the network.
 */
interface HttpClient
{
    /**
     * @param string               $method  GET or POST
     * @param string               $url
     * @param array<string,string> $headers name => value
     * @param string|null          $body    Request body, for POST
     *
     * @return array{status:int,body:string}
     * @throws HttpException when no response came back at all
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): array;
}
