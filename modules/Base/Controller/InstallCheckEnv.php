<?php
namespace OWA\Module\Base\Controller;

/**
 * Server Environment Check Controller
 */

class InstallCheckEnv extends \OWA\Core\Controller\Install {

    public function action() {

        /*
         * The checks themselves live in EnvironmentCheck, because
         * cmd=instance-info asks exactly the same questions of a running
         * instance. Two copies would disagree about what "supported" means, and
         * the disagreement would be invisible: nobody runs the installer on a
         * working instance, or the diagnostic on a fresh one.
         */
        $config_file = \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' );

        // Paths relative to OWA's directory: anyone can open this page
        // before OWA is installed.
        $checks = \OWA\Module\Base\Classes\EnvironmentCheck::all( $config_file, true );

        /*
         * The database connection, only when there is already a config file
         * naming one. Without a config file there is nothing to connect with,
         * and the next screen is where those details get entered.
         */
        $config_file_present = (bool) $this->c->isConfigFilePresent();

        if ( $config_file_present ) {

            $connected = (bool) $this->checkDbConnection();

            $checks[] = \OWA\Module\Base\Classes\EnvironmentCheck::check(
                \OWA\Core\CoreAPI::t( 'Database connection' ),
                $connected ? \OWA\Core\CoreAPI::t( 'connected' ) : \OWA\Core\CoreAPI::t( 'failed' ),
                $connected,
                sprintf( \OWA\Core\CoreAPI::t( 'Check the database settings in %s.' ), basename( (string) $config_file ) ) );

            // A warning, which does not stop the install; the next screen repeats it.
            if ( $connected ) {

                $checks[] = \OWA\Module\Base\Classes\EnvironmentCheck::timeZoneTables(
                    \OWA\Module\Base\Classes\DatabaseRequirement::timeZoneWarning() );
            }
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
         * Every check, not just the failures, so "this was checked and is fine"
         * reads differently from "this was never looked at".
         */
        $this->set( 'checks', $checks );
        $this->set( 'errors', $failed );
        $this->setView( 'base.install' );
        // Both, not just the failures: the template lists every check so that
        // "looked at and fine" is distinguishable from "never looked at".
        $this->setSubview( 'base.installCheckEnv', [ 'template' => 'install_check_env.php', 'vars' => [ 'checks', 'errors' ] ] );
    }

}
