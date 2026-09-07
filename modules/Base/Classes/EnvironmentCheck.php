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
                   . 'this virtual host at a newer PHP.', self::MIN_PHP_VERSION ) );

        /*
         * A database driver: PDO with a MySQL driver, or mysqli. Either one is
         * enough -- Db picks whichever is present -- so this is one check rather
         * than three, and it says which ones it looked for.
         */
        $drivers = array();

        if ( extension_loaded( 'pdo_mysql' ) ) {
            $drivers[] = 'pdo_mysql';
        }

        if ( extension_loaded( 'mysqli' ) ) {
            $drivers[] = 'mysqli';
        }

        $checks[] = self::check(
            'Database driver',
            $drivers ? implode( ', ', $drivers ) : 'none found',
            (bool) $drivers,
            'OWA needs the pdo_mysql or mysqli PHP extension to reach a database. '
          . 'Install one and restart PHP.' );

        foreach ( self::REQUIRED_EXTENSIONS as $extension => $what_for ) {

            $checks[] = self::check(
                sprintf( 'PHP extension: %s', $extension ),
                extension_loaded( $extension ) ? 'loaded' : 'missing',
                extension_loaded( $extension ),
                $what_for . ' Install the ' . $extension . ' extension and restart PHP.' );
        }

        // Where OWA writes.
        foreach ( array( 'logs' => 'Log directory', 'caches' => 'Cache directory' )
                  as $dir => $label ) {

            $path = OWA_DATA_DIR . $dir . '/';

            $checks[] = self::check(
                $label,
                is_writable( $path ) ? 'writable' : 'not writable',
                is_writable( $path ),
                sprintf( 'Make %s writable by the user your web server runs as.', $path ) );
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
                           $config_dir ) );

        // The two directories a source checkout does not come with.
        $checks[] = self::check(
            'Dependencies',
            is_dir( OWA_VENDOR_DIR ) ? 'installed' : 'missing',
            is_dir( OWA_VENDOR_DIR ),
            "Run 'composer install' in the top level OWA directory." );

        $built = \OWA\Core\Template::assetsAreBuilt();

        $checks[] = self::check(
            'Built assets',
            $built ? 'built' : 'missing',
            $built,
            "Run 'npm run build' in the top level OWA directory." );

        return $checks;
    }

    /**
     * One check, in the shape both callers render.
     *
     * @return array
     */
    public static function check( $name, $value, $passed, $msg ) {

        return array(
            'name'   => $name,
            'value'  => $value,
            'passed' => (bool) $passed,
            'msg'    => $passed ? '' : $msg,
        );
    }
}
