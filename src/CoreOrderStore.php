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
use mindstellar\billing\OrderStore as Orders;

/**
 * Orders through core's own order store.
 */
final class CoreOrderStore implements OrderStore
{
    public function find(int $id): ?Order
    {
        return Orders::find($id);
    }

    public function findByRef(string $ref): ?Order
    {
        return Orders::findByGatewayRef(PayPalGateway::ID, $ref);
    }

    public function attachRef(int $orderId, string $ref): bool
    {
        return Orders::attachRef($orderId, PayPalGateway::ID, $ref);
    }

    public function setMeta(int $orderId, string $key, $value): bool
    {
        return Orders::setMeta($orderId, $key, $value);
    }

    public function markFailed(int $orderId): void
    {
        Orders::settle($orderId, Order::STATUS_FAILED);
    }

    public function stalePending(int $seconds, int $limit): array
    {
        $rows = osc_db_table(DB_TABLE_PREFIX . 't_billing_order')
            ->where('s_gateway', PayPalGateway::ID)
            ->where('s_status', Order::STATUS_PENDING)
            ->whereNotNull('s_external_ref')
            ->where('dt_date', '<=', date('Y-m-d H:i:s', time() - $seconds))
            ->orderBy('dt_date', 'ASC')
            ->limit($limit)
            ->get();

        return array_map(static fn (array $row): Order => Order::fromRow($row), $rows);
    }
}
