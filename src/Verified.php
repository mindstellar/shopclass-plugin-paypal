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
 * Data the plugin trusts: a verified or fetched event, or a PayPal order to look up or capture.
 * An HTTP request cannot carry an object, so a forged callback can never pass one.
 */
final class Verified
{
    public const EVENT   = 'event';
    public const CAPTURE = 'capture';
    public const CHECK   = 'check';

    /**
     * @param string              $kind        One of the constants
     * @param array<string,mixed> $event       The event, for EVENT
     * @param string              $paypalOrder The PayPal order id, for CAPTURE and CHECK
     * @param int|null            $orderId     The core order it must belong to, for CAPTURE and CHECK
     * @param bool                $closeUnpaid For CHECK: fail the order when the buyer never approved it
     */
    private function __construct(
        public string $kind,
        public array $event = array(),
        public string $paypalOrder = '',
        public ?int $orderId = null,
        public bool $closeUnpaid = false
    ) {
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return self
     */
    public static function event(array $event): self
    {
        return new self(self::EVENT, $event);
    }

    /**
     * Capture an approved PayPal order and settle the core order from the answer.
     *
     * @param string $paypalOrder
     * @param int    $orderId
     *
     * @return self
     */
    public static function capture(string $paypalOrder, int $orderId): self
    {
        return new self(self::CAPTURE, array(), $paypalOrder, $orderId);
    }

    /**
     * Look a PayPal order up and settle the core order from what it says.
     *
     * @param string $paypalOrder
     * @param int    $orderId
     * @param bool   $closeUnpaid
     *
     * @return self
     */
    public static function check(string $paypalOrder, int $orderId, bool $closeUnpaid): self
    {
        return new self(self::CHECK, array(), $paypalOrder, $orderId, $closeUnpaid);
    }
}
