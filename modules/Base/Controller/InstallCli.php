<?php
namespace OWA\Module\Base\Controller;

/**
 * Installation CLI Controller
 */

class InstallCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Installs OWA: applies the database schema, creates the admin user and adds the first tracked website. owa-config.php must exist first.',
            'arguments'   => [
                'user_id=<name>'        => 'Required. The admin account\'s user name.',
                'password=<password>'   => 'Required. The admin account\'s password, at least ' . \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH . ' characters.',
                'email_address=<email>' => 'Required. The admin account\'s email address, used for password recovery.',
                'domain=<url>'          => 'Required. The domain of the first website to track.',
                'description=<text>'    => 'A description of the first website.',
                'site_family=<text>'    => 'A family name to group the first website under.',
            ],
        ];
    }

    public function __construct($params) {
        define('OWA_INSTALLING', true);
        parent::__construct($params);
    }

    /**
     * The admin account's password is required, and is not the database
     * password: whoever could read owa-config.php could otherwise sign in as
     * the admin.
     */
    public function validate()
    {
        $this->addValidation('password_required', $this->getParam('password'), 'required',
            ['errorMsg' => 'A password for the admin account is required: password=<password>.']);
        $this->addValidation('password_length', $this->getParam('password'), 'stringLength', [
            'operator' => '>=',
            'length'   => \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH,
            'errorMsg' => sprintf( 'The password must be at least %d characters.', \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH ),
        ]);
    }

    /** A refused install prints why, and has done nothing. */
    public function errorAction() {

        \OWA\Core\Controller\Cli::reportInvalidArguments( $this->data['validation_errors'] ?? [] );
    }

    /**
     * What the install is done through.
     *
     * @return \OWA\Module\Base\Classes\InstallManager
     */
    protected function installManager() {

        return new \OWA\Module\Base\Classes\InstallManager();
    }

    public function action() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $im = $this->installManager();
        \OWA\Core\CoreAPI::notice('Starting OWA Install from command line.');

        //create config file
        $present = $this->c->isConfigFilePresent();

        if ( $present ) {

            $this->c->applyConfigConstants();

            // install schema
            $status = $im->installSchema();

            // schema was installed successfully
            if ($status === true) {

                /*
                 * The admin, the first site and the install-complete flag are
                 * saved together or not at all (InstallManager::finishInstall()):
                 * a run that fails part way can be run again.
                 */
                $site_id = $im->finishInstall( function () use ( $im ) {

                    $im->createAdminUser($this->getParam('user_id'), $this->getParam('email_address'), $this->getParam('password') );

                    return $im->createDefaultSite(
                            $this->getParam('domain'),
                            $this->getParam('domain'),
                            $this->getParam('description'),
                            $this->getParam('site_family')
                    );
                } );

                if ( ! $site_id ) {

                    return $this->fail('The install did not complete, and nothing was saved. Check the error log, then run this command again.');
                }

                $im->announceInstallComplete( $this->getParam('user_id'), $site_id );

                \OWA\Core\CoreAPI::notice('Install Completed.');

                // Without the scheduler no report shows anything: say so where
                // the install is run, as the web installer's last page does.
                \OWA\Core\CoreAPI::notice('Add this to the crontab of the user that owns the OWA files, '
                    . 'or reports stay empty: ' . \OWA\Module\Base\Classes\SchedulerHealth::cronLine());
                \OWA\Core\CoreAPI::notice('Then check it with: php cli.php cmd=schedule-status');

                // Automatic updates write OWA's code as the cron user, usually
                // this one: say now if it cannot, not at the first release.
                $root = \OWA\Core\Env::root();

                if ( \OWA\Module\Base\Classes\AutoUpdate\Vcs::checkout( $root ) === null
                        && ( $dirs = \OWA\Module\Base\Classes\AutoUpdate\Preflight::unwritableCode( $root, [ \OWA\Core\Env::dataDir() ] ) ) ) {

                    \OWA\Core\CoreAPI::notice( sprintf( 'Automatic updates run as the cron user and write OWA\'s code. This user cannot write %d of its directories, such as %s. '
                        . 'Give them to the user the cron entry runs as.', count( $dirs ), $dirs[0] === '' ? 'the OWA directory' : $dirs[0] . '/' ) );
                }

                if ( \OWA\Module\Base\Classes\AutoUpdate\Vcs::checkout( $root ) === null ) {

                    foreach ( \OWA\Module\Base\Classes\AutoUpdate\Preflight::temp( 0 ) as $problem ) {

                        \OWA\Core\CoreAPI::notice( $problem );
                    }
                }

            // schema was not installed successfully
            } else {
                return $this->fail('Aborting embedded install due to errors installing schema. Try dropping all OWA tables and try again.');
            }


        } else {
            return $this->fail("Could not locate config file. Aborting installation.");
        }
    }
}
