<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * What this server has to provide before OWA can run.
 *
 * Extracted from InstallCheckEnv so the installer and `cmd=instance-info` ask
 * the same questions. They were the same questions in two places for about ten
 * minutes, which is how long it takes for the two to start disagreeing about
 * what "supported" means -- and the disagreement is invisible, because nobody
 * runs the installer on a working instance or the diagnostic on a fresh one.
 *
 * Each check answers a name, what was found, whether that passes, and what to
 * do about it. The remedy travels with the check rather than being looked up by
 * the caller: a check that cannot say how to fix itself is a check that gets
 * reported as "failed" and no more.
 */
class EnvironmentCheck {

    /**
     * The lowest PHP this release runs on. The same number composer.json requires.
     */
    const MIN_PHP_VERSION = '8.2';

    /**
     * Extensions OWA cannot run without, and what each is for.
     *
     * Checked because the alternative is finding out later and worse: with no
     * database driver the installer takes a full page of connection details and
     * then fails on connect, which reads as "my credentials are wrong".
     */
    const REQUIRED_EXTENSIONS = array(
        'json'     => 'Encoding stored report definitions and API responses.',
        'mbstring' => 'Handling non-ASCII page titles, URLs and search terms.',
        'pcre'     => 'Matching URLs, goal conditions and constraint values.',
        'zlib'     => 'Compressing and reading session recordings.',
    );

    /**
     * Every environment check, in report order.
     *
     * @param string $config_file  path to owa-config.php
     * @return array[] each ['name','value','passed','msg']
     */
    public static function all( $config_file ) {

        $checks = array();

        $checks[] = self::check(
            'PHP version',
            phpversion(),
            version_compare( phpversion(), self::MIN_PHP_VERSION, '>=' ),
            sprintf( 'OWA needs PHP %s or newer. Ask your host to upgrade, or point '
                   . 'this virtual host at a newer PHP.', self::MIN_PHP_VERSION ),
            sprintf( 'PHP is %s or later.', self::MIN_PHP_VERSION ) );

        $checks[] = self::integerSize( PHP_INT_SIZE );

        $checks[] = self::databaseDriver(
            extension_loaded( 'pdo_mysql' ),
            extension_loaded( 'mysqli' ),
            function_exists( 'mysqli_stmt_get_result' ),
            defined( 'OWA_DB_TYPE' ) ? (string) OWA_DB_TYPE : 'mysql' );

        foreach ( self::REQUIRED_EXTENSIONS as $extension => $what_for ) {

            $checks[] = self::check(
                sprintf( 'PHP extension: %s', $extension ),
                extension_loaded( $extension ) ? 'loaded' : 'missing',
                extension_loaded( $extension ),
                $what_for . ' Install the ' . $extension . ' extension and restart PHP.',
                sprintf( '`%s` is loaded.', $extension ) );
        }

        /*
         * Where OWA writes: the data directory (OWA_DATA_DIR, else owa-data/),
         * its logs/, and the cache directory (OWA_CACHE_DIR, else caches/ in
         * the data directory). The data directory itself need only exist: what
         * writes directly under it (update-ua-regexes, update-geoip-db) checks
         * for itself.
         */
        $data = \OWA\Core\Env::dataDir();

        $checks[] = self::check(
            'Data directory',
            sprintf( '%s (%s)', $data, is_dir( $data ) ? ( is_writable( $data ) ? 'writable' : 'not writable' ) : 'missing' ),
            is_dir( $data ),
            sprintf( 'Create %s, or set OWA_DATA_DIR in owa-config.php to a directory that exists.', $data ),
            'The data directory exists: `owa-data/` in the OWA directory, or `OWA_DATA_DIR`.' );

        foreach ( array(
            'Log directory'   => array( $data . 'logs/', '`logs/` in the data directory is writable.' ),
            'Cache directory' => array( \OWA\Core\Env::cacheDir(), 'The cache directory is writable: `caches/` in the data directory, or `OWA_CACHE_DIR`.' ),
        ) as $label => list( $path, $requires ) ) {

            $checks[] = self::check(
                $label,
                sprintf( '%s (%s)', $path, is_writable( $path ) ? 'writable' : 'not writable' ),
                is_writable( $path ),
                sprintf( 'Make %s writable by the user your web server runs as.', $path ),
                $requires );
        }

        /*
         * Whether the config file can be WRITTEN.
         *
         * On a docroot the web server cannot write to, an author used to fill in
         * the whole database form before anything went wrong, and what went
         * wrong was a file handle failing rather than a message. An existing
         * file is fine: that path skips the form entirely.
         */
        $config_dir = dirname( $config_file );

        $config_ok = file_exists( $config_file )
            ? is_readable( $config_file )
            : is_writable( $config_dir );

        $checks[] = self::check(
            'Configuration file',
            file_exists( $config_file )
                ? ( is_readable( $config_file ) ? 'present' : 'present, not readable' )
                : ( is_writable( $config_dir ) ? 'can be created' : 'cannot be created' ),
            $config_ok,
            file_exists( $config_file )
                ? sprintf( 'Make %s readable by the user your web server runs as.', $config_file )
                : sprintf( 'Make %s writable so the installer can create owa-config.php, or '
                         . 'copy owa-config-dist.php to owa-config.php yourself and fill it in.',
                           $config_dir ),
            '`owa-config.php` exists and is readable, or its directory is writable so the installer can create it.' );

        // The two directories a source checkout does not come with.
        $checks[] = self::check(
            'Dependencies',
            is_dir( \OWA\Core\Env::vendorDir() ) ? 'installed' : 'missing',
            is_dir( \OWA\Core\Env::vendorDir() ),
            "Run 'composer install' in the top level OWA directory.",
            '`vendor/` exists.' );

        $built = \OWA\Core\Template::assetsAreBuilt();

        $checks[] = self::check(
            'Built assets',
            $built ? 'built' : 'missing',
            $built,
            "Run 'npm run build' in the top level OWA directory.",
            'The JavaScript and CSS have been built.' );

        return $checks;
    }

    /**
     * PHP built with 64-bit integers. Ids are 64-bit values built with shifts
     * (Core\Lib::wideStringGuid), which a 32-bit build truncates.
     *
     * @param int $int_size  PHP_INT_SIZE
     * @return array
     */
    public static function integerSize( $int_size ) {

        return self::check(
            '64-bit PHP',
            ( (int) $int_size * 8 ) . '-bit',
            (int) $int_size === 8,
            \OWA\Core\CoreAPI::t( 'OWA needs a 64-bit build of PHP. Install a 64-bit PHP and point this virtual host at it.' ),
            'PHP is a 64-bit build.' );
    }

    /**
     * A database driver that can load for the configured OWA_DB_TYPE.
     *
     * The default type, mysql, uses pdo_mysql where it is loaded and mysqli
     * otherwise (CoreAPI::resolveDbDriver). The mysqli driver reads prepared
     * statements with mysqli_stmt_get_result(), which exists only when mysqli
     * is built on mysqlnd.
     *
     * @param bool   $pdo_mysql  pdo_mysql is loaded
     * @param bool   $mysqli     mysqli is loaded
     * @param bool   $mysqlnd    mysqli_stmt_get_result() exists
     * @param string $db_type    OWA_DB_TYPE, or 'mysql' before there is a config file
     * @return array
     */
    public static function databaseDriver( $pdo_mysql, $mysqli, $mysqlnd, $db_type = 'mysql' ) {

        $found = array();

        if ( $pdo_mysql ) {
            $found[] = 'pdo_mysql';
        }

        if ( $mysqli ) {
            $found[] = $mysqlnd ? 'mysqli' : 'mysqli (without mysqlnd)';
        }

        $driver = \OWA\Core\CoreAPI::resolveDbDriver( $db_type === '' ? 'mysql' : $db_type, (bool) $pdo_mysql );

        if ( ! $pdo_mysql && ! $mysqli ) {

            $passed = false;
            $msg    = \OWA\Core\CoreAPI::t( 'OWA needs the pdo_mysql or mysqli PHP extension to reach a database. Install one and restart PHP.' );

        } elseif ( $driver === 'pdo_mysql' ) {

            $passed = (bool) $pdo_mysql;
            $msg    = \OWA\Core\CoreAPI::t( 'OWA_DB_TYPE asks for PDO, and the pdo_mysql extension is not loaded. Install it and restart PHP.' );

        } elseif ( $driver === 'mysql' && ! $mysqli ) {

            $passed = false;
            $msg    = \OWA\Core\CoreAPI::t( 'OWA_DB_TYPE asks for mysqli, and the mysqli extension is not loaded. Install it and restart PHP.' );

        } elseif ( $driver === 'mysql' ) {

            $passed = (bool) $mysqlnd;
            $msg    = \OWA\Core\CoreAPI::t( 'The mysqli extension is built without mysqlnd, which OWA needs. Install pdo_mysql, or a mysqli built with mysqlnd, and restart PHP.' );

        } else {

            // A third-party driver: its needs are its own.
            $passed = true;
            $msg    = '';
        }

        return self::check(
            'Database driver',
            $found ? implode( ', ', $found ) : 'none found',
            $passed,
            $msg,
            '`pdo_mysql`, or `mysqli` built with mysqlnd, is loaded.' );
    }

    /**
     * One check, in the shape both callers render.
     *
     * @param string $requires  what passes, in a sentence, whatever this server
     *                          has: the wiki's requirements table reads it
     * @return array
     */
    public static function check( $name, $value, $passed, $msg, $requires = '' ) {

        return array(
            'name'     => $name,
            'value'    => $value,
            'passed'   => (bool) $passed,
            'msg'      => $passed ? '' : $msg,
            'requires' => (string) $requires,
        );
    }
}
