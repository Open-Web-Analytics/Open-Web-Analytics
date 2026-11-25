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
                'web-group=<group>'     => 'The group the web server runs as, which owa-data/logs/ is given. Default: the OWA directory\'s group.',
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

    /**
     * The data directory as it should be where the web server runs as a user
     * other than this one: writable only by its owner, so the overrides in its
     * conf/ are run (Core\Overrides), but for logs/, the one folder the web
     * server writes (its error log, the file event queue). logs/ is given
     * $web_group (else the OWA directory's group), group write, and setgid, so
     * the files made there keep the group.
     *
     * Changed only where this user owns the directory: OWA never changes
     * another's file modes. What it changed, or could not, is said.
     *
     * @param  string $web_group
     * @return list<string>
     */
    protected function secureDataDir( $web_group = '' ) {

        if ( PHP_OS_FAMILY === 'Windows' || ! function_exists( 'posix_geteuid' ) ) {

            return [];
        }

        $me   = posix_geteuid();
        $data = rtrim( \OWA\Core\Env::dataDir(), '/' );
        $out  = [];

        foreach ( [ $data, $data . '/conf' ] as $dir ) {

            if ( is_dir( $dir ) && @fileowner( $dir ) === $me && ( fileperms( $dir ) & 0022 ) ) {

                $out[] = @chmod( $dir, fileperms( $dir ) & 07755 )
                    ? sprintf( '%s is now writable by its owner only.', $dir )
                    : sprintf( 'Could not remove group and other write from %s.', $dir );
            }
        }

        $logs  = $data . '/logs';
        $group = $web_group !== '' ? $web_group : ( posix_getgrgid( (int) @filegroup( \OWA\Core\Env::root() ) )['name'] ?? '' );

        if ( is_dir( $logs ) && ! is_link( $logs ) && @fileowner( $logs ) === $me ) {

            $has = posix_getgrgid( (int) filegroup( $logs ) )['name'] ?? '';

            if ( $group !== '' && $has !== $group ) {

                $out[] = @chgrp( $logs, $group )
                    ? sprintf( '%s now belongs to group %s.', $logs, $group )
                    : sprintf( 'Could not give %1$s to group %2$s: make it writable by the user the web server runs as.', $logs, $group );
            }

            $mode = fileperms( $logs ) & 07777;
            $want = ( $mode | 02070 ) & ~0002;

            if ( $mode !== $want ) {

                $out[] = @chmod( $logs, $want )
                    ? sprintf( '%s is now writable by its group.', $logs )
                    : sprintf( 'Could not make %s writable by its group.', $logs );
            }
        }

        return $out;
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

                foreach ( $this->secureDataDir( (string) $this->getParam( 'web-group' ) ) as $said ) {

                    \OWA\Core\CoreAPI::notice( $said );
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
