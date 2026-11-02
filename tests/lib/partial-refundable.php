<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The shape of core's PartialRefundableGateway (Shopclass 7.1), for a core checkout that does not have it yet.
 */

namespace mindstellar\billing;

interface PartialRefundableGateway extends RefundableGateway
{
    public function refundPart(Order $order, int $amount, string $key): CallbackResult;
}
