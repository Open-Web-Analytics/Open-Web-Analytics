#!/usr/bin/env bash
#
# Install a schema, rewind it, and upgrade it forward again.
#
# WHY: nothing else here ever upgrades a database. Every environment installs at
# the current version and stamps schema_version at the latest, so the updates
# never run -- see the long note in upgrade_cycle.php. Two production failures
# in one week lived in that hole.
#
# This provisions a scratch install through tests/e2e/selfhost_harness.php -- the
# same harness the isolation sweep uses, so CI is already known to provision this
# way -- and then hands it to upgrade_cycle.php to be rewound and rebuilt.
#
# The live config is restored on failure, error and ctrl-c. A dead run must not
# leave the box without its config.
#
#   bash tests/tools/upgrade_cycle_run.sh
#   bash tests/tools/upgrade_cycle_run.sh --verbose
#
# In CI this runs as its own job, because it is the only configuration in which
# the update path executes at all.
#
set -uo pipefail

cd "$(dirname "$0")/../.."

HARNESS=tests/e2e/selfhost_harness.php

teardown() {
    local rc=$?
    echo
    echo "--- dropping the scratch database, restoring the live config ---"
    php "$HARNESS" down >/dev/null 2>&1 || php "$HARNESS" doctor >/dev/null 2>&1
    exit $rc
}

echo "--- stashing the live config, creating a scratch database, installing ---"
php "$HARNESS" up >/dev/null || {
    echo "provisioning failed; run: php $HARNESS doctor" >&2
    exit 1
}
trap teardown EXIT INT TERM

echo
echo "--- rewinding the schema ---"
php tests/tools/upgrade_cycle.php down "$@" || exit 1

echo
echo "--- upgrading it again: cli.php cmd=update, the real command ---"
# NOT checked here. A failure is the finding, and the verify phase reports it
# with the schema version it actually reached -- which is more use than a bare
# non-zero exit from a command whose own output is already above.
php cli.php cmd=update 2>&1 | sed 's/^/    /'

echo
echo "--- verifying ---"
php tests/tools/upgrade_cycle.php verify "$@"
