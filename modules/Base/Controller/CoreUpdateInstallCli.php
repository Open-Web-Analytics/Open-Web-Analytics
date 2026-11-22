<?php
namespace OWA\Module\Base\Controller;

/**
 * Install the staged release now (Step 2 for it, then Steps 3 and 4).
 *
 *   php cli.php cmd=core-update-install
 *
 * Handled by cli.php before OWA loads (updater.php `handoff`), so the new
 * release boots in a process that holds none of the old code. This class
 * declares the command for the registry and the wiki; reached, it means the
 * hand-off did not run, which only an install without updater.php can see.
 */
class CoreUpdateInstallCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Installs the staged release now, whether cmd=core-update-stage or the release check staged it, whatever the automatic update settings say: backs up and copies its files, then runs its schema updates and republishes the tracking bundles. A failing install starts over with fresh attempts.',
            'arguments'   => [
                '--force' => 'Install it even when it is not newer than the installed release (going back, or the same release again).',
            ],
        ];
    }

    /** See \OWA\Core\Controller\Cli::needsBoot(). It never boots: cli.php hands off before OWA loads. */
    public static function needsBoot() {

        return false;
    }

    /** See \OWA\Core\Controller\Cli::runWithoutBoot(). */
    public static function runWithoutBoot( array $params ) {

        fwrite( STDERR, "cmd=core-update-install is run by cli.php before OWA loads, from updater.php; this installation has no updater.php.\n" );

        return 1;
    }
}
