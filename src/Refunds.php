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

use mindstellar\billing\PartialRefundableGateway;
use mindstellar\billing\RefundableGateway;

// Cores before Shopclass 7.1 have no part refunds, so there the gateway offers full refunds only.
if (interface_exists(PartialRefundableGateway::class)) {
    /** The refunds this core lets a gateway offer. */
    interface Refunds extends PartialRefundableGateway
    {
    }
} else {
    /** The refunds this core lets a gateway offer. */
    interface Refunds extends RefundableGateway
    {
    }
}
