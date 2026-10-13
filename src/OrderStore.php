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

use mindstellar\billing\Order;

/**
 * What the gateway reads and writes about orders. CoreOrderStore is the real one.
 */
interface OrderStore
{
    public function find(int $id): ?Order;

    /**
     * The PayPal order carrying this reference: a PayPal order id while pending, a capture id once paid.
     */
    public function findByRef(string $ref): ?Order;

    /**
     * Remember the PayPal order id on a pending order.
     *
     * @return bool false when the order is not pending, already holds it, or another order has it
     */
    public function attachRef(int $orderId, string $ref): bool;

    /**
     * Store one plugin meta value on the order. Other keys are kept.
     *
     * @param bool|int|float|string|null $value
     */
    public function setMeta(int $orderId, string $key, $value): bool;

    /**
     * Close a pending order whose checkout could not start.
     */
    public function markFailed(int $orderId): void;

    /**
     * Pending PayPal orders older than $seconds that hold a PayPal order id.
     *
     * @return Order[]
     */
    public function stalePending(int $seconds, int $limit): array;
}
