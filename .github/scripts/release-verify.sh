#!/usr/bin/env bash
#
# The Release Packager's check before it signs: the tarball the build job made
# holds this directory's files unchanged, and beyond them only build output.
# Run from a clean checkout of the release commit. Used by
# .github/workflows/main.yml, and by tests/tools/self_update_e2e.php on every
# release it packs.
#
# Usage: bash .github/scripts/release-verify.sh <version> [tarball]
#
# Refused:
#   - a member that is not a regular file or a directory;
#   - a name that does not start ./, or has an empty, . or .. segment;
#   - a name that appears twice;
#   - a file of this directory (release-tarball.sh --list) missing or changed;
#   - any other file, outside vendor/, public/, modules/Base/build/ and
#     release.php. Those are what composer install, npm run build and
#     cmd=version-info write, and their contents are not checked.
#
# Members are hashed as tar reads them (--to-command), in an empty directory:
# nothing is extracted. File modes are not compared.
set -euo pipefail

VERSION="${1:?the version, such as 2.0.3}"
TARBALL="$(realpath "${2:-./owa_${VERSION}_packaged.tar}")"

export LC_ALL=C
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

refuse() {
    echo "The tarball does not match the release commit: $1" >&2
    exit 1
}

tar -tvf "$TARBALL" > "$scratch/verbose"
tar -tf "$TARBALL" > "$scratch/names"

bad=$(awk 'substr($0, 1, 1) != "-" && substr($0, 1, 1) != "d" { print; exit }' "$scratch/verbose")
[ -z "$bad" ] || refuse "a member is not a file or a directory: $bad"

bad=$(awk '$0 == "./" { next }
    substr($0, 1, 2) != "./" { print; exit }
    { s = substr($0, 3); sub(/\/$/, "", s); k = split(s, p, "/")
      for (i = 1; i <= k; i++) if (p[i] == "" || p[i] == "." || p[i] == "..") { print; exit } }' "$scratch/names")
[ -z "$bad" ] || refuse "a member's name is not a plain path: $bad"

bad=$(sort "$scratch/names" | uniq -d | head -1)
[ -z "$bad" ] || refuse "$bad is in it twice."

bash "$(dirname "$0")/release-tarball.sh" "$VERSION" --list | grep -v '/$' > "$scratch/expected-names"
xargs -d '\n' -a "$scratch/expected-names" sha256sum -- > "$scratch/expected"

mkdir "$scratch/empty"
( cd "$scratch/empty" && tar -xf "$TARBALL" \
    --to-command='printf "%s  %s\n" "$(sha256sum | cut -d" " -f1)" "$TAR_FILENAME"' ) > "$scratch/built"

awk -v built='^\\./(vendor/|public/|modules/Base/build/|release\\.php$)' '
    FNR == NR { want[substr($0, 67)] = substr($0, 1, 64); next }
    { name = substr($0, 67)
      if (name in want) {
          if (want[name] != substr($0, 1, 64)) { print name " is changed."; bad = 1; exit }
          delete want[name]
      } else if (name !~ built) { print name " is not in the release commit."; bad = 1; exit }
    }
    END { if (!bad) for (n in want) { print n " is missing."; exit } }
' "$scratch/expected" "$scratch/built" > "$scratch/problem"

[ ! -s "$scratch/problem" ] || refuse "$(cat "$scratch/problem")"

echo "The tarball holds the release commit's $(wc -l < "$scratch/expected") files unchanged, and build output."
