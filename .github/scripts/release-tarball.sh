#!/usr/bin/env bash
#
# The Release Packager's tarball: owa_<version>_packaged.tar of the current
# directory, which must be a built tree (composer install --no-dev, npm run
# build, release.php written). Used by .github/workflows/main.yml, and by
# tests/tools/self_update_e2e.php to pack its test releases the same way.
#
# Usage: bash .github/scripts/release-tarball.sh <version> [--list]
#
# --list prints the members a tarball of this directory would hold, and
# writes nothing: release-verify.sh reads it from a clean checkout of the
# release commit.
#
# NOTE: these are exact paths rather than globs, with one deliberate exception
# noted below. tar's --exclude wildcards match slashes, so a tidier
# './modules/*/src' would exclude more than it looks like -- any module's src,
# not just the one meant. modules/Base/src and modules/Domstream/src are the
# unbuilt source; webpack compiles both into public/base/dist.
#
# The exception is './playwright*.config.js'. Listing the configs by exact name
# silently missed playwright.install-nostash.config.js, which therefore shipped
# in the 1.13.0 tarball; the glob covers the next config added too. Because '*'
# matches slashes this would also catch a *.config.js under a directory named
# playwright-something -- the only one is ./playwright-report, which is build
# output and excluded in its own right anyway.
#
# This previously also had to protect a MaxMind reader bundled under
# modules/MaxmindGeoip/includes/, which composer classmapped and which
# therefore had to ship. That library is a composer dependency now, so it
# arrives in vendor/ like everything else and no longer constrains this
# list.
set -euo pipefail

VERSION="${1:?the version, such as 2.0.3}"

EXCLUDES=(
    --exclude='./composer.json'
    --exclude='./composer.lock'
    --exclude='./package-lock.json'
    --exclude='./package.json'
    --exclude='./webpack.config.js'
    --exclude='./webpack.public-htaccess.js'
    --exclude='./modules/Base/src'
    --exclude='./modules/Domstream/src'
    --exclude='./node_modules'
    --exclude='./.build'
    --exclude='./.gitignore'
    --exclude='./.gitattributes'
    --exclude='./.gitconfig'
    --exclude='./.github'
    --exclude='./.git'
    --exclude='./tests'
    --exclude='./phpunit.xml'
    --exclude='./phpstan.neon'
    --exclude='./.php-cs-fixer.dist.php'
    --exclude='./.php-cs-fixer.cache'
    --exclude='./jest.config.js'
    --exclude='./babel.config.js'
    --exclude='./playwright*.config.js'
    --exclude='./.phpunit.cache'
    --exclude='./test-results'
    --exclude='./playwright-report'
    --exclude="./owa_${VERSION}_packaged.tar"
)

if [ "${2:-}" = --list ]; then
    tar --directory ./ "${EXCLUDES[@]}" -cvf /dev/null ./
    exit 0
fi

tar --directory ./ "${EXCLUDES[@]}" -pcvf "./owa_${VERSION}_packaged.tar" ./

# What an installed release relies on to install this one: release.php,
# which npm run build wrote, and the files ReleaseCheck::judge() requires.
# What proves the tarball is its signature (.github/scripts/release-sign.php),
# made after this.
listing=$(tar -tf "./owa_${VERSION}_packaged.tar")
for f in release.php updater.php cli.php owa_env.php Core/Env.php modules/Base/Classes/AutoUpdate/Tar.php; do
    grep -qx "./$f" <<< "$listing" || { echo "The tarball has no $f." >&2; exit 1; }
done
