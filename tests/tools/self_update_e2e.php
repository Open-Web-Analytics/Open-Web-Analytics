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
 *   php cli.php cmd=core-update    the listing, the download, its SHA-256 and signature, the
 *                                        checks, and the install: both tarballs proved, the files
 *                                        copied when the command has finished
 *   php cli.php cmd=core-update-status   the next run of cli.php: it boots the new release and finishes
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
 *   not-signed       no signature, or one by a key not trusted: nothing staged; signed, it installs
 *   unmet            2.0.91 needs PHP 99: downloaded, never installed, not even by hand
 *   does-not-boot    2.0.91's owa.php does not parse: two boots fail, the third run puts 2.0.90 back,
 *                    and 2.0.91 is skipped after
 *   schema-fails     2.0.91's schema update fails: its files stay, the error is recorded
 *   tampered         the downloaded tarball is replaced after it was checked: it never installs
 *
 * and the failure cases of the wiki's Automatic Updates page, each with the
 * recovery it promises where there is one: window, by-version, unreachable,
 * asset-replaced, unwritable-directory, config-names-a-class, manual-install,
 * two-updates-directories, major-release, passwordless-database, copy-fails,
 * killed-mid-copy (the web, the REST API and the CLI while it runs, then
 * 2.0.90 put back and the retry), newer-release-after-a-failure and
 * installed-past.
 *
 * Every listing it serves has 2.0.90 too: a failed install puts the
 * installed release back from its tarball, which the release check downloads.
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
require dirname( __DIR__, 2 ) . '/modules/Base/Classes/AutoUpdate/Signature.php';

// The run's signing key: every package trusts its public half, as a release
// trusts the maintainers' keys, and is signed with it by the packager's script.
$pair = sodium_crypto_sign_keypair();
define( 'E2E_SIGNING_SECRET', base64_encode( sodium_crypto_sign_secretkey( $pair ) ) );
define( 'E2E_SIGNING_PUBLIC', base64_encode( sodium_crypto_sign_publickey( $pair ) ) );

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

    // The release trusts the run's key, in place of the maintainers'.
    $sig = "$tree/modules/Base/Classes/AutoUpdate/Signature.php";
    check( preg_match( '/const TRUSTED = \[[^\]]*\];/', (string) file_get_contents( $sig ) ) === 1, 'Signature declares no TRUSTED keys to replace' );
    file_put_contents( $sig, preg_replace( '/const TRUSTED = \[[^\]]*\];/', "const TRUSTED = [ '" . E2E_SIGNING_PUBLIC . "' ];", (string) file_get_contents( $sig ) ) );

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

    // Signed as the packager signs it: its script, checked against the keys this tree trusts.
    must( sprintf( 'OWA_RELEASE_SIGNING_KEY=%s php .github/scripts/release-sign.php %s', escapeshellarg( E2E_SIGNING_SECRET ), escapeshellarg( $version ) ), $tree );

    $file = "$work/tars/$name/owa_{$version}_packaged.tar";
    @mkdir( dirname( $file ), 0755, true );
    rename( "$tree/owa_{$version}_packaged.tar", $file );
    rename( "$tree/owa_{$version}_packaged.tar.sig", "$file.sig" );
    must( 'rm -rf ' . escapeshellarg( $tree ) );

    return [ 'file' => $file, 'sig' => (string) file_get_contents( "$file.sig" ), 'sha256' => hash_file( 'sha256', $file ), 'size' => filesize( $file ) ];
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

/**
 * The listing GitHub would give for one release, its tarball and its
 * signature, in $www, and for the installed release, FROM, whose tarball a
 * failed install puts back. $signature: the signature file in place of the
 * package's; false lists none.
 */
function publish( $www, $base, array $pkg, $version, $digest = null, $signature = null ) {

    must( 'rm -rf ' . escapeshellarg( $www ) );
    @mkdir( "$www/download/$version", 0755, true );
    copy( $pkg['file'], "$www/download/$version/owa_{$version}_packaged.tar" );

    $releases = [];
    $from     = $GLOBALS['e2e_from'] ?? null;

    if ( $from !== null && $version !== FROM ) {

        @mkdir( "$www/download/" . FROM, 0755, true );
        copy( $from['file'], "$www/download/" . FROM . '/owa_' . FROM . '_packaged.tar' );
        file_put_contents( "$www/download/" . FROM . '/owa_' . FROM . '_packaged.tar.sig', $from['sig'] );
        $releases[] = listed( $base, $from, FROM, $from['sha256'], $from['sig'], '2026-01-01T00:00:00Z' );
    }

    if ( $signature !== false ) {

        file_put_contents( "$www/download/$version/owa_{$version}_packaged.tar.sig", $signature ?? $pkg['sig'] );
    }

    array_unshift( $releases, listed( $base, $pkg, $version, $digest ?? $pkg['sha256'], $signature === false ? false : ( $signature ?? $pkg['sig'] ), gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
    file_put_contents( "$www/releases.json", json_encode( $releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}

/** One release as GitHub's listing gives it. $sig false: no signature asset. */
function listed( $base, array $pkg, $version, $digest, $sig, $published ) {

    $assets = [ [
        'name'                 => "owa_{$version}_packaged.tar",
        'browser_download_url' => "$base/download/$version/owa_{$version}_packaged.tar",
        'size'                 => $pkg['size'],
        'digest'               => 'sha256:' . $digest,
    ] ];

    if ( $sig !== false ) {

        $assets[] = [
            'name'                 => "owa_{$version}_packaged.tar.sig",
            'browser_download_url' => "$base/download/$version/owa_{$version}_packaged.tar.sig",
            'size'                 => strlen( (string) $sig ),
        ];
    }

    return [
        'id'           => 9000000 + (int) str_replace( '.', '', $version ),
        'tag_name'     => $version,
        'name'         => "OWA $version",
        'draft'        => false,
        'prerelease'   => false,
        'html_url'     => "$base/release/$version",
        'published_at' => $published,
        'body'         => 'The end-to-end test\'s release.',
        'assets'       => $assets,
    ];
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
        . "define('OWA_AUTO_UPDATE_DOWNLOAD_URL', " . var_export( dirname( $listing ) . '/download/', true ) . ");\n"
        . "define('OWA_ADMIN_NOTICE_EMAIL', 'admin@example.test');\n"
        . "define('OWA_MAILER_USE_SMTP', true);\n"
        . "define('OWA_MAILER_HOST', '127.0.0.1');\n"
        . "define('OWA_MAILER_PORT', '$smtp');\n"
        // No update window, so a release installs at the check that downloads it, unless the scenario sets one (later()).
        . ( str_contains( (string) $extra, 'OWA_AUTO_UPDATE_WINDOW' ) ? '' : "define('OWA_AUTO_UPDATE_WINDOW', '');\n" )
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

/** Config lines that set the update window twelve hours away: a release is downloaded and waits. */
function later() {

    return "define('OWA_TIMEZONE', 'UTC');\n"
        . sprintf( "define('OWA_AUTO_UPDATE_WINDOW', '%s-%s');\n", gmdate( 'H:i', time() + 43200 ), gmdate( 'H:i', time() + 43260 ) );
}

/** The release check, by hand: it lists the releases, downloads, and installs what is due. [exit, output] */
function release_check( $site, $ca ) {

    return cli( $site, $ca, 'cmd=core-update' );
}

/** The next run of cli.php, which finishes an install whose files are copied. [exit, output] */
function next_run( $site, $ca ) {

    return cli( $site, $ca, 'cmd=core-update-status' );
}

/** Whether the release's tarball is downloaded. */
function downloaded( $site, $version ) {

    $dir = updatesDir( $site );

    return $dir !== null && is_file( "$dir/owa_{$version}_packaged.tar" ) && is_file( "$dir/owa_{$version}_packaged.tar.sig" );
}

function failure( $site ) {

    $dir = updatesDir( $site );

    return $dir === null ? null : json( "$dir/last-failure.json" );
}

/** Installed and finished: the version, no schema update pending, nothing left of the install but its tarball. */
function installed( $site, $ca, $version ) {

    check( envVersion( $site ) === $version, "Core/Env.php says " . envVersion( $site ) . ", not $version" );

    [ , $pending ] = cli( $site, $ca, 'cmd=core-update-schema --listpending' );
    check( str_contains( $pending, 'No updates are pending' ), "schema updates are still pending:\n$pending" );

    $dir = updatesDir( $site );
    check( failure( $site ) === null, 'last-failure.json was left behind: ' . json_encode( failure( $site ) ) );
    check( ! file_exists( "$site/owa-update.php" ), 'the install marker was left behind: ' . @file_get_contents( "$site/owa-update.php" ) );
    check( ! file_exists( "$site/owa-data/updating" ), 'the lock file was left behind' );
    check( array_map( 'basename', glob( "$dir/*" ) ?: [] ) === [ "owa_{$version}_packaged.tar", "owa_{$version}_packaged.tar.sig" ],
        'the updates directory does not hold just the installed release\'s tarball: ' . implode( ', ', array_map( 'basename', glob( "$dir/*" ) ?: [] ) ) );
}

/** An HTTP GET of the installation through php -S: [status, body]. */
function web( $port, $path ) {

    $h = @fopen( "http://127.0.0.1:$port/$path", 'r', false, stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 20 ] ] ) );

    if ( $h === false ) {

        return [ 0, '' ];
    }

    $body   = (string) stream_get_contents( $h );
    $status = 0;

    foreach ( (array) ( stream_get_meta_data( $h )['wrapper_data'] ?? [] ) as $line ) {

        if ( preg_match( '#^HTTP/\S+ (\d+)#', (string) $line, $m ) ) {

            $status = (int) $m[1];
        }
    }

    fclose( $h );

    return [ $status, $body ];
}

// ---- the scenarios ---------------------------------------------------------

$build = buildTree( $repo, $work );
say( "Built HEAD in $build" );

$dropped = static function ( $tree ) {

    file_put_contents( "$tree/modules/Base/e2e-only-in-" . FROM . '.txt', 'a file the next release does not ship' );
};

$from = package( $build, $work, 'from', FROM, $dropped );
$GLOBALS['e2e_from'] = $from;
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
        $site    = installFrom( $from, $work, $db, "$base/releases.json" );
        $hash    = hash_file( 'sha256', "$site/owa-config.php" );
        $bundles = glob( "$site/public/tracker/*.js" );

        // An operator's own files in the OWA directory, which no release ships.
        @mkdir( "$site/.well-known", 0755 );
        file_put_contents( "$site/.well-known/e2e.txt", 'the operator\'s' );
        file_put_contents( "$site/.htpasswd", 'e2e:x' );
        file_put_contents( "$site/robots.txt", "User-agent: *\n" );

        // No window: the release check downloads it and installs it, in one run.
        [ $code, $out ] = release_check( $site, $ca );
        check( $code === 0, "core-update exited $code:\n$out" );
        check( envVersion( $site ) === TO, 'Core/Env.php says ' . envVersion( $site ) . ":\n$out" );
        check( str_contains( (string) @file_get_contents( "$site/owa-update.php" ), '"phase":"copied"' ), "the marker does not say copied after the copy:\n$out" );
        check( downloaded( $site, TO ) && downloaded( $site, FROM ), 'the release\'s tarball, or the installed release\'s, was not downloaded' );

        [ $code, $out ] = next_run( $site, $ca );
        check( $code === 0 && str_contains( $out, 'OWA ' . TO . ' is installed.' ), "the next run did not finish the install (exit $code):\n$out" );

        $n = notices( $db );
        check( hasNotice( $n, 'installed:' . TO ), 'no "is installed" notice' );
        check( ( $n[ 'installed:' . TO ]['email_status'] ?? '' ) !== '', 'the "is installed" notice records no e-mail outcome' );

        installed( $site, $ca, TO );
        check( hash_file( 'sha256', "$site/owa-config.php" ) === $hash, 'owa-config.php changed' );
        check( glob( "$site/public/tracker/*.js" ) == $bundles, 'the tracking bundles changed' );
        check( is_file( "$site/modules/E2eThirdParty/Module.php" ), 'a third-party module was deleted' );
        check( is_file( "$site/.well-known/e2e.txt" ) && is_file( "$site/.htpasswd" ) && is_file( "$site/robots.txt" ), 'an operator\'s own file was deleted' );
        check( ! file_exists( "$site/modules/Base/e2e-only-in-" . FROM . '.txt' ), 'a file ' . TO . ' does not ship is still there' );
        check( ! glob( "$site/{,*/,*/*/}*.owa-update", GLOB_BRACE ), 'the copy left temporary files' );
    },

    'wrong-digest' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'wrong-digest', TO );
        publish( $www, $base, $to, TO, str_repeat( '0', 64 ) );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ , $out ] = release_check( $site, $ca );
        check( ! downloaded( $site, TO ) && envVersion( $site ) === FROM, "a release that fails its SHA-256 was kept or installed:\n$out" );
        check( hasNotice( notices( $db ), 'download_failing:' . TO ), 'no "could not be downloaded" notice' );
    },

    'not-signed' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'not-signed', TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        // No signature published, then one by a key the installation does not trust.
        $other = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );

        foreach ( [ 'is not signed: the release has no' => false,
                'is not signed by a key this installation trusts' => \OWA\Module\Base\Classes\AutoUpdate\Signature::sign( TO, $to['sha256'], $other ) ] as $why => $signature ) {

            publish( $www, $base, $to, TO, null, $signature );
            [ , $out ] = release_check( $site, $ca );
            check( ! downloaded( $site, TO ) && envVersion( $site ) === FROM, "a release that $why was kept or installed:\n$out" );
            check( str_contains( $out, $why ), "the check does not say the release $why:\n$out" );
        }

        // Signed after all: installed.
        publish( $www, $base, $to, TO );
        release_check( $site, $ca );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    'unmet' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to = package( $build, $work, 'unmet', TO, null, static function ( $tree ) {

            $r = json_decode( (string) file_get_contents( "$tree/release.json" ), true );
            $r['requirements']['php']['min'] = '99.0';
            file_put_contents( "$tree/release.json", json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        } );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ , $out ] = release_check( $site, $ca );
        check( downloaded( $site, TO ) && envVersion( $site ) === FROM, "it was not downloaded, or it installed:\n$out" );
        check( str_contains( $out, 'cannot be installed here yet' ) && hasNotice( notices( $db ), 'unmet:' . TO ), "no \"cannot be installed automatically yet\" notice:\n$out" );

        // Nor by hand: what it needs is not met.
        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --force' );
        check( $code !== 0 && str_contains( $out, 'cannot be installed here' ), "core-update --force installed a release whose requirements are unmet (exit $code):\n$out" );
        next_run( $site, $ca );
        check( envVersion( $site ) === FROM, 'it installed anyway' );
    },

    'does-not-boot' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        // After release.json, which cmd=version-info writes by running OWA.
        $to = package( $build, $work, 'does-not-boot', TO, null, static function ( $tree ) {

            file_put_contents( "$tree/owa.php", "\nthis is not PHP(\n", FILE_APPEND );
        } );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ $code, $out ] = release_check( $site, $ca );
        check( $code === 0 && envVersion( $site ) === TO, "the copy did not complete (exit $code):\n$out" );

        // Two runs try to boot it; the third puts the installed release back.
        foreach ( [ 1, 2 ] as $boot ) {

            [ $code, $out ] = cli( $site, $ca, 'cmd=schedule-run' );
            check( $code !== 0, "boot $boot of a release that does not parse reported success:\n$out" );
        }

        [ $code, $out ] = cli( $site, $ca, 'cmd=schedule-run' );
        check( $code === 0 && envVersion( $site ) === FROM, 'Core/Env.php says ' . envVersion( $site ) . ', not the restored ' . FROM . " (exit $code):\n$out" );
        check( ! file_exists( "$site/owa-update.php" ), 'the marker stayed after the installed release was put back' );
        check( str_contains( (string) ( failure( $site )['error'] ?? '' ), 'did not boot' ) && str_contains( (string) failure( $site )['error'], FROM . ' was put back' ),
            'last-failure.json does not say why: ' . json_encode( failure( $site ) ) );

        // The restored release runs, raises the notice, and does not try it again.
        [ $code, $out ] = next_run( $site, $ca );
        check( $code === 0, "the restored release does not run:\n$out" );
        $n = notices( $db );
        check( hasNotice( $n, 'install_failed:' . TO ) && str_contains( (string) $n[ 'install_failed:' . TO ]['body'], 'runs as before' ), 'no "failed" notice saying the installed release runs' );

        [ , $out ] = release_check( $site, $ca );
        check( envVersion( $site ) === FROM && str_contains( $out, 'failed before, so it is skipped' ), "a release that failed was tried again:\n$out" );
    },

    'schema-fails' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'schema-fails', TO, withSchemaUpdate( false ) );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        release_check( $site, $ca );
        [ , $out ] = next_run( $site, $ca );
        check( envVersion( $site ) === TO, 'the files were undone after the new release booted' );
        check( str_contains( $out, 'cmd=core-update-schema did not complete' ), "the run that finishes does not say the schema update failed:\n$out" );
        check( ! empty( failure( $site )['booted'] ) && str_contains( (string) ( failure( $site )['error'] ?? '' ), 'cmd=core-update-schema did not complete' ),
            'last-failure.json does not record the failure: ' . json_encode( failure( $site ) ) );
        check( ! file_exists( "$site/owa-update.php" ), 'the marker stayed after the new release booted' );

        $n = notices( $db );
        check( hasNotice( $n, 'install_failed:' . TO ) && str_contains( (string) $n[ 'install_failed:' . TO ]['body'], 'tracking is queued' ), 'no "failed" notice saying tracking is queued' );
    },

    'tampered' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to    = package( $build, $work, 'tampered', TO );
        $other = package( $build, $work, 'tampered-other', TO, static function ( $tree ) {

            file_put_contents( "$tree/modules/Base/e2e-tampered.txt", 'not what was checked' );
        } );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json", later() );

        [ , $out ] = release_check( $site, $ca );
        check( downloaded( $site, TO ) && str_contains( $out, 'installs between' ), "it was not downloaded to wait for its window:\n$out" );

        // After the release check proved it, someone who can write the data
        // directory puts another tarball, a real release's, in its place.
        $tar = updatesDir( $site ) . '/owa_' . TO . '_packaged.tar';
        copy( $other['file'], $tar );

        // --force installs what is downloaded; --version would fetch it afresh.
        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --force' );
        check( $code !== 0 && str_contains( $out, 'not signed by a key this installation trusts' ), "core-update --force does not refuse the tampered tarball (exit $code):\n$out" );
        check( ! file_exists( $tar ), 'the tarball that does not check was kept' );
        next_run( $site, $ca );
        check( envVersion( $site ) === FROM && ! file_exists( "$site/modules/Base/e2e-tampered.txt" ), 'a tarball its signature does not match was installed' );

        // Downloaded again, and it installs.
        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --force' );
        check( $code === 0, "core-update --force exited $code:\n$out" );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    // ---- before anything is installed --------------------------------------

    'window' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'window', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json", later() );

        [ , $out ] = release_check( $site, $ca );
        check( downloaded( $site, TO ) && envVersion( $site ) === FROM && str_contains( $out, TO . ' installs between' ), "it did not wait for its window:\n$out" );

        // Installing by hand ignores the window.
        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --force' );
        check( $code === 0 && str_contains( $out, 'The next run of cli.php boots it' ), "core-update --force exited $code:\n$out" );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    'by-version' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        // 2.0.92 is not in the listing, as a release past its newest 100 would not be:
        // --version fetches it from the download URL by its version.
        $to  = package( $build, $work, 'by-version-listed', TO );
        $old = package( $build, $work, 'by-version', '2.0.92' );
        publish( $www, $base, $to, TO );
        @mkdir( "$www/download/2.0.92", 0755, true );
        copy( $old['file'], "$www/download/2.0.92/owa_2.0.92_packaged.tar" );
        file_put_contents( "$www/download/2.0.92/owa_2.0.92_packaged.tar.sig", $old['sig'] );
        $site = installFrom( $from, $work, $db, "$base/releases.json", later() );

        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --version=2.0.92' );
        check( $code === 0 && envVersion( $site ) === '2.0.92', "core-update --version did not install a release the listing does not have (exit $code):\n$out" );
        next_run( $site, $ca );
        installed( $site, $ca, '2.0.92' );
    },

    'unreachable' => function () use ( $build, $work, $db, $from, $www, $base, $ca, $tls, $port, $server ) {

        $to   = package( $build, $work, 'unreachable', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json", later() );
        release_check( $site, $ca );

        proc_terminate( $server->proc );
        proc_close( $server->proc );

        try {

            [ , $out ] = release_check( $site, $ca );
            check( downloaded( $site, TO ) && str_contains( $out, 'could not be listed' ), "GitHub unreachable deleted the downloaded release, or was not said:\n$out" );

            // Installing what was downloaded needs no network.
            [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --force' );
            check( $code === 0, "core-update --force exited $code:\n$out" );
            next_run( $site, $ca );
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
        release_check( $site, $ca );

        publish( $www, $base, $second, TO );
        [ , $out ] = release_check( $site, $ca );
        check( hash_file( 'sha256', updatesDir( $site ) . '/owa_' . TO . '_packaged.tar' ) === $second['sha256'], "the replaced asset was not downloaded again:\n$out" );

        cli( $site, $ca, 'cmd=core-update --force' );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
        check( is_file( "$site/modules/Base/e2e-replaced.txt" ), 'what was installed is not the replacement' );
    },

    'unwritable-directory' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'unwritable', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        chmod( "$site/Core", 0555 );

        try {

            [ , $out ] = release_check( $site, $ca );
            check( envVersion( $site ) === FROM && str_contains( $out, 'such as Core/' ), "a release that cannot be written installed, or the check does not name the directory:\n$out" );

        } finally {

            chmod( "$site/Core", 0755 );
        }

        // Fixed: the next check installs it.
        release_check( $site, $ca );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    'config-names-a-class' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        // Nothing reads owa-config.php before OWA loads, so a config that
        // names an OWA class installs as any other.
        $to   = package( $build, $work, 'config-names-a-class', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json", "define('OWA_E2E_NAMES_A_CLASS', \\OWA\\Core\\Env::class);\n" );

        release_check( $site, $ca );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    'manual-install' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'manual-install', TO, withSchemaUpdate( true, 'It rewrites nothing; the test says it must be installed by hand.' ) );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ , $out ] = release_check( $site, $ca );
        check( envVersion( $site ) === FROM && str_contains( $out, 'must be installed by hand' ), "an update marked for a manual install installed itself:\n$out" );
        check( hasNotice( notices( $db ), 'manual:' . TO ), 'no "needs to be installed by hand" notice' );

        // By hand, as the notice says.
        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --force' );
        check( $code === 0 && envVersion( $site ) === TO, "core-update --force exited $code:\n$out" );
        [ $code, $out ] = next_run( $site, $ca );
        check( $code === 0 && str_contains( $out, 'OWA ' . TO . ' is installed.' ), "the next command did not finish the install (exit $code):\n$out" );
        installed( $site, $ca, TO );
    },

    'two-updates-directories' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'two-dirs', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        mkdir( "$site/owa-data/updates-" . str_repeat( 'a', 32 ), 0711 );
        mkdir( "$site/owa-data/updates-" . str_repeat( 'b', 32 ), 0711 );

        [ , $out ] = release_check( $site, $ca );
        check( ! glob( "$site/owa-data/updates-*/owa_*" ) && envVersion( $site ) === FROM, "something was downloaded beside two updates directories:\n$out" );

        rmdir( "$site/owa-data/updates-" . str_repeat( 'b', 32 ) );
        release_check( $site, $ca );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    'major-release' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'major', '3.0.0' );
        publish( $www, $base, $to, '3.0.0' );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        [ , $out ] = release_check( $site, $ca );
        check( ! downloaded( $site, '3.0.0' ) && envVersion( $site ) === FROM, "a major release was downloaded or installed:\n$out" );

        $m   = sql( $db );
        $row = $m->query( sprintf( "SELECT COUNT(*) AS n FROM `%s`.owa_notification WHERE source <> 'auto_update' AND title LIKE '%%3.0.0%%'", $db['name'] ) )->fetch_assoc();
        $m->close();
        check( (int) $row['n'] === 1, 'the major release is not announced in the bell' );
    },

    'passwordless-database' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        // A database account with no password, which boot accepts. Only on
        // CI's own database server: nowhere else is a passwordless account made.
        if ( getenv( 'GITHUB_ACTIONS' ) !== 'true' ) {

            return 'skipped: it creates a database account without a password, so it runs only in CI';
        }

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

            release_check( $site, $ca );
            next_run( $site, $ca );
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

        // Every directory is writable, as the checks find it, and the copy
        // still cannot write Core/Env.php beside itself.
        $blocker = "$site/Core/Env.php.owa-update";
        mkdir( $blocker );

        [ $code, $out ] = release_check( $site, $ca );
        check( $code !== 0 && envVersion( $site ) === FROM, "the failed copy was not put back (exit $code):\n$out" );
        check( ! file_exists( "$site/owa-update.php" ), 'the marker stayed after the installed release was put back' );
        check( str_contains( (string) ( failure( $site )['error'] ?? '' ), 'Could not write Core/Env.php' ) && str_contains( (string) failure( $site )['error'], FROM . ' was put back' ),
            'last-failure.json does not record the copy put back: ' . json_encode( failure( $site ) ) );

        [ $code, $out ] = next_run( $site, $ca );
        check( $code === 0, "the restored release does not run:\n$out" );
        check( hasNotice( notices( $db ), 'install_failed:' . TO ), 'no "failed" notice' );

        [ , $out ] = release_check( $site, $ca );
        check( envVersion( $site ) === FROM && str_contains( $out, 'skipped' ), "the failed release was tried again:\n$out" );

        // Fixed: install it by hand, as the notice says.
        rmdir( $blocker );
        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --version=' . TO );
        check( $code === 0, "core-update --force exited $code:\n$out" );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    'killed-mid-copy' => function () use ( $build, $work, $db, $from, $www, $base, $ca, $server ) {

        $to   = package( $build, $work, 'killed', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );

        // The copy writes Core/Env.php.owa-update before renaming it into
        // place. A FIFO there blocks that write until it is read: the
        // install stops part-way, holding the lock.
        $fifo = "$site/Core/Env.php.owa-update";
        check( posix_mkfifo( $fifo, 0644 ), 'could not make the FIFO' );

        $proc   = proc_open( [ PHP_BINARY, '-d', "curl.cainfo=$ca", 'cli.php', 'cmd=core-update' ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes, $site );
        $marker = "$site/owa-update.php";

        for ( $i = 0; $i < 300 && ! str_contains( (string) @file_get_contents( $marker ), '"phase":"copying"' ); $i++ ) {

            usleep( 100000 );
        }

        check( str_contains( (string) @file_get_contents( $marker ), '"phase":"copying"' ), 'the install did not start copying' );

        // While it runs: the web answers 503, the REST API says busy, the CLI waits.
        $webPort     = freePort();
        $server->web = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$webPort", '-t', $site ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes2 );
        usleep( 500000 );

        [ $status, $body ] = web( $webPort, 'index.php' );
        check( $status === 503 && str_contains( $body, 'OWA is updating' ), "index.php answered $status during the install" );
        [ $status, $body ] = web( $webPort, 'api/index.php' );
        check( $status === 503 && str_contains( $body, '"busy"' ), "the REST API answered $status during the install" );
        [ $status, $body ] = web( $webPort, 'owa-update.php' );
        check( $body === '', 'the install marker shows its contents over HTTP' );
        proc_terminate( $server->web );

        [ $code, $out ] = next_run( $site, $ca );
        check( $code === 75 && str_contains( $out, 'OWA is installing an update' ), "a CLI command during the install exited $code:\n$out" );

        // Killed: the marker stays, saying copying, and the lock's flock goes
        // with the process. What it was writing is left behind, as a real kill leaves it.
        $pid = (int) proc_get_status( $proc )['pid'];
        posix_kill( $pid, 9 );
        proc_close( $proc );
        unlink( $fifo );
        file_put_contents( $fifo, 'half written' );

        // The next run puts the installed release back, and it is skipped after.
        [ $code, $out ] = cli( $site, $ca, 'cmd=schedule-run' );
        check( $code === 0 && envVersion( $site ) === FROM && ! file_exists( $marker ), "the run after the kill did not put " . FROM . " back (exit $code):\n$out" );
        check( str_contains( (string) ( failure( $site )['error'] ?? '' ), 'The copy stopped before it finished' ), 'last-failure.json does not say the copy was killed: ' . json_encode( failure( $site ) ) );

        [ , $out ] = release_check( $site, $ca );
        check( envVersion( $site ) === FROM && str_contains( $out, 'skipped' ), "the killed install was tried again on its own:\n$out" );

        // By hand, it installs.
        [ $code, $out ] = cli( $site, $ca, 'cmd=core-update --force' );
        check( $code === 0, "core-update --force exited $code:\n$out" );
        next_run( $site, $ca );
        installed( $site, $ca, TO );
    },

    // ---- after the new release has started -----------------------------------

    'newer-release-after-a-failure' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $bad  = package( $build, $work, 'newer-bad', TO, withSchemaUpdate( false ) );
        $good = package( $build, $work, 'newer-good', '2.0.92' );
        publish( $www, $base, $bad, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json" );
        release_check( $site, $ca );
        next_run( $site, $ca );
        check( envVersion( $site ) === TO && ! empty( failure( $site )['booted'] ), 'the failing install did not leave ' . TO . ' with its schema update failed' );

        // The release that fixes it installs over it.
        publish( $www, $base, $good, '2.0.92' );
        [ , $out ] = release_check( $site, $ca );
        check( envVersion( $site ) === '2.0.92', "2.0.92 was not installed over the failed install:\n$out" );
        next_run( $site, $ca );
        installed( $site, $ca, '2.0.92' );
    },

    // ---- a newer release installed meanwhile ---------------------------------

    'installed-past' => function () use ( $build, $work, $db, $from, $www, $base, $ca ) {

        $to   = package( $build, $work, 'past', TO );
        publish( $www, $base, $to, TO );
        $site = installFrom( $from, $work, $db, "$base/releases.json", later() );

        release_check( $site, $ca );
        check( downloaded( $site, TO ) && envVersion( $site ) === FROM, 'it was not downloaded to wait for its window' );

        // 2.0.92 put in place by hand, as an operator unpacking it would leave Core/Env.php.
        $env = (string) file_get_contents( "$site/Core/Env.php" );
        file_put_contents( "$site/Core/Env.php", str_replace( "const VERSION = '" . FROM . "';", "const VERSION = '2.0.92';", $env ) );

        // Nothing newer is listed: what was downloaded goes.
        [ , $out ] = release_check( $site, $ca );
        check( envVersion( $site ) === '2.0.92', "the older " . TO . " was installed over 2.0.92:\n$out" );
        check( ! downloaded( $site, TO ), "the downloaded " . TO . ", older than what is installed, was kept:\n$out" );
    },

];

$only = array_slice( $argv, 1 );

foreach ( $scenarios as $name => $scenario ) {

    if ( $only && ! in_array( $name, $only, true ) ) {

        continue;
    }

    $t = microtime( true );

    try {

        $skipped = $scenario();
        say( is_string( $skipped ) ? "SKIP $name: $skipped" : sprintf( 'PASS %s (%.0fs)', $name, microtime( true ) - $t ) );

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
