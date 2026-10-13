<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** The old-core guard when the http piece of Shopclass 7.0 is missing. */

$missing = 'http';
require __DIR__ . '/lib/oldcore.php';
