<?php
/**
 * Automatic updates, end to end: real releases, a real install, a real
 * database, and nothing of OWA replaced.
 *
 * It builds release packages from this checkout's HEAD exactly as the Release
 * Packager does (.github/scripts/release-tarball.sh), installs the first,
 * serves the next from a GitHub-shaped release listing over HTTPS on
 * 127.0.0.1, and lets the installation update itself with the commands its
 * cron entry runs:
 *
 *   php cli.php cmd=release-check    Step 1: listing, download, SHA-256, unpack, preflight, staged.json
 *   php cli.php cmd=schedule-run     Steps 2-4: hand-off, copy, the new release's boot, cmd=update, finish
 *
 * The HTTPS server's certificate is signed by a CA made for the run, which
 * each cli.php trusts through PHP's curl.cainfo. OWA is pointed at the
 * listing by OWA_AUTO_UPDATE_RELEASES_URL, the config-only setting any
 * installation has. No code path is test-only.
 *
 * Scenarios, each on a fresh install of 2.0.90 and a fresh database:
 *
 *   installs         2.0.91 stages, installs and runs its schema update; the
 *                    installation's own files stay, what 2.0.91 dropped goes
 *   wrong-digest     the listing's SHA-256 is not the tarball's: nothing staged
 *   unmet            2.0.91 needs PHP 99: staged, never installed
 *   does-not-boot    2.0.91's owa.php does not parse: undone, 2.0.90 runs
 *   schema-fails     2.0.91's schema update fails: its files stay, the error is recorded
 *
 * and the failure cases of the wiki's Automatic Updates page, each with the
 * recovery it promises where there is one: unreachable, asset-replaced,
 * unwritable-directory, config-refused, manual-install,
 * two-updates-directories, major-release, passwordless-database, copy-fails, killed-mid-copy (the
 * web, the REST API and the CLI while it runs, then the take-over),
 * newer-release-after-a-failure and installed-past.
 *
 * Needs: a built checkout (npm run build), composer, tar, openssl, python3,
 * and a MySQL or MariaDB server named by OWA_E2E_DB_HOST, _PORT, _USER,
 * _PASSWORD and _TYPE. The database is OWA_E2E_DB_NAME, default
 * owa_self_update_scratch, and must read as scratch (scratch_guard.php): it
 * is dropped and created for every scenario.
 *
 * E-MAIL: every notice is e-mailed to admin@example.test through an SMTP
 * server on a closed port of 127.0.0.1, so a send fails on the spot. The
 * constants are checked as defined before any command runs.
 *
 * The packages are built in OWA_SELF_UPDATE_DIR (default a new directory in
 * the system temp directory), about 300 MB. It is deleted after a run that
 * passes, unless OWA_SELF_UPDATE_KEEP is set.
 *
 * Usage, from the repository root: php tests/tools/self_update_e2e.php [scenario ...]
 */

require __DIR__ . '/scratch_guard.php';

const FROM = '2.0.90';
const TO   = '2.0.91';

$repo = dirname( __DIR__, 2 );
$db   = [
    'host'     => getenv( 'OWA_E2E_DB_HOST' ) ?: '127.0.0.1',
    'port'     => getenv( 'OWA_E2E_DB_PORT' ) ?: '3306',
    'user'     => getenv( 'OWA_E2E_DB_USER' ) ?: 'root',
    'password' => (string) getenv( 'OWA_E2E_DB_PASSWORD' ),
    'type'     => getenv( 'OWA_E2E_DB_TYPE' ) ?: 'mysql',
    'name'     => getenv( 'OWA_E2E_DB_NAME' ) ?: 'owa_self_update_scratch',
];

if ( ! owa_looks_like_scratch_db( $db['name'] ) || ! preg_match( '/^[a-z0-9_]+$/', $db['name'] ) ) {

    fwrite( STDERR, "refusing: {$db['name']} does not read as a scratch database; every scenario drops it.\n" );
    exit( 2 );
}

foreach ( [ 'public/base/dist', 'modules/Base/build/owa.tracker.manifest.json' ] as $built ) {

    if ( ! file_exists( "$repo/$built" ) ) {

        fwrite( STDERR, "$built is missing: run npm run build first.\n" );
        exit( 2 );
    }
}

$work = rtrim( getenv( 'OWA_SELF_UPDATE_DIR' ) ?: sys_get_temp_dir() . '/owa-self-update-' . bin2hex( random_bytes( 4 ) ), '/' );
@mkdir( $work, 0755, true );

$failures = 0;
$server   = (object) [ 'proc' => null, 'web' => null ];

register_shutdown_function( static function () use ( $server ) {

    foreach ( [ $server->proc, $server->web ] as $p ) {

        if ( is_resource( $p ) ) {

            proc_terminate( $p );
        }
    }
} );

// ---- helpers ---------------------------------------------------------------

function say( $line ) {

    fwrite( STDOUT, $line . "\n" );
}

/** @return array{0: int, 1: string} */
function run( $cmd, $cwd = null ) {

    $p = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'redirect', 1 ] ], $pipes, $cwd );
    $out = stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    $code = proc_close( $p );

    // What the command changed on disk, not what this process last saw.
    clearstatcache();

    return [ $code, (string) $out ];
}

function must( $cmd, $cwd = null ) {

    [ $code, $out ] = run( $cmd, $cwd );

    if ( $code !== 0 ) {

        throw new RuntimeException( "$cmd exited $code:\n" . substr( $out, -2000 ) );
    }

    return $out;
}

function check( $ok, $what ) {

    if ( ! $ok ) {

        throw new RuntimeException( $what );
    }
}

function freePort() {

    $s    = stream_socket_server( 'tcp://127.0.0.1:0' );
    $name = stream_socket_get_name( $s, false );
    fclose( $s );

    return (int) substr( (string) $name, strrpos( (string) $name, ':' ) + 1 );
}

function sql( array $db ) {

    $m = new mysqli( $db['host'], $db['user'], $db['password'], '', (int) $db['port'] );
    $m->set_charset( 'utf8mb4' );

    return $m;
}

/** The auto_update notices raised, by source_key. */
function notices( array $db ) {

    $m   = sql( $db );
    $out = [];
    $res = $m->query( sprintf( "SELECT source_key, severity, email_status, body FROM `%s`.owa_notification WHERE source = 'auto_update'", $db['name'] ) );

    foreach ( $res ? $res->fetch_all( MYSQLI_ASSOC ) : [] as $row ) {

        $out[ $row['source_key'] ] = $row;
    }

    $m->close();

    return $out;
}

function hasNotice( array $notices, $prefix ) {

    foreach ( array_keys( $notices ) as $key ) {

        if ( str_starts_with( (string) $key, $prefix ) ) {

            return true;
        }
    }

    return false;
}

function envVersion( $site ) {

    preg_match( "/const VERSION = '([^']+)';/", (string) file_get_contents( "$site/Core/Env.php" ), $m );

    return $m[1] ?? null;
}

/** The one updates-<id>/ directory, or null. */
function updatesDir( $site ) {

    $dirs = glob( "$site/owa-data/updates-*", GLOB_ONLYDIR ) ?: [];

    return $dirs[0] ?? null;
}

function json( $file ) {

    return is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
}

// ---- the build -------------------------------------------------------------

/** HEAD as the Release Packager checks it out, with production vendor/ and this checkout's build output. */
function buildTree( $repo, $work ) {

    $build = "$work/build";

    if ( is_file( "$build/.complete" ) ) {

        return $build;
    }

    must( 'rm -rf ' . escapeshellarg( $build ) );
    mkdir( $build, 0755, true );
    must( sprintf( 'git -C %s archive --format=tar HEAD | tar -x -C %s', escapeshellarg( $repo ), escapeshellarg( $build ) ) );
    must( sprintf( 'composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet --working-dir=%s', escapeshellarg( $build ) ) );

    // public/ and the tracker manifest are build output, as npm run build leaves them; the bundles are an installation's.
    must( sprintf( 'cp -a %s %s', escapeshellarg( "$repo/public" ), escapeshellarg( "$build/" ) ) );
    must( 'rm -rf ' . escapeshellarg( "$build/public/tracker" ) );
    must( sprintf( 'cp -a %s %s', escapeshellarg( "$repo/modules/Base/build" ), escapeshellarg( "$build/modules/Base/" ) ) );

    touch( "$build/.complete" );

    return $build;
}

/**
 * A release package: the build at $version, changed by $edit, its release.json
 * written as npm run build writes it, then $after (for release.json itself).
 *
 * @return array{file: string, sha256: string, size: int}
 */
function package( $build, $work, $name, $version, ?callable $edit = null, ?callable $after = null ) {

    $tree = "$work/pkg-$name";
    must( 'rm -rf ' . escapeshellarg( $tree ) );
    must( sprintf( 'cp -a %s %s', escapeshellarg( $build ), escapeshellarg( $tree ) ) );
    @unlink( "$tree/.complete" );

    $env = (string) file_get_contents( "$tree/Core/Env.php" );
    check( str_contains( $env, "const VERSION = 'master';" ), 'Core/Env.php does not say master, so it cannot be versioned as the packager does' );
    file_put_contents( "$tree/Core/Env.php", str_replace( "const VERSION = 'master';", "const VERSION = '$version';", $env ) );

    if ( $edit ) {

        $edit( $tree );
    }

    must( 'php cli.php cmd=version-info --file=release.json', $tree );

    if ( $after ) {

        $after( $tree );
    }

    must( sprintf( 'bash %s %s', escapeshellarg( dirname( __DIR__, 2 ) . '/.github/scripts/release-tarball.sh' ), escapeshellarg( $version ) ), $tree );

    $file = "$work/tars/$name/owa_{$version}_packaged.tar";
    @mkdir( dirname( $file ), 0755, true );
    rename( "$tree/owa_{$version}_packaged.tar", $file );
    must( 'rm -rf ' . escapeshellarg( $tree ) );

    return [ 'file' => $file, 'sha256' => hash_file( 'sha256', $file ), 'size' => filesize( $file ) ];
}

/** A schema update for Base in the release, one past the release's own: up() returns $succeeds; $manual is its manual_install. */
function withSchemaUpdate( $succeeds, $manual = '' ) {

    return static function ( $tree ) use ( $succeeds, $manual ) {

        $module = (string) file_get_contents( "$tree/modules/Base/Module.php" );
        check( preg_match( '/public \$required_schema_version = (\d+);/', $module, $m ) === 1, 'Base declares no required_schema_version' );
        $next = (int) $m[1] + 1;

        file_put_contents( "$tree/modules/Base/Module.php", str_replace( $m[0], "public \$required_schema_version = $next;", $module ) );
        file_put_contents( sprintf( '%s/modules/Base/Update/Update%03d.php', $tree, $next ), sprintf( <<<'PHP'
<?php
namespace OWA\Module\Base\Update;

/** The end-to-end test's update: changes nothing. */
class Update%03d extends \OWA\Core\Update {

    public $schema_version = %d;

    public $manual_install = %s;

    public function up( $force = false ) {

        return %s;
    }

    public function down() {

        return true;
    }
}

PHP, $next, $next, var_export( $manual, true ), $succeeds ? 'true' : 'false' ) );
    };
}

// ---- the HTTPS server ------------------------------------------------------

function tls( $work ) {

    $d = "$work/tls";
    @mkdir( $d, 0755, true );

    if ( ! is_file( "$d/server.pem" ) ) {

        must( 'openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=owa-self-update-test-ca'
            . ' -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign'
            . ' -keyout ca.key -out ca.pem 2>&1', $d );
        must( 'openssl req -newkey rsa:2048 -nodes -subj /CN=127.0.0.1 -keyout server.key -out server.csr 2>&1', $d );
        file_put_contents( "$d/ext", "subjectAltName=IP:127.0.0.1\n" );
        must( 'openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 1 -extfile ext -out server.pem 2>&1', $d );
    }

    file_put_contents( "$d/serve.py", <<<'PY'
import functools, http.server, ssl, sys

port, root, cert, key = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]

class Quiet(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass

server = http.server.ThreadingHTTPServer(('127.0.0.1', port), functools.partial(Quiet, directory=root))
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(cert, key)
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
PY );

    return $d;
}

/** @return resource the server process */
function serve( $tls, $root, $port ) {

    $p = proc_open( [ 'python3', '-I', "$tls/serve.py", (string) $port, $root, "$tls/server.pem", "$tls/server.key" ],
        [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', "$tls/serve.log", 'a' ] ], $pipes );

    for ( $i = 0; $i < 50; $i++ ) {

        if ( $s = @stream_socket_client( "tcp://127.0.0.1:$port", $errno, $errstr, 0.2 ) ) {

            fclose( $s );

            return $p;
        }

        usleep( 100000 );
    }

    throw new RuntimeException( 'The HTTPS server did not start: ' . @file_get_contents( "$tls/serve.log" ) );
}

/** The listing GitHub would give for one release, and its tarball, in $www. */
function publish( $www, $base, array $pkg, $version, $digest = null ) {

    must( 'rm -rf ' . escapeshellarg( $www ) );
    mkdir( "$www/download", 0755, true );
    copy( $pkg['file'], "$www/download/owa_{$version}_packaged.tar" );

    file_put_contents( "$www/releases.json", json_encode( [ [
        'id'           => 9000000 + (int) str_replace( '.', '', $version ),
        'tag_name'     => $version,
        'name'         => "OWA $version",
        'draft'        => false,
        'prerelease'   => false,
        'html_url'     => "$base/release/$version",
        'published_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        'body'         => 'The end-to-end test\'s release.',
        'assets'       => [ [
            'name'                 => "owa_{$version}_packaged.tar",
            'browser_download_url' => "$base/download/owa_{$version}_packaged.tar",
            'size'                 => $pkg['size'],
            'digest'               => 'sha256:' . ( $digest ?? $pkg['sha256'] ),
        ] ],
    ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}

// ---- the installation ------------------------------------------------------

/** 2.0.90 unpacked, configured and installed into a fresh database, with files of its own. */
/**
 * $as: the database account owa-config.php names, when not $db's (which
 * creates the database).
 */
function installFrom( array $pkg, $work, array $db, $listing, $extra = '', ?array $as = null ) {

    $site = "$work/site";
    must( 'rm -rf ' . escapeshellarg( $site ) );
    mkdir( $site, 0755, true );
    must( sprintf( 'tar -xf %s -C %s', escapeshellarg( $pkg['file'] ), escapeshellarg( $site ) ) );

    $m = sql( $db );
    $m->query( "DROP DATABASE IF EXISTS `{$db['name']}`" );
    $m->query( "CREATE DATABASE `{$db['name']}`" );
    $m->close();

    $as     = ( $as ?? [] ) + $db;
    $rand   = static fn () => bin2hex( random_bytes( 24 ) );
    $config = strtr( (string) file_get_contents( "$site/owa-config-dist.php" ), [
        'yourdbtypegoeshere'         => $db['type'],
        'yourdbnamegoeshere'         => $db['name'],
        'yourdbhostgoeshere'         => $db['host'],
        'yourdbusergoeshere'         => $as['user'],
        'yourdbpasswordgoeshere'     => addcslashes( $as['password'], "\\'" ),
        'yournoncekeygoeshere'       => $rand(),
        'yournoncesaltgoeshere'      => $rand(),
        'yourauthkeygoeshere'        => $rand(),
        'yourauthsaltgoeshere'       => $rand(),
        'http://domain/path/to/owa/' => 'http://127.0.0.1/owa-self-update-test/',
    ] );
    $config = preg_replace( "/(OWA_DB_PORT'\s*,\s*')3306/", '${1}' . $db['port'], $config );

    $smtp = freePort();
    $own  = "\n// tests/tools/self_update_e2e.php: the listing it serves, and no e-mail leaves the machine.\n"
        . "define('OWA_AUTO_UPDATE_RELEASES_URL', " . var_export( $listing, true ) . ");\n"
        . "define('OWA_ADMIN_NOTICE_EMAIL', 'admin@example.test');\n"
        . "define('OWA_MAILER_USE_SMTP', true);\n"
        . "define('OWA_MAILER_HOST', '127.0.0.1');\n"
        . "define('OWA_MAILER_PORT', '$smtp');\n"
        . $extra;
    $close  = strrpos( $config, '?>' );
    $config = $close === false ? $config . $own : substr( $config, 0, $close ) . $own . substr( $config, $close );
    file_put_contents( "$site/owa-config.php", $config );

    $defined = must( sprintf( "php -r %s", escapeshellarg( 'require "owa-config.php"; foreach (["OWA_AUTO_UPDATE_RELEASES_URL","OWA_ADMIN_NOTICE_EMAIL","OWA_MAILER_USE_SMTP","OWA_MAILER_HOST","OWA_MAILER_PORT"] as $c) echo defined($c) ? "" : "$c "; ' ) ), $site );
    check( trim( $defined ) === '', 'owa-config.php does not define ' . $defined . '-- stopping before anything can send mail' );

    must( 'php cli.php cmd=install user_id=e2e-admin password=e2e-admin-password-1 email_address=admin@example.test domain=example.test', $site );

    // What belongs to the installation, and must survive an update: the
    // bundles cmd=install published, and a module of someone else's.
    check( (bool) glob( "$site/public/tracker/*.js" ), 'cmd=install published no tracking bundle' );
    @mkdir( "$site/modules/E2eThirdParty", 0755, true );
    file_put_contents( "$site/modules/E2eThirdParty/Module.php", '<?php // someone else\'s module' );

    return $site;
}

function cli( $site, $ca, $args ) {

    return run( sprintf( 'php -d curl.cainfo=%s cli.php %s', escapeshellarg( $ca ), $args ), $site );
}

/** Config lines that make a patch wait for a window twelve hours away: staged, not yet due. */
function later() {

    return "define('OWA_TIMEZONE', 'UTC');\ndefine('OWA_AUTO_UPDATE_PATCH_IMMEDIATELY', false);\n"
        . sprintf( "define('OWA_AUTO_UPDATE_WINDOW', '%s-%s');\n", gmdate( 'H:i', time() + 43200 ), gmdate( 'H:i', time() + 43260 ) );
}

/** Step 1, and what it left: [output, staged.json]. */
function stage( $site, $ca ) {

    [ , $out ] = cli( $site, $ca, 'cmd=release-check' );
    $dir = updatesDir( $site );

    return [ $out, $dir === null ? null : json( "$dir/staged.json" ) ];
}

function stagedToInstall( $site, $ca ) {

    [ $out, $staged ] = stage( $site, $ca );
    check( ( $staged['version'] ?? null ) === TO && ! empty( $staged['automatic'] ), 'release-check did not stage ' . TO . " to install itself:\n$out" );

    return $staged;
}

/** Installed and finished: the version, no schema update pending, nothing left of the install. */
function installed( $site, $ca, $version ) {

    check( envVersion( $site ) === $version, "Core/Env.php says " . envVersion( $site ) . ", not $version" );

    [ , $pending ] = cli( $site, $ca, 'cmd=update --listpending' );
    check( str_contains( $pending, 'No updates are pending' ), "schema updates are still pending:\n$pending" );

    $dir = updatesDir( $site );
    check( ! is_file( "$dir/install.json" ) && ! is_dir( "$dir/backup" ), 'install.json or backup/ was left behind' );
    check( ! file_exists( "$site/owa-data/updating" ), 'the lock file was left behind' );
}

/** Wait until install.json's retry time has passed, as the scheduler would. */
function waitForRetry( $site ) {

    $install = json( updatesDir( $site ) . '/install.json' );
    $wait    = (int) ( $install['retry_after'] ?? 0 ) - time() + 1;

    if ( $wait > 0 ) {

        sleep( $wait );
    }
}

/** An HTTP GET of the installation through php -S: [status, body]. */
function web( $port, $path ) {

    $body   = @file_get_contents( "http://127.0.0.1:$port/$path", false, stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 20 ] ] ) );
    $status = 0;

    foreach ( $http_response_header ?? [] as $h ) {

        if ( preg_match( '#^HTTP/\S+ (\d+)#', $h, $m ) ) {

            $status = (int) $m[1];
        }
    }

    return [ $status, (string) $body ];
}

// ---- the scenarios ---------------------------------------------------------

$build = buildTree( $repo, $work );
say( "Built HEAD in $build" );

$dropped = static function ( $tree ) {

    file_put_contents( "$tree/modules/Base/e2e-only-in-" . FROM . '.txt', 'a file the next release does not ship' );
};

$from = package( $build, $work, 'from', FROM, $dropped );
$tls  = tls( $work );
$port = freePort();
$base = "https://127.0.0.1:$port";
$www  = "$work/www";
@mkdir( $www, 0755, true );
$server->proc = serve( $tls, $www, $port );
$ca     = "$tls/ca.pem";

$scenarios = [

    'installs' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'installs', TO, withSchemaUpdate( true ) );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );
        $hash    = hash_file( 'sha256', "$site/owa-config.php" );
        $bundles = glob( "$site/public/tracker/*.js" );

        [ , $out ] = cli( $site, $ca, 'cmd=release-check' );
        $staged = json( updatesDir( $site ) . '/staged.json' );
        check( ( $staged['version'] ?? null ) === TO && ! empty( $staged['automatic'] ) && array_key_exists( 'window', (array) $staged ) && $staged['window'] === null,
            "release-check did not stage " . TO . " to install at once:\n$out" );
        check( hasNotice( notices( $db ), 'staged:' . TO ), 'no "will be installed" notice' );

        [ $code, $out ] = cli( $site, $ca, 'cmd=schedule-run' );
        check( $code === 0, "schedule-run exited $code:\n$out" );
        check( envVersion( $site ) === TO, 'Core/Env.php says ' . envVersion( $site ) . ":\n$out" );

        [ , $pending ] = cli( $site, $ca, 'cmd=update --listpending' );
        check( str_contains( $pending, 'No updates are pending' ), "the schema update did not run:\n$pending" );

        $n = notices( $db );
        check( hasNotice( $n, 'installed:' . TO ), 'no "is installed" notice' );
        check( ( $n[ 'installed:' . TO ]['email_status'] ?? '' ) !== '', 'the "is installed" notice records no e-mail outcome' );

        $dir = updatesDir( $site );
        check( ! is_file( "$dir/install.json" ) && ! is_dir( "$dir/backup" ) && ! is_dir( "$dir/staged" ) && ! is_file( "$dir/staged.json" ),
            'the install left install.json, backup/ or the staged release behind' );
        check( ! file_exists( "$site/owa-data/updating" ), 'the lock file was left behind' );
        check( hash_file( 'sha256', "$site/owa-config.php" ) === $hash, 'owa-config.php changed' );
        check( glob( "$site/public/tracker/*.js" ) == $bundles, 'the tracking bundles changed' );
        check( is_file( "$site/modules/E2eThirdParty/Module.php" ), 'a third-party module was deleted' );
        check( ! file_exists( "$site/modules/Base/e2e-only-in-" . FROM . '.txt' ), 'a file ' . TO . ' does not ship is still there' );
    },

    'wrong-digest' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'wrong-digest', TO );
        publish( $www, $base, $to, TO, str_repeat( '0', 64 ) );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ , $out ] = cli( $site, $ca, 'cmd=release-check' );
        $dir = updatesDir( $site );
        check( $dir === null || ( ! is_file( "$dir/staged.json" ) && ! is_dir( "$dir/staged" ) ), "a release that fails its SHA-256 was staged:\n$out" );
        check( hasNotice( notices( $db ), 'staging_failing:' . TO ), 'no "could not be downloaded" notice' );

        cli( $site, $ca, 'cmd=schedule-run' );
        check( envVersion( $site ) === FROM, 'it installed anyway' );
    },

    'unmet' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to = package( $build, $work, 'unmet', TO, null, static function ( $tree ) {

            $r = json_decode( (string) file_get_contents( "$tree/release.json" ), true );
            $r['requirements']['php']['min'] = '99.0';
            file_put_contents( "$tree/release.json", json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        } );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ , $out ] = cli( $site, $ca, 'cmd=release-check' );
        $staged = json( updatesDir( $site ) . '/staged.json' );
        check( ( $staged['version'] ?? null ) === TO && empty( $staged['automatic'] ), "it was not staged as not installing itself:\n$out" );
        check( hasNotice( notices( $db ), 'unmet:' . TO ), 'no "cannot be installed automatically yet" notice' );

        cli( $site, $ca, 'cmd=schedule-run' );
        check( envVersion( $site ) === FROM, 'it installed anyway' );
    },

    'does-not-boot' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        // After release.json, which cmd=version-info writes by running OWA.
        $to = package( $build, $work, 'does-not-boot', TO, null, static function ( $tree ) {

            file_put_contents( "$tree/owa.php", "\nthis is not PHP(\n", FILE_APPEND );
        } );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        cli( $site, $ca, 'cmd=release-check' );
        [ $code, $out ] = cli( $site, $ca, 'cmd=schedule-run' );
        check( $code !== 0, "a release that does not parse reported success:\n$out" );
        check( envVersion( $site ) === FROM, 'Core/Env.php says ' . envVersion( $site ) . ', not the restored ' . FROM );

        $install = json( updatesDir( $site ) . '/install.json' );
        check( str_contains( (string) ( $install['error'] ?? '' ), 'stopped before it booted' ), 'install.json does not say why: ' . json_encode( $install ) );

        // The restored release runs, and raises the notice.
        [ $code, $out ] = cli( $site, $ca, 'cmd=self-update' );
        check( $code === 0, "the restored release does not run:\n$out" );
        $n = notices( $db );
        check( hasNotice( $n, 'install_failed:' . TO ), 'no "failed" notice' );
        check( str_contains( (string) $n[ 'install_failed:' . TO ]['body'], 'runs as before' ), 'the notice does not say the installed release runs' );
    },

    'schema-fails' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'schema-fails', TO, withSchemaUpdate( false ) );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        cli( $site, $ca, 'cmd=release-check' );
        [ $code, $out ] = cli( $site, $ca, 'cmd=schedule-run' );
        check( $code !== 0, "a failed schema update reported success:\n$out" );
        check( envVersion( $site ) === TO, 'the files were undone after the new release booted' );

        $install = json( updatesDir( $site ) . '/install.json' );
        check( ! empty( $install['booted'] ) && str_contains( (string) ( $install['error'] ?? '' ), 'cmd=update did not complete' ),
            'install.json does not record the failure: ' . json_encode( $install ) );

        $n = notices( $db );
        check( hasNotice( $n, 'install_failed:' . TO ), 'no "failed" notice' );
        check( str_contains( (string) $n[ 'install_failed:' . TO ]['body'], 'tracking is queued' ), 'the notice does not say tracking is queued' );
    },
    // ---- before anything is installed --------------------------------------

    'unreachable' => function () use ( $build, $work, $db, $from, $www, $base, $ca, $tls, $port, $server ) {

        $to   = package( $build, $work, 'unreachable', TO );
        publish( $www, $base, $to, TO );
        // Staged for later: every cli.php run would otherwise install it first.
        $site   = installFrom( $from, $work, $db, "$base/releases.json", later() );
        $staged = stagedToInstall( $site, $ca );

        proc_terminate( $server->proc );
        proc_close( $server->proc );

        try {

            [ $out, $again ] = stage( $site, $ca );
            check( $again == $staged, "GitHub unreachable changed staged.json:\n$out" );
            check( is_dir( updatesDir( $site ) . '/staged' ), 'GitHub unreachable deleted the staged release' );

            // Installing needs no network.
            [ $code, $out ] = cli( $site, $ca, 'cmd=self-update-now' );
            check( $code === 0, "self-update-now exited $code:\n$out" );
            cli( $site, $ca, 'cmd=schedule-run' );
            installed( $site, $ca, TO );

        } finally {

            $server->proc = serve( $tls, $www, $port );
        }
    },

    'asset-replaced' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $first  = package( $build, $work, 'replaced-1', TO );
        $second = package( $build, $work, 'replaced-2', TO, static function ( $tree ) {

            file_put_contents( "$tree/modules/Base/e2e-replaced.txt", 'only in the replacement' );
        } );
        publish( $www, $base, $first, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json", later() );
        stagedToInstall( $site, $ca );

        publish( $www, $base, $second, TO );
        [ $out, $staged ] = stage( $site, $ca );
        check( ( $staged['sha256'] ?? null ) === $second['sha256'], "the replaced asset was not downloaded again:\n$out" );

        cli( $site, $ca, 'cmd=self-update-now' );
        cli( $site, $ca, 'cmd=schedule-run' );
        installed( $site, $ca, TO );
        check( is_file( "$site/modules/Base/e2e-replaced.txt" ), 'what was installed is not the replacement' );
    },

    'unwritable-directory' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'unwritable', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        chmod( "$site/Core", 0555 );

        try {

            [ $out, $staged ] = stage( $site, $ca );
            check( ( $staged['version'] ?? null ) === TO && empty( $staged['automatic'] ), "a release that cannot be written was staged to install:\n$out" );
            check( str_contains( $out, 'such as Core/' ), "the check does not name the directory:\n$out" );

            cli( $site, $ca, 'cmd=schedule-run' );
            check( envVersion( $site ) === FROM, 'it installed anyway' );

        } finally {

            chmod( "$site/Core", 0755 );
        }

        // Fixed: the next check stages it to install, and it installs.
        stagedToInstall( $site, $ca );
        cli( $site, $ca, 'cmd=schedule-run' );
        installed( $site, $ca, TO );
    },

    'config-refused' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'config-refused', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json", "define('OWA_E2E_NAMES_A_CLASS', \\OWA\\Core\\Env::class);\n" );

        [ $out, $staged ] = stage( $site, $ca );
        check( ( $staged['version'] ?? null ) === TO && empty( $staged['automatic'] ), "it was staged to install:\n$out" );
        check( str_contains( $out, 'owa-config.php uses' ), "the check does not say why:\n$out" );

        cli( $site, $ca, 'cmd=schedule-run' );
        check( envVersion( $site ) === FROM, 'it installed anyway' );
    },

    'manual-install' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'manual-install', TO, withSchemaUpdate( true, 'It rewrites nothing; the test says it must be installed by hand.' ) );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ $out, $staged ] = stage( $site, $ca );
        check( ( $staged['version'] ?? null ) === TO && empty( $staged['automatic'] ), "an update marked for a manual install was staged to install itself:\n$out" );
        check( hasNotice( notices( $db ), 'manual:' . TO ), 'no "needs to be installed by hand" notice' );

        cli( $site, $ca, 'cmd=schedule-run' );
        check( envVersion( $site ) === FROM, 'it installed itself' );

        // By hand, as the notice says.
        [ $code, $out ] = cli( $site, $ca, 'cmd=self-update-install version=' . TO );
        check( $code === 0, "self-update-install exited $code:\n$out" );
        installed( $site, $ca, TO );
    },

    'two-updates-directories' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'two-dirs', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        mkdir( "$site/owa-data/updates-" . str_repeat( 'a', 32 ), 0711 );
        mkdir( "$site/owa-data/updates-" . str_repeat( 'b', 32 ), 0711 );

        [ $out ] = stage( $site, $ca );
        check( ! glob( "$site/owa-data/updates-*/staged.json" ), "something was staged beside two updates directories:\n$out" );

        rmdir( "$site/owa-data/updates-" . str_repeat( 'b', 32 ) );
        stagedToInstall( $site, $ca );
        cli( $site, $ca, 'cmd=schedule-run' );
        installed( $site, $ca, TO );
    },

    'major-release' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'major', '3.0.0' );
        publish( $www, $base, $to, '3.0.0' );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ $out, $staged ] = stage( $site, $ca );
        check( $staged === null, "a major release was staged:\n$out" );

        $m   = sql( $db );
        $row = $m->query( sprintf( "SELECT COUNT(*) AS n FROM `%s`.owa_notification WHERE source <> 'auto_update'", $db['name'] ) )->fetch_assoc();
        $m->close();
        check( (int) $row['n'] === 1, 'the major release is not announced in the bell' );
    },

    'passwordless-database' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        // A database account with no password, which boot accepts.
        $user = 'owa_e2e_nopass';
        $m    = sql( $db );
        $m->query( "DROP USER IF EXISTS '$user'@'%'" );
        $m->query( "CREATE USER '$user'@'%' IDENTIFIED BY ''" );
        $m->query( "GRANT ALL ON `{$db['name']}`.* TO '$user'@'%'" );
        $m->close();

        try {

            $to   = package( $build, $work, 'passwordless', TO );
            publish( $www, $base, $to, TO );
            $site = installFrom( $from, $work, $db, "$base/releases.json", '', [ 'user' => $user, 'password' => '' ] );

            stagedToInstall( $site, $ca );
            cli( $site, $ca, 'cmd=schedule-run' );
            installed( $site, $ca, TO );

        } finally {

            $m = sql( $db );
            $m->query( "DROP USER IF EXISTS '$user'@'%'" );
            $m->close();
        }
    },

    // ---- during the copy -----------------------------------------------------

    'copy-fails' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'copy-fails', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );
        stagedToInstall( $site, $ca );

        // Writable when the release was checked, not when it is copied.
        chmod( "$site/Core", 0555 );

        try {

            cli( $site, $ca, 'cmd=schedule-run' );
            check( envVersion( $site ) === FROM, 'the failed copy was not undone' );

            $install = json( updatesDir( $site ) . '/install.json' );
            check( str_contains( (string) ( $install['error'] ?? '' ), 'Could not write' ) && str_contains( (string) $install['error'], 'previous release was put back' ),
                'install.json does not record the undone copy: ' . json_encode( $install ) );

            [ $code, $out ] = cli( $site, $ca, 'cmd=self-update' );
            check( $code === 0, "the restored release does not run:\n$out" );
            check( hasNotice( notices( $db ), 'install_failed:' . TO ), 'no "failed" notice' );

        } finally {

            chmod( "$site/Core", 0755 );
        }

        // Fixed: install it now, as the notice says.
        [ $code, $out ] = cli( $site, $ca, 'cmd=self-update-install version=' . TO );
        check( $code === 0, "self-update-install exited $code:\n$out" );
        installed( $site, $ca, TO );
    },

    'killed-mid-copy' => function () use ( $build, $work, $db, $from, $www, $base, $ca, $server ) {

        $to   = package( $build, $work, 'killed', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );
        stagedToInstall( $site, $ca );

        // The copy writes Core/Env.php.owa-update before renaming it into
        // place. A FIFO there blocks that write until it is read: the
        // install stops part-way, holding the lock.
        $fifo = "$site/Core/Env.php.owa-update";
        check( posix_mkfifo( $fifo, 0644 ), 'could not make the FIFO' );

        $proc = proc_open( [ PHP_BINARY, '-d', "curl.cainfo=$ca", 'cli.php', 'cmd=schedule-run' ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes, $site );

        for ( $i = 0; $i < 100 && ! str_contains( (string) @file_get_contents( "$site/owa-data/updating" ), 'copying' ); $i++ ) {

            usleep( 100000 );
        }

        check( str_contains( (string) @file_get_contents( "$site/owa-data/updating" ), 'copying' ), 'the install did not start copying' );

        // While it runs: the web answers 503, the REST API says busy, the CLI waits.
        $webPort     = freePort();
        $server->web = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$webPort", '-t', $site ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes2 );
        usleep( 500000 );

        [ $status, $body ] = web( $webPort, 'index.php' );
        check( $status === 503 && str_contains( $body, 'OWA is updating' ), "index.php answered $status during the install" );
        [ $status, $body ] = web( $webPort, 'api/index.php' );
        check( $status === 503 && str_contains( $body, '"busy"' ), "the REST API answered $status during the install" );
        proc_terminate( $server->web );

        [ $code, $out ] = cli( $site, $ca, 'cmd=self-update' );
        check( $code === 75 && str_contains( $out, 'OWA is installing an update' ), "a CLI command during the install exited $code:\n$out" );

        // Killed: the lock file stays, its flock goes with the process.
        $pid = (int) proc_get_status( $proc )['pid'];
        posix_kill( $pid, 9 );
        proc_close( $proc );
        unlink( $fifo );
        check( file_exists( "$site/owa-data/updating" ), 'the killed install\'s lock file is gone' );

        // The next run after the retry time takes the lock over and carries the install on.
        waitForRetry( $site );
        [ $code, $out ] = cli( $site, $ca, 'cmd=schedule-run' );
        check( $code === 0, "the run after the kill exited $code:\n$out" );
        installed( $site, $ca, TO );
    },

    // ---- after the new release has started -----------------------------------

    'newer-release-after-a-failure' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $bad  = package( $build, $work, 'newer-bad', TO, withSchemaUpdate( false ) );
        $good = package( $build, $work, 'newer-good', '2.0.92' );
        publish( $www, $base, $bad, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );
        stagedToInstall( $site, $ca );
        cli( $site, $ca, 'cmd=schedule-run' );
        check( envVersion( $site ) === TO && json( updatesDir( $site ) . '/install.json' ) !== null, 'the failing install did not leave ' . TO . ' unfinished' );

        // The release that fixes it replaces the unfinished install.
        publish( $www, $base, $good, '2.0.92' );
        [ $out, $staged ] = stage( $site, $ca );
        check( ( $staged['version'] ?? null ) === '2.0.92', "2.0.92 was not staged over the unfinished install:\n$out" );
        cli( $site, $ca, 'cmd=schedule-run' );
        installed( $site, $ca, '2.0.92' );
    },

    // ---- a newer release installed meanwhile ---------------------------------

    'installed-past' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'past', TO );
        publish( $www, $base, $to, TO );

        $site = installFrom( $from, $work, $db, "$base/releases.json", later() );

        [ $out, $staged ] = stage( $site, $ca );
        check( ( $staged['version'] ?? null ) === TO && is_array( $staged['window'] ?? null ), "it was not staged to wait for its window:\n$out" );
        cli( $site, $ca, 'cmd=schedule-run' );
        check( envVersion( $site ) === FROM, 'it installed outside its window' );

        // 2.0.92 put in place by hand, as an operator unpacking it would leave Core/Env.php.
        $env = (string) file_get_contents( "$site/Core/Env.php" );
        file_put_contents( "$site/Core/Env.php", str_replace( "const VERSION = '" . FROM . "';", "const VERSION = '2.0.92';", $env ) );

        // Not due before its window, so the hand-off leaves it; the next release check finds nothing newer and deletes it.
        cli( $site, $ca, 'cmd=schedule-run' );
        check( envVersion( $site ) === '2.0.92', 'the hand-off installed the older ' . TO . ' over 2.0.92' );
        [ $out ] = stage( $site, $ca );
        $dir = updatesDir( $site );
        check( ! is_file( "$dir/staged.json" ) && ! is_dir( "$dir/staged" ), 'the staged ' . TO . ", older than what is installed, was kept:\n$out" );
    },

];

$only = array_slice( $argv, 1 );

foreach ( $scenarios as $name => $scenario ) {

    if ( $only && ! in_array( $name, $only, true ) ) {

        continue;
    }

    $t = microtime( true );

    try {

        $scenario();
        say( sprintf( 'PASS %s (%.0fs)', $name, microtime( true ) - $t ) );

    } catch ( Throwable $e ) {

        $failures++;
        say( sprintf( "FAIL %s: %s", $name, $e->getMessage() ) );
    }
}

proc_terminate( $server->proc );

try {

    $m = sql( $db );
    $m->query( "DROP DATABASE IF EXISTS `{$db['name']}`" );
    $m->close();

} catch ( Throwable $e ) {

    say( 'Could not drop the scratch database: ' . $e->getMessage() );
}

if ( $failures === 0 && ! getenv( 'OWA_SELF_UPDATE_KEEP' ) ) {

    run( 'rm -rf ' . escapeshellarg( $work ) );

} else {

    say( "The packages and the last installation are in $work" );
}

exit( $failures === 0 ? 0 : 1 );
