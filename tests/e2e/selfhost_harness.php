<?php
/**
 * Self-hosting provisioning harness for the OWA end-to-end (Playwright) suite.
 *
 * PURPOSE
 * The reporting / admin / tracker / cookie specs are ordinary app-behavior tests:
 * they only need a RUNNING, INSTALLED OWA reachable at some base URL. On this box
 * that's the live Apache + RDS install; but the specs are written entirely against
 * relative URLs + Playwright's baseURL (see tests/e2e/fixtures.js) and a
 * deterministic seeder (seed_reporting_fixtures.php), so they can just as well run
 * against a throwaway install served by PHP's built-in server (`php -S`) on a bare
 * machine or in CI. This harness stands that throwaway install up and tears it
 * down, so `npm run test:e2e:selfhost` is fully self-contained.
 *
 * WHAT IT DOES (command `up`)
 *   1. If a live owa-config.php exists, STASH it (rename to a backup) so we never
 *      touch the live install's config or DB.
 *   2. Resolve DB credentials -- from OWA_E2E_DB_* env vars if given (the CI path,
 *      where a MySQL service is provisioned), else parsed out of the stashed live
 *      config (the local path on this box, reusing its RDS server). Either way we
 *      only ever CREATE/DROP a fixed SENTINEL scratch database, never the live one.
 *   3. CREATE the scratch database fresh.
 *   4. WRITE a fresh owa-config.php from owa-config-dist.php pointing at the scratch
 *      DB and at the php -S public URL (http://HOST:PORT/).
 *   5. Run the real CLI installer (`php cli.php cmd=install`) to build the schema.
 *
 * After `up`, the caller seeds fixtures (seed_reporting_fixtures.php) and starts
 * `php -S` at the repo root; every spec then runs against http://HOST:PORT/index.php.
 *
 * `down` drops the scratch DB, removes our config, and restores the live backup.
 * `doctor` recovers an aborted run. Everything is tolerant on teardown so a
 * mid-run crash can't leave the box without its live config.
 *
 * SAFETY: the scratch DB name is a fixed sentinel (SCRATCH_DB_DEFAULT) unless
 * OWA_E2E_DB_NAME overrides it, and we REFUSE to create/drop a name that equals the
 * live install's DB or that isn't a plain [a-z0-9_] identifier. `up` refuses to run
 * if a stale backup is present (run `doctor` first).
 *
 * Usage (from repo root):
 *   php tests/e2e/selfhost_harness.php up        # stash + create DB + write config + install
 *   php tests/e2e/selfhost_harness.php down       # drop DB + remove config + restore backup
 *   php tests/e2e/selfhost_harness.php doctor      # best-effort recovery after an aborted run
 *   php tests/e2e/selfhost_harness.php info        # print identifiers/URLs as JSON
 *   php tests/e2e/selfhost_harness.php baseurl     # print just the base URL (index.php)
 *
 * Env contract (all optional; sensible defaults below):
 *   OWA_E2E_HOST      php -S bind host   (default 127.0.0.1)
 *   OWA_E2E_PORT      php -S port        (default 8964)
 *   OWA_E2E_DB_HOST / _PORT / _USER / _PASSWORD / _NAME / _TYPE
 *
 * This file (like all of tests/) is excluded from the release tarball.
 */

// ---- Fixed identifiers / defaults (kept in sync with playwright.selfhost.config.js) ----
const SCRATCH_DB_DEFAULT = 'owa_e2e_selfhost';
const CONFIG_FILE = 'owa-config.php';
const BACKUP_FILE = 'owa-config.php.e2e-selfhost-bak';
const DEFAULT_HOST = '127.0.0.1';
const DEFAULT_PORT = '8964';

// The admin user the CLI installer creates (its password becomes OWA_DB_PASSWORD;
// the app-behavior specs log in as the SEPARATELY-seeded fixture users, not this
// one, so the install admin password is irrelevant to the specs). LOCAL throwaway.
const INSTALL_ADMIN_ID = 'owa-e2e-selfhost-admin@example.test';
const INSTALL_DOMAIN    = 'owa-e2e-selfhost.example.test';

// The file queue and error log this run may write to.
//
// WHY THIS IS NOT owa-data/logs/: async_log_dir is a filesystem path derived
// from the install directory, not from the database, so a scratch DB alone does
// NOT isolate the queue. Sharing it with the live install means the queue depth
// a spec measures counts whatever anyone else left there, and a drain the spec
// runs consumes the live install's queued events into the scratch DB.
//
// That is not hypothetical: it hid a real queue bug for weeks. The queue spec
// failed here and passed in CI -- a fresh checkout has an empty directory, this
// box did not -- so the failure read as local mess and was repeatedly dismissed
// as environmental.
const SCRATCH_LOG_DIR = 'owa-data/e2e-selfhost-logs/';

$repoRoot = dirname(__DIR__, 2) . '/';
$cmd = $argv[1] ?? 'info';

switch ($cmd) {
    case 'up':      out(up($repoRoot));      break;
    case 'down':    out(down($repoRoot));    break;
    case 'doctor':  out(doctor($repoRoot));  break;
    case 'info':    out(info());             break;
    case 'baseurl': echo baseUrl() . "\n";   break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: up | down | doctor | info | baseurl\n");
        exit(2);
}

// -----------------------------------------------------------------------------

/** The php -S origin (repo root), with trailing slash. This is OWA's public_url. */
function publicUrl(): string
{
    $host = getenv('OWA_E2E_HOST') ?: DEFAULT_HOST;
    $port = getenv('OWA_E2E_PORT') ?: DEFAULT_PORT;
    return "http://{$host}:{$port}/";
}

/** The base URL the specs target (public_url + index.php front controller). */
function baseUrl(): string
{
    return publicUrl() . 'index.php';
}

/** The scratch DB name (sentinel unless overridden), always validated by callers. */
function scratchDb(): string
{
    return getenv('OWA_E2E_DB_NAME') ?: SCRATCH_DB_DEFAULT;
}

function info(): array
{
    return [
        'scratch_db'       => scratchDb(),
        'public_url'       => publicUrl(),
        'base_url'         => baseUrl(),
        'install_admin_id' => INSTALL_ADMIN_ID,
        'install_domain'   => INSTALL_DOMAIN,
        'config_backup'    => BACKUP_FILE,
    ];
}

/**
 * Resolve DB credentials. Prefer explicit OWA_E2E_DB_* env (the CI path, where a
 * fresh MySQL is provisioned and there is no live config to protect). Otherwise
 * parse them out of the live/stashed owa-config.php (the local path on this box,
 * which reuses the RDS server that config points at). The DB NAME returned is
 * always our scratch DB, never the live one -- $creds['live_name'] separately
 * records the live install's DB (if any) so we can refuse to clobber it.
 */
function dbCreds(string $repoRoot, bool $backupOnly = false): array
{
    $envHost = getenv('OWA_E2E_DB_HOST');
    $liveName = parseLiveDbName($repoRoot, $backupOnly); // null if no live/stashed config

    if ($envHost !== false && $envHost !== '') {
        // CI path: everything from env.
        return [
            'host'      => $envHost,
            'port'      => getenv('OWA_E2E_DB_PORT') ?: '3306',
            'user'      => getenv('OWA_E2E_DB_USER') ?: 'root',
            'password'  => getenv('OWA_E2E_DB_PASSWORD') !== false ? getenv('OWA_E2E_DB_PASSWORD') : '',
            'type'      => getenv('OWA_E2E_DB_TYPE') ?: 'mysql',
            'live_name' => $liveName,
        ];
    }

    // Local path: reuse the live install's server (parsed from its config).
    $c = parseConfigConstants($repoRoot);
    if ($c === null || empty($c['OWA_DB_HOST']) || empty($c['OWA_DB_USER'])) {
        fail(
            "No DB credentials. Set OWA_E2E_DB_HOST/_USER/_PASSWORD (CI) " .
            "or run where a live " . CONFIG_FILE . " exists to reuse its server."
        );
    }
    return [
        'host'      => $c['OWA_DB_HOST'],
        'port'      => $c['OWA_DB_PORT'] ?? '3306',
        'user'      => $c['OWA_DB_USER'],
        'password'  => $c['OWA_DB_PASSWORD'] ?? '',
        // OWA_E2E_DB_TYPE wins over the live install's driver, so the whole
        // suite can be run against a different one:
        //
        //   OWA_E2E_DB_TYPE=pdo npm run test:e2e:selfhost
        //
        // Without this the local path always inherited the live config's type
        // and there was no way to exercise a second driver end to end -- which
        // is the only check that covers reporting's dynamically-built SQL, since
        // those statements do not exist until they run.
        'type'      => getenv('OWA_E2E_DB_TYPE') ?: ($c['OWA_DB_TYPE'] ?? 'mysql'),
        'live_name' => $liveName,
    ];
}

/** Parse the define()d constants out of the live config OR the backup (no OWA boot). */
function parseConfigConstants(string $repoRoot): ?array
{
    foreach ([$repoRoot . CONFIG_FILE, $repoRoot . BACKUP_FILE] as $src) {
        if (!file_exists($src)) {
            continue;
        }
        $php = file_get_contents($src);
        $out = [];
        foreach (['OWA_DB_TYPE', 'OWA_DB_NAME', 'OWA_DB_HOST', 'OWA_DB_USER', 'OWA_DB_PORT', 'OWA_DB_PASSWORD'] as $k) {
            if (preg_match('/define\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s', $php, $m)) {
                $out[$k] = $m[1];
            }
        }
        return $out;
    }
    return null;
}

/**
 * The LIVE install's DB name (the one we must never drop), or null if none.
 *
 * The authoritative source of the live name is the BACKUP: during a run the live
 * config is stashed there while a SCRATCH config occupies owa-config.php. So:
 *   - $backupOnly = true (teardown): trust ONLY the backup. If there's no backup,
 *     there is no stashed live install to protect (the CI case, or `up` never ran)
 *     -- return null so the scratch DB can be dropped. Reading owa-config.php here
 *     would wrongly report the SCRATCH db as "live" and block its own cleanup.
 *   - $backupOnly = false (setup/`up`): before we've stashed, owa-config.php still
 *     holds the live config, so fall back to it to learn the live name.
 */
function parseLiveDbName(string $repoRoot, bool $backupOnly = false): ?string
{
    $sources = $backupOnly ? [$repoRoot . BACKUP_FILE] : [$repoRoot . BACKUP_FILE, $repoRoot . CONFIG_FILE];
    foreach ($sources as $src) {
        if (!file_exists($src)) {
            continue;
        }
        $php = file_get_contents($src);
        if (preg_match('/define\(\s*[\'"]OWA_DB_NAME[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s', $php, $m)) {
            return $m[1];
        }
        return null;
    }
    return null;
}

/** Open a raw mysqli connection to the DB server (no DB selected). */
function connectServer(array $creds)
{
    $m = @mysqli_connect($creds['host'], $creds['user'], $creds['password'], '', (int) $creds['port']);
    if (!$m) {
        fail('DB connect failed: ' . mysqli_connect_error());
    }
    return $m;
}

/** Refuse any name that isn't a plain identifier or that equals the live DB. */
function assertScratchName(string $db, ?string $liveName): void
{
    if (!preg_match('/^[a-z0-9_]+$/', $db)) {
        fail("Refusing unsafe database identifier '$db'.");
    }
    if ($liveName !== null && $db === $liveName) {
        fail("Refusing to operate on the LIVE database '$db' (set OWA_E2E_DB_NAME to a scratch name).");
    }
}

/**
 * UP: stash any live config, create the scratch DB, write a fresh config pointing
 * at it + the php -S URL, and run the CLI installer to build the schema.
 */
function up(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;
    $db     = scratchDb();

    // Made here rather than left to FileEventQueue, whose mkdir() is not
    // recursive and would fail on the missing parent.
    $logDir = $repoRoot . SCRATCH_LOG_DIR;

    if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        fail('Could not create the scratch log directory: ' . $logDir);
    }

    if (file_exists($backup)) {
        fail('Stale backup ' . BACKUP_FILE . ' present -- a prior run did not tear down. Run `doctor` first.');
    }

    // Resolve creds BEFORE moving the live config (parse reads from it).
    $creds = dbCreds($repoRoot);
    assertScratchName($db, $creds['live_name']);

    // Stash the live config out of the way (so createConfigFile-style write + the
    // CLI installer see a clean slate). If there's no live config (CI), skip.
    $stashed = false;
    if (file_exists($config)) {
        if (!rename($config, $backup)) {
            fail('Failed to stash ' . CONFIG_FILE . ' -> ' . BACKUP_FILE);
        }
        $stashed = true;
    }

    // Create the scratch DB fresh (drop any leftover first).
    $m = connectServer($creds);
    mysqli_query($m, "DROP DATABASE IF EXISTS `$db`");
    if (!mysqli_query($m, "CREATE DATABASE `$db` CHARACTER SET utf8mb4")) {
        $err = mysqli_error($m);
        mysqli_close($m);
        if ($stashed) { rename($backup, $config); } // don't leave the box down
        fail("Failed to create scratch database '$db': $err");
    }
    mysqli_close($m);

    // Write the scratch config pointing at the scratch DB + php -S public URL.
    writeConfig($repoRoot, $creds, $db);

    // Run the real CLI installer to build the schema/admin/site. It reads the
    // config we just wrote and sets the admin password = OWA_DB_PASSWORD.
    $install = runInstaller($repoRoot);
    if (!preg_match('/Install Completed|Installation complete/i', $install['output'])) {
        // Roll back so a failed install doesn't strand the box.
        rollback($repoRoot, $creds, $db, $stashed);
        fail("CLI installer did not report success:\n" . $install['output']);
    }

    registerHarnessSite($repoRoot);

    return [
        'status'      => 'up',
        'scratch_db'  => $db,
        'public_url'  => publicUrl(),
        'base_url'    => baseUrl(),
        'stashed_live'=> $stashed,
    ];
}

/**
 * Register the site the tracker fixtures beacon to.
 *
 * The tracker harness pages send owa_site_id=e2e-tracker-harness, a literal
 * baked into the fixtures, and tracking now refuses events naming a site the
 * installation does not have. Until that check existed the specs relied on OWA
 * accepting an unregistered id, which is exactly the behaviour being removed.
 *
 * Written directly rather than through SiteManager. That used to be necessary
 * -- SiteManager derived site_id as md5( domain ) and could not produce an
 * arbitrary literal -- and is now merely simpler: createSite() accepts a pinned
 * identifier, but this row needs no other setup. It is otherwise identical to
 * one the admin UI creates.
 */
function registerHarnessSite(string $repoRoot): void
{
    $site_id = 'e2e-tracker-harness';

    $php = escapeshellarg(PHP_BINARY);
    $cmd = $php . ' -r ' . escapeshellarg(
        'require "' . $repoRoot . 'owa.php";'
      . ' new owa(["instance_role" => "cli"]);'
      . ' $s = owa_coreAPI::entityFactory("base.site");'
      . ' $id = $s->generateId("' . $site_id . '");'
      . ' $s->load($id);'
      . ' if (!$s->wasPersisted()) {'
      . '   $s->set("id", $id);'
      . '   $s->set("site_id", "' . $site_id . '");'
      . '   $s->set("name", "E2E tracker harness");'
      . '   $s->set("domain", "http://127.0.0.1");'
      . '   $s->create();'
      . ' }'
      . ' echo "harness site registered\n";'
    ) . ' 2>&1';

    $out = shell_exec($cmd);

    if (strpos((string) $out, 'harness site registered') === false) {
        fail("Could not register the tracker harness site:\n" . $out);
    }
}

/** Write owa-config.php from the dist template, pointed at the scratch DB + URL. */
function writeConfig(string $repoRoot, array $creds, string $db): void
{
    $config = $repoRoot . CONFIG_FILE;
    $dist   = $repoRoot . 'owa-config-dist.php';
    if (!file_exists($dist)) {
        fail('Missing owa-config-dist.php template.');
    }

    $rand = function (int $len = 64): string {
        // Printable, no quote/backslash chars that would break a single-quoted define().
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.-_@#%^*()';
        $s = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $len; $i++) {
            $s .= $alphabet[random_int(0, $max)];
        }
        return $s;
    };

    // Same placeholder swaps createConfigFile() performs (settings.php:850-882),
    // done per-line so the port swap only touches the OWA_DB_PORT line.
    $simple = [
        'yourdbtypegoeshere'          => $creds['type'],
        'yourdbnamegoeshere'          => $db,
        'yourdbhostgoeshere'          => $creds['host'],
        'yourdbusergoeshere'          => $creds['user'],
        'yourdbpasswordgoeshere'      => $creds['password'],
        'yournoncekeygoeshere'        => $rand(),
        'yournoncesaltgoeshere'       => $rand(),
        'yourauthkeygoeshere'         => $rand(),
        'yourauthsaltgoeshere'        => $rand(),
        'http://domain/path/to/owa/'  => publicUrl(),
    ];

    $out = '';
    foreach (file($dist) as $line) {
        // Scope the port replacement to the OWA_DB_PORT define line only.
        if (strpos($line, 'OWA_DB_PORT') !== false) {
            $line = str_replace('3306', addcslashes((string) $creds['port'], "\\'"), $line);
        }
        foreach ($simple as $needle => $value) {
            if (strpos($line, $needle) !== false) {
                $line = str_replace($needle, addcslashes((string) $value, "\\'"), $line);
            }
        }
        $out .= $line;
    }
    // Point the file queue and error log at this run's own directory.
    //
    // Set through the config file because both are config-file-only settings
    // (Settings::configFileOnlySettings) -- a stored value is stripped on load,
    // deliberately, so a path from a previous server cannot follow a database
    // around. setupPaths() fills them only when unset, which is what lets this
    // land.
    //
    // Spliced in BEFORE the template's closing tag, not appended: the template
    // ends by closing PHP mode, so anything after that point is not code -- it
    // is output, printed on every request and every CLI call, which corrupts the
    // JSON these helpers return.
    //
    // (The closing tag is written only as a string literal below, never in a
    // comment: a close-tag sequence ends PHP mode even inside a // comment, so
    // spelling it out here would do to this file what it warns about.)
    $override = "\n"
        . "// Added by tests/e2e/selfhost_harness.php -- keeps this run's queue and\n"
        . "// error log out of the live install's owa-data/logs/.\n"
        . "\$this->set('base', 'async_log_dir', OWA_DIR . " . var_export(SCRATCH_LOG_DIR, true) . ");\n"
        . "\$this->set('base', 'error_log_file', OWA_DIR . " . var_export(SCRATCH_LOG_DIR . 'errors.txt', true) . ");\n";

    $close = strrpos($out, '?>');
    $out = $close === false ? $out . $override : substr($out, 0, $close) . $override . substr($out, $close);

    if (file_put_contents($config, $out) === false) {
        fail('Failed to write ' . CONFIG_FILE);
    }
    @chmod($config, 0640);
}

/**
 * Delete this run's log directory.
 *
 * Scoped to SCRATCH_LOG_DIR and refuses anything else, because a wrong path here
 * would delete the live install's queue -- the very thing this exists to protect.
 */
function removeScratchLogs(string $repoRoot)
{
    $dir = $repoRoot . SCRATCH_LOG_DIR;

    if (substr(SCRATCH_LOG_DIR, 0, 9) !== 'owa-data/' || strpos(SCRATCH_LOG_DIR, 'e2e') === false) {
        return 'refused: SCRATCH_LOG_DIR is not a recognisable scratch path';
    }

    if (!is_dir($dir)) {
        return 'absent';
    }

    $removed = 0;

    foreach (['unprocessed/', 'archive/', ''] as $sub) {
        foreach (glob($dir . $sub . '*') ?: [] as $f) {
            if (is_file($f) && @unlink($f)) {
                $removed++;
            }
        }
    }

    foreach (['unprocessed/', 'archive/', ''] as $sub) {
        if (is_dir($dir . $sub)) {
            @rmdir($dir . $sub);
        }
    }

    return $removed . ' file(s)';
}

/** Shell out to the real CLI installer; return its combined output + exit code. */
function runInstaller(string $repoRoot): array
{
    $args = [
        escapeshellarg(PHP_BINARY),
        escapeshellarg($repoRoot . 'cli.php'),
        'cmd=install',
        'user_id=' . escapeshellarg(INSTALL_ADMIN_ID),
        'email_address=' . escapeshellarg(INSTALL_ADMIN_ID),
        'domain=' . escapeshellarg(INSTALL_DOMAIN),
    ];
    $cmd = implode(' ', $args) . ' 2>&1';
    $output = [];
    $code = 0;
    // Run from the repo root so cli.php resolves owa_env.php relatively.
    $cwd = getcwd();
    chdir($repoRoot);
    exec($cmd, $output, $code);
    chdir($cwd);
    return ['output' => implode("\n", $output), 'code' => $code];
}

/** Undo a partial `up`: drop the scratch DB, remove our config, restore backup. */
function rollback(string $repoRoot, array $creds, string $db, bool $stashed): void
{
    try {
        $m = connectServer($creds);
        assertScratchName($db, $creds['live_name']);
        mysqli_query($m, "DROP DATABASE IF EXISTS `$db`");
        mysqli_close($m);
    } catch (\Throwable $e) { /* best effort */ }
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;
    if (file_exists($config)) { @unlink($config); }
    if ($stashed && file_exists($backup)) { rename($backup, $config); }
}

/**
 * DOWN: drop the scratch DB, remove our scratch config, and restore the live
 * backup. Tolerant: does as much as it can and only fails if it cannot put the
 * live config back (that would leave the box down). Runs from globalTeardown, so
 * it must not throw on a cleanup hiccup and mask the real test result.
 */
function down(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;
    $db     = scratchDb();
    $done   = [];

    try {
        $creds = dbCreds($repoRoot, true); // teardown: only the backup names the live DB
        assertScratchName($db, $creds['live_name']);
        $m = connectServer($creds);
        $done['dropped_db'] = mysqli_query($m, "DROP DATABASE IF EXISTS `$db`")
            ? true : ('error: ' . mysqli_error($m));
        mysqli_close($m);
    } catch (\Throwable $e) {
        $done['dropped_db'] = 'skip: ' . $e->getMessage();
    }

    $done['scratch_logs_removed'] = removeScratchLogs($repoRoot);

    if (file_exists($backup)) {
        if (file_exists($config)) { @unlink($config); }        // remove our scratch config
        if (!rename($backup, $config)) {
            fail('CRITICAL: could not restore ' . CONFIG_FILE . ' from ' . BACKUP_FILE . ' -- the site is DOWN. Restore manually.');
        }
        $done['config_restored'] = true;
    } else {
        // No backup: CI (no live config was ever stashed). Remove our scratch
        // config so the checkout is left clean.
        if (file_exists($config)) { @unlink($config); $done['config_removed'] = true; }
        else { $done['config_restored'] = 'no backup, no config'; }
    }

    return ['status' => 'down', 'detail' => $done];
}

/** DOCTOR: best-effort recovery from an aborted run. Safe to run anytime. */
function doctor(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;
    $db     = scratchDb();
    $found  = ['config_present' => file_exists($config), 'backup_present' => file_exists($backup)];
    $fixed  = [];

    // Resolve creds + the live DB name NOW, from the backup, BEFORE the restore
    // below moves the backup away. Only the backup names the live DB (a scratch
    // config in owa-config.php would falsely report the scratch db as "live" and
    // block its own cleanup). If there's no backup there's no live install to
    // protect (CI, or `up` never ran) -> live_name is null and the scratch db
    // can be dropped.
    $creds = null;
    try {
        $creds = dbCreds($repoRoot, true);
    } catch (\Throwable $e) {
        $found['creds'] = 'skip: ' . $e->getMessage();
    }

    // A backup means a run died mid-swap: the backup is the authoritative live
    // config. Remove whatever scratch config occupies the slot and restore it.
    if (file_exists($backup)) {
        if (file_exists($config)) { @unlink($config); }
        if (rename($backup, $config)) {
            $fixed[] = 'restored live ' . CONFIG_FILE . ' from backup';
        } else {
            fail('doctor: found backup but could not restore it to ' . CONFIG_FILE);
        }
    }

    try {
        if ($creds === null) {
            // No creds from the backup; after a restore, owa-config.php now holds
            // the live config -- read it to find the server (and the live name, so
            // we still refuse to drop it).
            $creds = dbCreds($repoRoot, false);
        }
        assertScratchName($db, $creds['live_name']);
        $m = connectServer($creds);
        $r = mysqli_query($m, "SHOW DATABASES LIKE '" . mysqli_real_escape_string($m, $db) . "'");
        if ($r && mysqli_num_rows($r) > 0) {
            $fixed[] = mysqli_query($m, "DROP DATABASE `$db`") ? "dropped stale scratch DB $db" : ('drop error: ' . mysqli_error($m));
        }
        mysqli_close($m);
    } catch (\Throwable $e) {
        $found['drop_db'] = 'skip: ' . $e->getMessage();
    }

    return ['status' => 'doctor complete', 'found' => $found, 'fixed' => $fixed ?: ['nothing to fix']];
}

// ---- small helpers ----------------------------------------------------------

function out(array $result): void
{
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}

function fail(string $msg): void
{
    fwrite(STDERR, "[selfhost_harness] $msg\n");
    exit(1);
}
