// @ts-check
/**
 * The install-flow specs WITHOUT the stash/restore hooks.
 *
 * playwright.install.config.js owns the whole lifecycle: its globalSetup stashes
 * the live config and its globalTeardown always puts it back, deliberately, so a
 * crash mid-wizard cannot leave the site without a config file. That is right for
 * `npm run test:e2e:install` and wrong for a caller that needs the fresh install
 * to still be standing afterwards.
 *
 * tests/tools/scratch_db_run.sh is that caller: it stashes, drives an install,
 * runs the unit suite against it, and restores from its own trap -- which fires
 * on a failing suite, an error, or a ctrl-c. Two teardowns would race, and the
 * hooks' one would win before phpunit ever ran.
 *
 * So this config borrows the base and drops ONLY the two global hooks. It is not
 * meant to be run directly; the safety it gives up belongs to the caller.
 */
const base = require('./playwright.install.config.js');

const config = { ...base };

delete config.globalSetup;
delete config.globalTeardown;

module.exports = config;
