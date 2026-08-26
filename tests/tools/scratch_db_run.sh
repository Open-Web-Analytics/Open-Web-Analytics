#!/usr/bin/env bash
#
# Run the unit suite against a FRESH install in a scratch database.
#
# The suite normally talks to whatever install owa-config.php names -- on a dev
# box, the live one. Two consequences that removes:
#
#   1. a test that writes has to stop short of writing, because the target is
#      real (which is why the goal tests covered validation and not the save)
#   2. a test that silently leans on a row this install happens to have keeps
#      passing, and only CI -- which has no database -- ever notices
#
# FRESH, not a copy: copying the live database would carry across exactly the
# ambient rows this exists to remove.
#
# EITHER INSTALL PATH. The two are not interchangeable and only one of them
# writes a config file:
#
#   --via cli   (default)  writecli supplies the config, cli.php cmd=install
#                          fills the schema. No browser, seconds.
#   --via web              the real wizard, driven by Playwright, which WRITES
#                          owa-config.php itself. Slower, needs the live vhost.
#
# It reuses tests/e2e/install_harness.php rather than growing a second mechanism
# beside it: that harness already stashes the live config, creates the scratch
# databases, refuses to clobber an existing backup, and has a `doctor` that
# recovers an aborted run.
#
# Restores on ANY exit -- failing suite, error, ctrl-c -- because a dead run
# would otherwise leave the live config stashed and the site down.
#
#   bash tests/tools/scratch_db_run.sh
#   bash tests/tools/scratch_db_run.sh --via web
#   bash tests/tools/scratch_db_run.sh --filter GoalGroup
#
# The suite passes under this as of 2026-08-25. It did not when it was written:
# a fresh install surfaced eight defects the live one hides, because a configured
# install registers an error handler that swallows warnings and because ambient
# rows keep the null paths from being reached. All are fixed. Two were behaviour
# bugs rather than noise -- the dom-clicks report lost its title on every route
# but document_id, and a metric with an unrecognised aggregation built a SELECT
# with a hole in it.
#
# If this goes red, that is the point of it. Read the warning, do not silence it.
#
set -uo pipefail

cd "$(dirname "$0")/../.."

HARNESS=tests/e2e/install_harness.php
VIA=cli

while [ $# -gt 0 ]; do
    case "$1" in
        --via) VIA="${2:-}"; shift 2 ;;
        --via=*) VIA="${1#*=}"; shift ;;
        *) break ;;
    esac
done

case "$VIA" in
    cli|web) ;;
    *) echo "scratch-db: --via must be cli or web (got '$VIA')" >&2; exit 2 ;;
esac

info() { php "$HARNESS" info | python3 -c "import json,sys;print(json.load(sys.stdin)['$1'])"; }

restore() {
    local rc=$?
    echo
    echo "--- restoring the live install ---"
    php "$HARNESS" restore
    exit $rc
}

echo "--- stashing the live config, creating scratch databases ---"
php "$HARNESS" stash || exit 1
trap restore EXIT INT TERM

if [ "$VIA" = "cli" ]; then

    echo "--- writing the CLI scratch config ---"
    php "$HARNESS" writecli >/dev/null || exit 1

    echo "--- installing (cli.php cmd=install) ---"
    php cli.php \
        cmd=install \
        "user_id=$(info installcli_admin_id)" \
        "email_address=$(info installcli_admin_id)" \
        "domain=$(info installcli_domain)" 2>&1 | tail -3
else

    # The wizard writes owa-config.php itself, which is the whole reason this
    # path exists separately. Run without the install config's global hooks --
    # its teardown would restore before phpunit ever saw the install.
    echo "--- installing (web wizard, Playwright) ---"
    npx playwright test \
        --config=playwright.install-nostash.config.js \
        --project=install-web 2>&1 | tail -5
fi

echo
vendor/bin/phpunit --no-coverage "$@"
