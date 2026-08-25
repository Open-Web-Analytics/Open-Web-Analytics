#!/usr/bin/env bash
#
# Run the test suite the way CI's unit job does: NO owa-config.php and NO
# database.
#
# This exists because a green local suite says nothing about that job. Locally
# every DB-backed test runs (2 skips); in CI ~506 of them skip, and the tests
# that behave differently WITHOUT a database are exactly the ones no local run
# can see. Two separate red builds were caused by that blind spot before this
# script existed: a test that asserted a config file exists, and a set that
# wrote rows and so failed rather than skipped.
#
# It copies the WORKING TREE, not HEAD, so uncommitted work is what gets tested
# -- the point is to find this before pushing.
#
# Usage:
#   composer test:configless                 # whole suite
#   composer test:configless tests/FooTest.php   # one file
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# NOT "owa-configless": ClassLoadSmokeTest skips any path CONTAINING the
# substring "owa-config" -- meant for the install-generated config file -- so a
# staging directory named that way makes every source file invisible to it, and
# the suite fails claiming the class tree has vanished.
WORK="$(mktemp -d "${TMPDIR:-/tmp}/owa-nodb.XXXXXX")"

cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

echo "configless: staging a copy of the working tree in $WORK"

# The working tree minus the things that would make it NOT configless, and minus
# the heavy directories we either recreate or do not need.
#
# owa-config.php is the whole point: its absence is what makes this CI-like.
# owa-data holds the live install's logs and caches.
rsync -a \
    --exclude '.git/' \
    --exclude 'vendor/' \
    --exclude 'node_modules/' \
    --exclude 'owa-config.php' \
    --exclude 'owa-data/' \
    --exclude 'test-results/' \
    --exclude 'playwright-report/' \
    "$ROOT"/ "$WORK"/

# COPY vendor, never symlink. Composer's $baseDir is dirname(dirname(__DIR__)),
# so a symlink resolves back to the live install: it then loads the real tree's
# classes and fatals with "Cannot declare class ... already in use".
cp -r "$ROOT/vendor" "$WORK/vendor"

cd "$WORK"
composer dump-autoload --quiet

if [ -f owa-config.php ]; then
    echo "configless: owa-config.php present after staging -- refusing to run" >&2
    exit 1
fi

echo "configless: running phpunit with no config and no database"
set +e
./vendor/bin/phpunit "$@"
status=$?
set -e

exit "$status"
