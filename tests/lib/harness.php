<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The pin/check micro-harness the core repository's tests use, trimmed to the four
 * functions these tests need. No dependency on a test framework:
 * every test here runs from a bare `php tests/<file>.php`.
 */

$GLOBALS['okCount']    = $GLOBALS['okCount'] ?? 0;
$GLOBALS['failCount']  = $GLOBALS['failCount'] ?? 0;
$GLOBALS['failLabels'] = $GLOBALS['failLabels'] ?? array();

function describe($v): string
{
    if ($v === null) {
        return 'null';
    }
    if (is_bool($v)) {
        return 'bool(' . ($v ? 'true' : 'false') . ')';
    }
    if (is_int($v)) {
        return 'int(' . $v . ')';
    }
    if (is_string($v)) {
        return 'string("' . $v . '")';
    }
    if (is_array($v)) {
        return 'array(' . count($v) . ') ' . json_encode($v);
    }

    return gettype($v);
}

function report(string $label, bool $ok, string $expected, string $actual): void
{
    if ($ok) {
        $GLOBALS['okCount']++;
        echo "PASS  $label\n";

        return;
    }

    $GLOBALS['failCount']++;
    $GLOBALS['failLabels'][] = $label;
    echo "FAIL  $label\n        expected: $expected\n        actual:   $actual\n";
}

function pin(string $label, $expected, $actual): void
{
    report($label, $expected === $actual, describe($expected), describe($actual));
}

function check(string $label, bool $ok, string $detail = ''): void
{
    report($label, $ok, 'true', $ok ? 'true' : ('false' . ($detail !== '' ? " ($detail)" : '')));
}

function harness_section(string $title): void
{
    echo "\n== $title ==\n";
}

function harness_result(): int
{
    echo "\n----------------------------------------\n";
    echo "RESULT: {$GLOBALS['okCount']} passed, {$GLOBALS['failCount']} failed\n";
    foreach ($GLOBALS['failLabels'] as $l) {
        echo "  FAILED: $l\n";
    }

    return $GLOBALS['failCount'] === 0 ? 0 : 1;
}
