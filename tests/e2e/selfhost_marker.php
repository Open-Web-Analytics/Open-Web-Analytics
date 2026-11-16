<?php
/**
 * How the self-hosted e2e runner tells its own owa-config.php from any other.
 *
 * selfhost_harness.php writes SELFHOST_CONFIG_MARKER into the config it
 * generates. selfhost_router.php runs nothing of OWA, and the harness's
 * teardown neither unseeds nor removes a config, unless the config is that file.
 */
const SELFHOST_CONFIG_MARKER = '// Written by tests/e2e/selfhost_harness.php for the self-hosted e2e run.';

/**
 * Whether $config is the harness's: a regular file, not a symlink (a git
 * worktree's owa-config.php links to another install's), carrying the marker.
 */
function owa_e2e_selfhost_config_is_ours(string $config): bool
{
    clearstatcache(true, $config);

    return is_file($config) && !is_link($config)
        && strpos((string) file_get_contents($config), SELFHOST_CONFIG_MARKER) !== false;
}
