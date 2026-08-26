<?php
/**
 * Provisioning harness for the OWA install-flow end-to-end tests
 * (tests/e2e/install.spec.js and install-cli.spec.js).
 *
 * Testing the installer is unusual because the installer OWNS the two pieces of
 * global state every other test depends on: the config file and the database.
 *
 *   1. DB connection comes ONLY from define()d OWA_DB_* constants in
 *      owa-config.php -- there is no getenv() seam (settings.php applyConfigConstants).
 *   2. owa_settings::createConfigFile() hard-codes its target as
 *      OWA_DIR.'owa-config.php' AND refuses to overwrite an existing one. The CLI
 *      installer additionally REQUIRES the config file to already exist.
 *
 * So an install test has no side-by-side option: it must physically move the
 * real owa-config.php out of the way, let the installer create a fresh one
 * pointing at a THROWAWAY scratch database, then drop that DB and restore the
 * original config. This script does exactly that provisioning/cleanup around the
 * browser + CLI specs, and nothing else touches the live schema.
 *
 * SAFETY: the scratch DB names are fixed sentinels (below). Every DROP in here
 * only ever targets those names -- never the live database. `stash` refuses to
 * clobber an existing backup; `restore` only acts if the backup exists. `doctor`
 * un-wedges an aborted run (a crash mid-swap leaves the live site with no config
 * file, which takes the whole install down until restored).
 *
 * Usage (from repo root):
 *   php tests/e2e/install_harness.php preflight   # assert we CAN run (connect, no stale state)
 *   php tests/e2e/install_harness.php stash        # back up owa-config.php, CREATE scratch DBs
 *   php tests/e2e/install_harness.php prepare-cli   # drop the web-written config, write the CLI scratch config
 *   php tests/e2e/install_harness.php writecli      # write a scratch config for the CLI install path
 *   php tests/e2e/install_harness.php assert-web    # verify the web-wizard scratch DB got installed
 *   php tests/e2e/install_harness.php assert-cli    # verify the CLI scratch DB got installed
 *   php tests/e2e/install_harness.php restore       # DROP scratch DBs, restore owa-config.php
 *   php tests/e2e/install_harness.php doctor         # best-effort recovery after an aborted run
 *   php tests/e2e/install_harness.php info           # print the harness's identifiers as JSON
 *
 * Each command prints a JSON status line and exits non-zero on hard failure so
 * the Playwright setup/teardown projects fail loudly.
 *
 * This file (like all of tests/) is excluded from the release tarball.
 */

// ---- Fixed identifiers (stable contract with the install specs) -------------

// Two scratch schemas: one the WEB wizard installs into (it writes its own
// config), one the CLI installer installs into (it needs a pre-written config).
const SCRATCH_DB_WEB = 'owa_e2e_install_scratch';
const SCRATCH_DB_CLI = 'owa_e2e_installcli_scratch';

// The admin user + tracked site the install specs create. Constant so the
// specs and the assert commands agree without parsing anything. LOCAL throwaway.
const INSTALL_ADMIN_ID    = 'owa-e2e-install-admin@example.test';
const INSTALL_ADMIN_PASS  = 'e2e-Install-Admin-1!';   // web wizard submits this
const INSTALL_ADMIN_EMAIL = 'owa-e2e-install-admin@example.test';
// The web wizard's site: protocol select + domain field (domain must NOT start
// with http -- installBase validation rejects it; the protocol is separate).
const INSTALL_SITE_PROTOCOL = 'https://';
const INSTALL_SITE_DOMAIN   = 'owa-e2e-install.example.test';

// The CLI installer takes user_id + email_address + domain and sets the admin
// password = OWA_DB_PASSWORD of its config. Distinct id so the two paths don't
// collide even if they ever shared a DB.
const INSTALLCLI_ADMIN_ID = 'owa-e2e-installcli-admin@example.test';
const INSTALLCLI_DOMAIN   = 'owa-e2e-installcli.example.test';

// Filenames (all resolved against the repo root at runtime).
const CONFIG_FILE  = 'owa-config.php';
const BACKUP_FILE  = 'owa-config.php.e2e-install-bak';

// -----------------------------------------------------------------------------

$repoRoot = dirname(__DIR__, 2) . '/';
$cmd = $argv[1] ?? 'info';

/**
 * ENV MODE (CI / self-served): when OWA_E2E_DB_HOST is set, the harness gets its
 * DB creds from OWA_E2E_DB_* env vars and its public_url from OWA_E2E_BASE_URL,
 * instead of parsing them out of a live owa-config.php. In this mode there is NO
 * live install to protect: there's no config to stash and no backup to restore --
 * the web wizard just writes into the (otherwise absent) owa-config.php and the
 * checkout is scrubbed clean afterwards. When OWA_E2E_DB_HOST is UNSET the harness
 * behaves exactly as before (parse the live config, stash/restore it) -- the local
 * against-live-Apache path is unchanged.
 */
function inEnvMode(): bool
{
    $h = getenv('OWA_E2E_DB_HOST');
    return $h !== false && $h !== '';
}

/** The public_url the wizard/CLI config should carry, in env mode (from base URL). */
function envPublicUrl(): string
{
    $base = getenv('OWA_E2E_BASE_URL') ?: 'http://127.0.0.1:8965/index.php';
    // public_url is the install ROOT (base URL with the trailing entry file removed).
    return preg_replace('#/[^/]*\.php.*$#', '/', $base);
}

switch ($cmd) {
    case 'preflight': out(preflight($repoRoot));               break;
    case 'stash':     out(stash($repoRoot));                    break;
    case 'prepare-cli': out(prepareCli($repoRoot));             break;
    case 'webform':   out(webForm($repoRoot));                  break;
    case 'writecli':  out(writeCliConfig($repoRoot));           break;
    // The web wizard SUBMITS Europe/London -- deliberately not the shipped
    // default, so the check fails if the submitted value is ignored.
    //
    // The CLI installer takes its configuration from owa-config.php and asks for
    // nothing, so it persists NO timezone at all: get() falls through to the
    // config file and then the default. Passing null skips the check rather than
    // asserting a value the CLI path never writes.
    case 'assert-web':out(assertInstalled($repoRoot, SCRATCH_DB_WEB, INSTALL_ADMIN_ID, 'web', 'Europe/London')); break;
    case 'assert-cli':out(assertInstalled($repoRoot, SCRATCH_DB_CLI, INSTALLCLI_ADMIN_ID, 'cli', null)); break;
    case 'restore':   out(restore($repoRoot));                  break;
    case 'doctor':    out(doctor($repoRoot));                   break;
    case 'info':      out(info());                              break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: preflight | stash | writecli | assert-web | assert-cli | restore | doctor | info\n");
        exit(2);
}

// -----------------------------------------------------------------------------

function info(): array
{
    return [
        'scratch_db_web'     => SCRATCH_DB_WEB,
        'scratch_db_cli'     => SCRATCH_DB_CLI,
        'install_admin_id'   => INSTALL_ADMIN_ID,
        'install_admin_pass' => INSTALL_ADMIN_PASS,
        'install_site'       => INSTALL_SITE_PROTOCOL . INSTALL_SITE_DOMAIN,
        'installcli_admin_id'=> INSTALLCLI_ADMIN_ID,
        'installcli_domain'  => INSTALLCLI_DOMAIN,
    ];
}

/**
 * Read the LIVE DB credentials straight out of owa-config.php (or the backup, if
 * we've already stashed) by parsing the define() lines. We deliberately do NOT
 * boot OWA here: after stash the config file is gone, and we still need the RDS
 * host/user/password to CREATE/DROP the scratch schemas and to template the CLI
 * config. Returns [host, port, user, password, type].
 */
function liveDbCreds(string $repoRoot): array
{
    // ENV MODE: creds come straight from the environment (there's no live config).
    if (inEnvMode()) {
        return [
            'host'       => getenv('OWA_E2E_DB_HOST'),
            'port'       => getenv('OWA_E2E_DB_PORT') ?: '3306',
            'user'       => getenv('OWA_E2E_DB_USER') ?: 'root',
            'password'   => getenv('OWA_E2E_DB_PASSWORD') !== false ? getenv('OWA_E2E_DB_PASSWORD') : '',
            'type'       => getenv('OWA_E2E_DB_TYPE') ?: 'mysql',
            'name'       => null, // no live DB in env mode
            'public_url' => envPublicUrl(),
            '_source'    => 'env',
        ];
    }

    $candidates = [$repoRoot . CONFIG_FILE, $repoRoot . BACKUP_FILE];
    $src = null;
    foreach ($candidates as $c) {
        if (file_exists($c)) { $src = $c; break; }
    }
    if ($src === null) {
        fail('Cannot find live DB creds: neither ' . CONFIG_FILE . ' nor ' . BACKUP_FILE . ' exists.');
    }
    $php = file_get_contents($src);
    $grab = function (string $const) use ($php) {
        // Match define('CONST', '...'); tolerant of quoting/whitespace.
        if (preg_match('/define\(\s*[\'"]' . preg_quote($const, '/') . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s', $php, $m)) {
            return $m[1];
        }
        return null;
    };
    $creds = [
        'host'     => $grab('OWA_DB_HOST'),
        'port'     => $grab('OWA_DB_PORT') ?: '3306',
        'user'     => $grab('OWA_DB_USER'),
        'password' => $grab('OWA_DB_PASSWORD'),
        'type'     => $grab('OWA_DB_TYPE') ?: 'mysql',
        'name'     => $grab('OWA_DB_NAME'),
        'public_url' => $grab('OWA_PUBLIC_URL'),
    ];
    if (!$creds['host'] || !$creds['user']) {
        fail("Could not parse DB creds from $src");
    }
    $creds['_source'] = $src;
    return $creds;
}

/** Open a raw mysqli connection to the RDS server (no DB selected). */
function connectServer(array $creds)
{
    $m = @mysqli_connect($creds['host'], $creds['user'], $creds['password'], '', (int) $creds['port']);
    if (!$m) {
        fail('DB connect failed: ' . mysqli_connect_error());
    }
    return $m;
}

/** Backtick-safe identifier guard: scratch DB names are fixed sentinels, but assert anyway. */
function assertScratchName(string $db): void
{
    if ($db !== SCRATCH_DB_WEB && $db !== SCRATCH_DB_CLI) {
        fail("Refusing to operate on non-scratch database '$db'.");
    }
    if (!preg_match('/^[a-z0-9_]+$/', $db)) {
        fail("Refusing unsafe database identifier '$db'.");
    }
}

/**
 * PREFLIGHT: prove we can run before we touch anything. Confirms the live config
 * exists, we can connect to RDS, and there's NO stale state (no leftover backup,
 * no leftover scratch DBs from an aborted run). Fails loudly so CI stops here
 * rather than half-swapping the config.
 */
function preflight(string $repoRoot): array
{
    $issues = [];
    // In env mode there is NO live config to require: the wizard writes one into
    // an empty slot. A leftover config from a previous CI run is stale and must go.
    if (!inEnvMode() && !file_exists($repoRoot . CONFIG_FILE)) {
        $issues[] = 'live ' . CONFIG_FILE . ' is missing (is the site installed? did a prior run fail to restore? try `doctor`)';
    }
    if (inEnvMode() && file_exists($repoRoot . CONFIG_FILE)) {
        $issues[] = 'stale ' . CONFIG_FILE . ' present in env mode (a prior CI run left one -- run `doctor` first)';
    }
    if (file_exists($repoRoot . BACKUP_FILE)) {
        $issues[] = 'stale backup ' . BACKUP_FILE . ' present (a prior run did not restore -- run `doctor` first)';
    }
    $creds = liveDbCreds($repoRoot);
    $m = connectServer($creds);
    foreach ([SCRATCH_DB_WEB, SCRATCH_DB_CLI] as $db) {
        $r = mysqli_query($m, "SHOW DATABASES LIKE '" . mysqli_real_escape_string($m, $db) . "'");
        if ($r && mysqli_num_rows($r) > 0) {
            $issues[] = "stale scratch database '$db' present (run `doctor` first)";
        }
    }
    mysqli_close($m);

    if ($issues) {
        fail("preflight failed:\n  - " . implode("\n  - ", $issues));
    }
    return ['status' => 'preflight ok', 'db_host' => $creds['host']];
}

/**
 * STASH: back up the live owa-config.php out of the way (so the web wizard's
 * createConfigFile() will write a fresh one), and CREATE both empty scratch
 * databases the two install paths install into.
 */
function stash(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;

    if (file_exists($backup)) {
        fail('Backup ' . BACKUP_FILE . ' already exists -- refusing to overwrite. Run `doctor` to recover from a prior aborted run.');
    }
    // Read creds (env mode: from the environment; local mode: from the live config
    // BEFORE moving it).
    $creds = liveDbCreds($repoRoot);

    // ENV MODE: there is no live config to stash -- the wizard writes into the
    // empty slot. Just make sure no stale config is squatting there.
    if (inEnvMode()) {
        if (file_exists($config)) {
            fail('stash: stale ' . CONFIG_FILE . ' present in env mode -- run `doctor` first.');
        }
    } else {
        if (!file_exists($config)) {
            fail('No ' . CONFIG_FILE . ' to stash (site not installed?).');
        }
        if (!rename($config, $backup)) {
            fail('Failed to move ' . CONFIG_FILE . ' to ' . BACKUP_FILE);
        }
    }

    // Create the two scratch schemas fresh (empty -- the installer builds them).
    $m = connectServer($creds);
    $created = [];
    foreach ([SCRATCH_DB_WEB, SCRATCH_DB_CLI] as $db) {
        assertScratchName($db);
        // Drop-then-create so a partial leftover can't wedge us (preflight
        // already guards, but be defensive).
        mysqli_query($m, "DROP DATABASE IF EXISTS `$db`");
        if (!mysqli_query($m, "CREATE DATABASE `$db` CHARACTER SET utf8mb4")) {
            mysqli_close($m);
            // Try to undo the config move so we don't leave the site down (no-op
            // in env mode, where nothing was stashed).
            if (!inEnvMode() && file_exists($backup)) {
                rename($backup, $config);
            }
            fail("Failed to create scratch database '$db': " . mysqli_error($m));
        }
        $created[] = $db;
    }
    mysqli_close($m);

    return [
        'status'         => 'stashed',
        'config_backup'  => BACKUP_FILE,
        'scratch_dbs'    => $created,
    ];
}

/**
 * WRITECLI: write a scratch owa-config.php pointing at SCRATCH_DB_CLI, for the
 * CLI install path (cli.php cmd=install REQUIRES a pre-existing config file; it
 * does not write one). Reuses the live RDS host/user/password + generates fresh
 * random keys/salts (mirrors what createConfigFile would do). Must be called
 * while the live config is stashed (i.e. after `stash`, before the CLI spec) and
 * removed again before the web spec runs -- the web wizard needs NO config file.
 *
 * NOTE: the CLI installer sets the admin password = OWA_DB_PASSWORD, so the
 * assert step matches on the admin USER existing, not on a known password.
 */
function writeCliConfig(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    if (file_exists($config)) {
        fail(CONFIG_FILE . ' already exists -- writecli expected it stashed. Run `stash` first.');
    }
    $dist = $repoRoot . 'owa-config-dist.php';
    if (!file_exists($dist)) {
        fail('Missing owa-config-dist.php template.');
    }
    $creds = liveDbCreds($repoRoot); // reads from the backup

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

    // Build the config from the dist template with the same placeholder swaps
    // createConfigFile() performs (settings.php:850-870), pointed at the CLI
    // scratch DB. public_url reuses the live one (host is the same install).
    $lines = file($dist);
    $publicUrl = $creds['public_url'] ?: 'https://test.openwebanalytics.com/owa/';
    $repl = [
        "yourdbtypegoeshere"     => $creds['type'],
        "yourdbnamegoeshere"     => SCRATCH_DB_CLI,
        "yourdbhostgoeshere"     => $creds['host'],
        "yourdbusergoeshere"     => $creds['user'],
        "yourdbpasswordgoeshere" => $creds['password'],
        "yournoncekeygoeshere"   => $rand(),
        "yournoncesaltgoeshere"  => $rand(),
        "yourauthkeygoeshere"    => $rand(),
        "yourauthsaltgoeshere"   => $rand(),
        "http://domain/path/to/owa/" => $publicUrl,
    ];
    $out = '';
    foreach ($lines as $line) {
        // Port: dist has define('OWA_DB_PORT', '3306'); keep as-is (matches live 3306).
        foreach ($repl as $needle => $value) {
            if (strpos($line, $needle) !== false) {
                $line = str_replace($needle, addcslashes((string) $value, "\\'"), $line);
            }
        }
        $out .= $line;
    }
    if (file_put_contents($config, $out) === false) {
        fail('Failed to write scratch ' . CONFIG_FILE);
    }
    @chmod($config, 0640);

    return ['status' => 'cli config written', 'db_name' => SCRATCH_DB_CLI];
}

/**
 * WEBFORM: the exact values the browser must type into the wizard's config-entry
 * form -- the LIVE RDS host/user/password/type/port + public_url (read from the
 * stashed backup), but with the db_name swapped to the WEB scratch schema. The
 * wizard then writes an owa-config.php from these and installs into the scratch
 * DB. Emitted as JSON for the Playwright spec.
 */
function webForm(string $repoRoot): array
{
    $creds = liveDbCreds($repoRoot);
    return [
        'db_type'    => $creds['type'],
        'db_host'    => $creds['host'],
        'db_port'    => $creds['port'],
        'db_name'    => SCRATCH_DB_WEB,               // install into the scratch schema
        'db_user'    => $creds['user'],
        'db_password'=> $creds['password'],
        'public_url' => $creds['public_url'] ?: 'https://test.openwebanalytics.com/owa/',
    ];
}

/**
 * PREPARE-CLI: transition from the web-wizard path to the CLI path. The web
 * wizard just WROTE an owa-config.php (pointing at the web scratch DB); the CLI
 * path needs its OWN config pointing at the CLI scratch DB. Remove the wizard's
 * config, then write the CLI one. In local mode the live config stays safely in
 * the backup throughout; in env mode there is no backup (nothing to protect). Must
 * run between the web spec and the CLI spec.
 */
function prepareCli(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;
    // Local mode: a backup MUST exist (it holds the stashed live config), proving
    // stash ran. Env mode: there's no live config, so no backup -- skip the check.
    if (!inEnvMode() && !file_exists($backup)) {
        fail('prepare-cli: no backup present -- stash must run first.');
    }
    // Remove the wizard-written config (NOT the backup) so writecli can proceed.
    if (file_exists($config)) {
        if (!@unlink($config)) {
            fail('prepare-cli: could not remove the wizard-written ' . CONFIG_FILE);
        }
    }
    $res = writeCliConfig($repoRoot);
    $res['status'] = 'cli path prepared';
    return $res;
}

/**
 * ASSERT: connect to the given scratch DB and verify the installer actually
 * created a working install: the owa_user table exists with an admin row for the
 * expected user_id, an owa_site row exists, and the owa_configuration table holds
 * install_complete = true. Direct SQL (no OWA boot) so it works regardless of
 * which config file is currently in place.
 */
function assertInstalled(string $repoRoot, string $db, string $expectedAdminId, string $path, ?string $expectedTimezone = null): array
{
    assertScratchName($db);
    $creds = liveDbCreds($repoRoot);
    $m = connectServer($creds);
    if (!mysqli_select_db($m, $db)) {
        mysqli_close($m);
        fail("Scratch DB '$db' not selectable: " . mysqli_error($m));
    }

    $checks = [];

    /*
     * 0. The wizard WROTE a config file, and it points at the database the form
     *    was given.
     *
     * Only the web path: the CLI installer requires a config to already exist
     * and `writecli` supplies it, so there the file proves nothing about the
     * installer. On the web path it is the installer's own output, and the one
     * step with nothing else to vouch for it -- a wizard that created a working
     * schema but no config leaves an install that cannot boot, and every other
     * check here would still pass.
     */
    if ($path === 'web') {
        $configPath = $repoRoot . CONFIG_FILE;
        $checks['config_file_written'] = file_exists($configPath);
        $checks['config_names_scratch_db'] = false;

        if ($checks['config_file_written']) {
            $written = (string) file_get_contents($configPath);
            $checks['config_names_scratch_db'] = (bool) preg_match(
                "/define\s*\(\s*['\"]OWA_DB_NAME['\"]\s*,\s*['\"]"
                    . preg_quote($db, '/') . "['\"]/",
                $written);
        }
    }

    // 1. Core tables exist.
    foreach (['owa_user', 'owa_site', 'owa_configuration'] as $t) {
        $r = mysqli_query($m, "SHOW TABLES LIKE '" . mysqli_real_escape_string($m, $t) . "'");
        $checks["table_$t"] = ($r && mysqli_num_rows($r) > 0);
    }

    // 2. Admin user row for the expected id.
    $checks['admin_user'] = false;
    if ($checks['table_owa_user']) {
        $eid = mysqli_real_escape_string($m, $expectedAdminId);
        $r = mysqli_query($m, "SELECT role FROM owa_user WHERE user_id = '$eid' LIMIT 1");
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            $checks['admin_user'] = ($row['role'] === 'admin');
        }
    }

    // 3. At least one tracked site.
    $checks['has_site'] = false;
    if ($checks['table_owa_site']) {
        $r = mysqli_query($m, "SELECT COUNT(*) AS c FROM owa_site");
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            $checks['has_site'] = ((int) $row['c'] > 0);
        }
    }

    // 4. install_complete flag serialized into owa_configuration.
    $checks['install_complete'] = false;
    if ($checks['table_owa_configuration']) {
        $r = mysqli_query($m, "SELECT settings FROM owa_configuration ORDER BY id DESC LIMIT 1");
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            // Settings are a serialized PHP blob; a loose contains check avoids
            // coupling to the exact nesting while still proving the flag is set.
            $blob = (string) $row['settings'];
            $checks['install_complete'] =
                (strpos($blob, 'install_complete') !== false)
                && (bool) preg_match('/install_complete[^;]*;b:1/', $blob);

            /*
             * 4b. The timezone the wizard was given is the one that got stored.
             *
             * Asserted because the choice is NOT retroactive -- yyyymmdd and the
             * date-part columns are derived in this zone and written into every
             * fact row -- so a wizard that collects it and drops it on the floor
             * is worse than one that never asked: the operator believes they
             * chose. Checked against the value the spec submits, and that value
             * is deliberately NOT the shipped default, so this fails if the
             * submitted timezone is ignored.
             */
            if ( $expectedTimezone !== null ) {

                $checks['timezone_stored'] =
                    (bool) preg_match('/timezone[^;]*;s:\d+:"' . preg_quote($expectedTimezone, '/') . '"/', $blob);
            }
        }
    }
    // 5. Fact tables are born partitioned, with a lead of future periods.
    //
    // This is the one part of the schema whose absence is silent: if the
    // partition clause is never emitted -- a driver that cannot partition, an
    // entity that stops declaring its column, a clause dropped from
    // createTable() -- the install still succeeds, every other check above
    // still passes, and the installation simply has no retention story. It
    // would surface much later, as a DELETE nobody can afford to run.
    //
    // Asserted on owa_request as the representative fact table: it is the one
    // every installation writes to first.
    $checks['request_partitioned'] = false;
    $checks['request_has_lead']    = false;

    $r = mysqli_query($m, "SELECT PARTITION_NAME, PARTITION_DESCRIPTION
        FROM information_schema.PARTITIONS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'owa_request'
          AND PARTITION_NAME IS NOT NULL");

    if ($r) {
        $bounds = [];
        $catchAll = false;

        while ($row = mysqli_fetch_assoc($r)) {
            if (strtoupper(trim((string) $row['PARTITION_DESCRIPTION'])) === 'MAXVALUE') {
                $catchAll = true;
                continue;
            }
            $bounds[] = (string) $row['PARTITION_DESCRIPTION'];
        }

        // Partitioned at all, and with somewhere for a write past the last
        // boundary to go -- without the catch-all, tracking stops dead the day
        // the range runs out.
        $checks['request_partitioned'] = ($bounds && $catchAll);

        // And covering future periods, not just today. A single current period
        // would mean everything from next month landing in the catch-all,
        // where no retention cutoff can ever reach it.
        if ($bounds) {
            sort($bounds);
            $checks['request_has_lead'] = (end($bounds) > date('Ymd', strtotime('+60 days')));
        }
    }

    mysqli_close($m);

    $ok = !in_array(false, $checks, true);
    $result = ['status' => $ok ? 'installed' : 'INCOMPLETE', 'path' => $path, 'db' => $db, 'checks' => $checks];
    if (!$ok) {
        fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
    return $result;
}

/**
 * RESTORE: DROP both scratch databases and move the live owa-config.php back.
 * Also removes any scratch config the wizard/writecli left in place. Designed to
 * run in the Playwright teardown project (which runs even if the specs failed),
 * so it is tolerant: it does as much cleanup as it can and only fails if it
 * cannot put the live config back (that would leave the site down).
 */
function restore(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;
    $done = [];

    // Drop scratch DBs first (creds still parseable from either config or backup).
    try {
        $creds = liveDbCreds($repoRoot);
        $m = connectServer($creds);
        foreach ([SCRATCH_DB_WEB, SCRATCH_DB_CLI] as $db) {
            assertScratchName($db);
            if (mysqli_query($m, "DROP DATABASE IF EXISTS `$db`")) {
                $done["dropped_$db"] = true;
            } else {
                $done["dropped_$db"] = 'error: ' . mysqli_error($m);
            }
        }
        mysqli_close($m);
    } catch (\Throwable $e) {
        $done['drop_dbs'] = 'skip: ' . $e->getMessage();
    }

    // ENV MODE: there was no live config to protect -- the wizard/CLI wrote one
    // into an empty slot. Just scrub it so the checkout is left clean.
    if (inEnvMode()) {
        if (file_exists($config)) {
            @unlink($config);
            $done['config_removed'] = true;
        } else {
            $done['config_removed'] = 'nothing to remove';
        }
        return ['status' => 'restored', 'detail' => $done];
    }

    // LOCAL MODE: restore the live config. If a scratch config is sitting in its
    // place (written by writecli or the wizard), remove it before renaming the backup.
    if (file_exists($backup)) {
        if (file_exists($config)) {
            @unlink($config);
        }
        if (!rename($backup, $config)) {
            fail('CRITICAL: could not restore ' . CONFIG_FILE . ' from ' . BACKUP_FILE . ' -- the site is DOWN. Restore manually.');
        }
        $done['config_restored'] = true;
    } else {
        // No backup: either restore already ran, or stash never did. If there's
        // also no live config, that's a problem the doctor should look at.
        $done['config_restored'] = file_exists($config) ? 'already in place' : 'MISSING (no backup, no config)';
    }

    return ['status' => 'restored', 'detail' => $done];
}

/**
 * DOCTOR: best-effort recovery from an aborted run. Safe to run anytime. Drops
 * the scratch DBs and, if the live config is missing but a backup exists,
 * restores it. Never creates anything. Prints what it found/fixed.
 */
function doctor(string $repoRoot): array
{
    $config = $repoRoot . CONFIG_FILE;
    $backup = $repoRoot . BACKUP_FILE;
    $found = [];
    $fixed = [];

    $configPresent = file_exists($config);
    $backupPresent = file_exists($backup);
    $found['config_present'] = $configPresent;
    $found['backup_present'] = $backupPresent;

    // ENV MODE: there's no backup and no live install -- a leftover config is just
    // a stale wizard/CLI write from an aborted CI run. Scrub it.
    if (inEnvMode()) {
        if ($configPresent) {
            @unlink($config);
            $fixed[] = 'removed stale ' . CONFIG_FILE . ' (env mode)';
        }
    }
    // LOCAL MODE: if the live config is gone but we have a backup, the run died
    // mid-swap: put it back. If a scratch config is in place AND a backup exists,
    // the backup is the real one -- prefer it.
    elseif ($backupPresent) {
        if ($configPresent) {
            // A scratch/wizard config is occupying the slot. The backup is the
            // authoritative live config -> replace.
            @unlink($config);
        }
        if (rename($backup, $config)) {
            $fixed[] = 'restored live ' . CONFIG_FILE . ' from backup';
        } else {
            fail('doctor: found backup but could not restore it to ' . CONFIG_FILE);
        }
    }

    // Drop any leftover scratch DBs.
    try {
        $creds = liveDbCreds($repoRoot);
        $m = connectServer($creds);
        foreach ([SCRATCH_DB_WEB, SCRATCH_DB_CLI] as $db) {
            assertScratchName($db);
            $r = mysqli_query($m, "SHOW DATABASES LIKE '" . mysqli_real_escape_string($m, $db) . "'");
            if ($r && mysqli_num_rows($r) > 0) {
                if (mysqli_query($m, "DROP DATABASE `$db`")) {
                    $fixed[] = "dropped stale scratch DB $db";
                } else {
                    $found["drop_$db"] = 'error: ' . mysqli_error($m);
                }
            }
        }
        mysqli_close($m);
    } catch (\Throwable $e) {
        $found['drop_dbs'] = 'skip: ' . $e->getMessage();
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
    fwrite(STDERR, "[install_harness] $msg\n");
    exit(1);
}
