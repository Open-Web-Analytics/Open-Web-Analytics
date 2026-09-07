<?php
namespace OWA\Module\Base\Controller;


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
// $Id$
//


/**
 * Server Environment Check Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class InstallCheckEnv extends \OWA\Core\Controller\Install {

    /**
     * The lowest PHP this release runs on.
     *
     * The same number composer.json requires. It was a hand-rolled comparison
     * -- `$version[0] < 5 && $version[1] < 2` -- which cannot express a
     * requirement at all: on PHP 8.2 the first term is false, so the whole
     * check passed, and on PHP 5.1 it passed too because 5 is not less than 5.
     * It has been unable to fail for every PHP anyone still runs.
     */
    const MIN_PHP_VERSION = '8.2';

    /**
     * Extensions OWA cannot run without, and what each is for.
     *
     * Checked here because the alternative is finding out later and worse: with
     * no database driver the wizard takes a full page of connection details and
     * then fails on connect, which reads as "my credentials are wrong".
     */
    const REQUIRED_EXTENSIONS = array(
        'json'     => 'Encoding stored report definitions and API responses.',
        'mbstring' => 'Handling non-ASCII page titles, URLs and search terms.',
        'pcre'     => 'Matching URLs, goal conditions and constraint values.',
    );

    function action() {

        $checks = array();

        // PHP itself.
        $checks[] = $this->check(
            'PHP version',
            phpversion(),
            version_compare( phpversion(), self::MIN_PHP_VERSION, '>=' ),
            sprintf( 'OWA needs PHP %s or newer. Ask your host to upgrade, or point '
                   . 'this virtual host at a newer PHP.', self::MIN_PHP_VERSION ) );

        /*
         * A database driver: PDO with a MySQL driver, or mysqli. Either one is
         * enough -- Db picks whichever is present -- so this is one check
         * rather than three, and it says which ones it looked for.
         */
        $drivers = array();

        if ( extension_loaded( 'pdo_mysql' ) ) {
            $drivers[] = 'pdo_mysql';
        }

        if ( extension_loaded( 'mysqli' ) ) {
            $drivers[] = 'mysqli';
        }

        $checks[] = $this->check(
            'Database driver',
            $drivers ? implode( ', ', $drivers ) : 'none found',
            (bool) $drivers,
            'OWA needs the pdo_mysql or mysqli PHP extension to reach a database. '
          . 'Install one and restart PHP.' );

        foreach ( self::REQUIRED_EXTENSIONS as $extension => $what_for ) {

            $checks[] = $this->check(
                sprintf( 'PHP extension: %s', $extension ),
                extension_loaded( $extension ) ? 'loaded' : 'missing',
                extension_loaded( $extension ),
                $what_for . ' Install the ' . $extension . ' extension and restart PHP.' );
        }

        // Where OWA writes.
        foreach ( array( 'logs' => 'Log directory', 'caches' => 'Cache directory' )
                  as $dir => $label ) {

            $path = OWA_DATA_DIR . $dir . '/';

            $checks[] = $this->check(
                $label,
                is_writable( $path ) ? 'writable' : 'not writable',
                is_writable( $path ),
                sprintf( 'Make %s writable by the user your web server runs as.', $path ) );
        }

        /*
         * Whether the config file can be WRITTEN, which nothing checked.
         *
         * The next screen collects database details and then calls
         * createConfigFile(), which fopen()s the path for writing without
         * asking first -- so on a docroot the web server cannot write to, an
         * author filled in the whole form before anything went wrong, and what
         * went wrong was a file handle failing rather than a message. Asked
         * here, they are told before they type anything.
         *
         * An existing file is fine: that path skips the form entirely.
         */
        $config_file = $this->c->get( 'base', 'config_file' );
        $config_dir  = dirname( $config_file );

        $config_writable = file_exists( $config_file )
            ? is_readable( $config_file )
            : is_writable( $config_dir );

        $checks[] = $this->check(
            'Configuration file',
            file_exists( $config_file )
                ? ( is_readable( $config_file ) ? 'present' : 'present, not readable' )
                : ( is_writable( $config_dir ) ? 'can be created' : 'cannot be created' ),
            $config_writable,
            file_exists( $config_file )
                ? sprintf( 'Make %s readable by the user your web server runs as.', $config_file )
                : sprintf( 'Make %s writable so the installer can create owa-config.php, or '
                         . 'copy owa-config-dist.php to owa-config.php yourself and fill it in.',
                           $config_dir ) );

        // The two directories a source checkout does not come with.
        $checks[] = $this->check(
            'Dependencies',
            is_dir( OWA_VENDOR_DIR ) ? 'installed' : 'missing',
            is_dir( OWA_VENDOR_DIR ),
            "Run 'composer install' in the top level OWA directory." );

        /*
         * The webpack build emits the tracker and reporting bundles under
         * public/base/dist, and public/ is gitignored -- so a fresh source
         * checkout has none of it until 'npm run build' runs.
         */
        $built = \OWA\Core\Template::assetsAreBuilt();

        $checks[] = $this->check(
            'Built assets',
            $built ? 'built' : 'missing',
            $built,
            "Run 'npm run build' in the top level OWA directory." );

        /*
         * The database connection, only when there is already a config file
         * naming one. Without a config file there is nothing to connect with,
         * and the next screen is where those details get entered.
         */
        $config_file_present = (bool) $this->c->isConfigFilePresent();

        if ( $config_file_present ) {

            $connected = (bool) $this->checkDbConnection();

            $checks[] = $this->check(
                'Database connection',
                $connected ? 'connected' : 'failed',
                $connected,
                'Check the database settings in ' . $config_file . '.' );
        }

        $failed = array_values( array_filter( $checks, static function ( $check ) {

            return ! $check['passed'];
        } ) );

        if ( ! $failed ) {

            if ( $config_file_present ) {

                // Everything is in place already; go straight to the defaults.
                $this->setRedirectAction( 'base.installDefaultsEntry' );

                return;
            }

            $this->setView( 'base.install' );
            $this->setSubview( 'base.installConfigEntry' );

            return;
        }

        /*
         * Every check, not just the failures.
         *
         * The screen used to list only what was wrong, which left no way to
         * tell "this was checked and is fine" from "this was never looked at"
         * -- and on a screen whose whole job is to describe the environment,
         * that is most of the information.
         */
        $this->set( 'checks', $checks );
        $this->set( 'errors', $failed );
        $this->setView( 'base.install' );
        $this->setSubview( 'base.installCheckEnv' );
    }

    /**
     * One environment check, in the shape the template renders.
     *
     * @param string $name   what was looked at
     * @param string $value  what was found
     * @param bool   $passed
     * @param string $msg    what to do about it, shown only when it failed
     * @return array
     */
    private function check( $name, $value, $passed, $msg ) {

        return array(
            'name'   => $name,
            'value'  => $value,
            'passed' => (bool) $passed,
            'msg'    => $passed ? '' : $msg,
        );
    }
}

?>
