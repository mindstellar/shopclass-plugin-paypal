#!/usr/bin/env bash
# Unit tests: no database, no network. They load core's billing classes from
# SHOPCLASS_CORE, or ../osclass. tests/integration/ needs core's scratch MySQL and runs on its own.
set -u
cd "$(dirname "$0")/.."
fail=0
for t in tests/*.php; do
    echo "── $t"
    php "$t" | tail -3
    [ "${PIPESTATUS[0]}" -eq 0 ] || fail=1
done
exit "$fail"
