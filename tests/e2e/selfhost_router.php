<?php
/**
 * The php -S router of the self-hosted e2e runner (playwright.selfhost.config.js).
 *
 * Playwright starts php -S before globalSetup runs `selfhost_harness.php up`, and
 * globalTeardown restores the stashed owa-config.php before the server stops. In
 * a git worktree that config is a symlink to another install's config, which
 * names that install's database. So nothing of OWA runs unless owa-config.php is
 * the harness's (owa_e2e_selfhost_config_is_ours()):
 *
 *   /__owa_e2e_selfhost   200, always: the webServer readiness probe
 *   anything else         503 until the harness's config is in place,
 *                         then served by php -S as usual
 */
require_once __DIR__ . '/selfhost_marker.php';

if ((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__owa_e2e_selfhost') {
    header('Content-Type: text/plain');
    echo "self-hosted e2e server\n";
    return true;
}

if (!owa_e2e_selfhost_config_is_ours(dirname(__DIR__, 2) . '/owa-config.php')) {
    http_response_code(503);
    header('Content-Type: text/plain');
    echo "The self-hosted e2e harness has not written owa-config.php.\n";
    return true;
}

return false;
