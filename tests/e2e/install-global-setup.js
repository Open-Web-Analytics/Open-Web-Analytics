// @ts-check
/**
 * globalSetup for the INSTALL-flow specs (playwright.install.config.js only).
 *
 * The installer owns the two pieces of global state everything else depends on:
 * the config file and the database. So before the install specs run we:
 *   1. preflight  -- assert we CAN run (live config present, RDS reachable, no
 *      stale backup or scratch DBs from an aborted run). Fails loudly otherwise.
 *   2. stash      -- move the live owa-config.php to a backup AND create the two
 *      empty scratch databases the web + CLI paths install into.
 *
 * Paired with install-global-teardown.js, which restores the config and drops
 * the scratch DBs even if the specs fail. All delegated to install_harness.php
 * (the single source of truth for the provisioning logic).
 */
const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async () => {
    const harness = path.join(__dirname, 'install_harness.php');
    // Abort the whole run if preconditions aren't met -- never half-swap config.
    execFileSync('php', [harness, 'preflight'], { stdio: 'inherit' });
    // Stash the live config + create the scratch DBs.
    execFileSync('php', [harness, 'stash'], { stdio: 'inherit' });
};
