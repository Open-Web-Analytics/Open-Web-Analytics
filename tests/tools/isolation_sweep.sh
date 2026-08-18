#!/usr/bin/env bash
#
# Run every test file in its own PHP process and report the ones that only pass
# as part of the full suite.
#
# WHY THIS EXISTS
# PHPUnit runs the whole suite in ONE process, and OWA's Service is a singleton,
# so a test can silently inherit state that something earlier set up: a registry
# the dispatcher builds lazily (cli_commands, scheduled_jobs, api_methods,
# event_processors, metricsByEntity), or a legacy owa_* alias that only exists
# once something has autoloaded it -- note `instanceof` does NOT autoload, so an
# untouched alias reads as false for every object, with no error at all.
#
# Both failure modes are invisible in a normal run and both have happened here.
# Randomised ordering finds them only by luck and makes a red build hard to
# reproduce; running each file alone is deterministic.
#
# Exits non-zero if any file fails on its own.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

PHPUNIT="${PHPUNIT:-./vendor/bin/phpunit}"
failed=0
checked=0

# Optional file arguments narrow the sweep, which is how you re-check one file
# after fixing it without paying for the whole suite.
if [ "$#" -gt 0 ]; then
    files=("$@")
else
    files=(tests/*Test.php)
fi

printf '%s\n' "Running ${#files[@]} test file(s), each in its own process..."

for f in "${files[@]}"; do
    [ -e "$f" ] || continue
    checked=$((checked + 1))

    if out=$("$PHPUNIT" --no-coverage "$f" 2>&1) && printf '%s' "$out" | grep -qE '^(OK|OK \()'; then
        continue
    fi

    # A file whose every case skips (no database, say) reports "No tests
    # executed" rather than OK. That is not an isolation failure.
    if printf '%s' "$out" | grep -qE 'No tests executed|OK, but'; then
        continue
    fi

    failed=$((failed + 1))
    printf '\n--- %s fails on its own ---\n' "$f"
    printf '%s\n' "$out" | sed -n '/^There \(was\|were\)/,$p' | head -40
done

printf '\n%s\n' "Checked $checked file(s); $failed failed in isolation."

if [ "$failed" -ne 0 ]; then
    cat <<'MSG'

A test that passes in the suite but fails alone is depending on something an
earlier test did. Fix the test to establish its own precondition -- do not
reorder the suite around it.
MSG
    exit 1
fi
