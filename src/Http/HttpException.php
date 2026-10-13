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

use RuntimeException;

/**
 * PayPal could not be reached, or answered with an error. The code is the HTTP status, or 0.
 */
class HttpException extends RuntimeException
{
}
