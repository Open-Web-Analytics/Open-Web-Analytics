<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * What the Updates screen says about automatic updates, from what the web
 * user can read: the release tarballs downloaded, last-failure.json (0644 in
 * the updates directory), the install marker, the installed version and the
 * settings. The screen changes nothing itself: its actions are jobs the
 * scheduler runs as the install owner (Controller\UpdatesRun).
 */
class UpdatesState {

    /** The screen's actions, and the command and parameters each queues. */
    const ACTIONS = [
        'check'   => [ 'core-update', [] ],
        'install' => [ 'core-update', [ 'force' => 1 ] ],
    ];

    /**
     * The installation's state, read now.
     *
     * @return array{lines: list<string>, actions: list<string>}
     */
    public static function read() {

        $downloads = new Downloads();
        $check     = new ReleaseCheck( $downloads );

        return self::describe( $downloads->versions(), $downloads->failure(), \OWA\Core\Env::version(),
            Vcs::checkout( \OWA\Core\Env::root() ), (bool) CoreAPI::getSetting( 'base', 'auto_update' ), $downloads->count(),
            $check->window(), Downloads::updater()['readMarker']( \OWA\Core\Env::root() ) );
    }

    /**
     * The sentences the screen shows, and the actions it offers.
     *
     * @param  list<string>                                                              $downloaded the versions downloaded, newest first
     * @param  array<string, mixed>|null                                                 $failure    last-failure.json
     * @param  string                                                                    $installed
     * @param  string|null                                                               $checkout   the version control directory OWA runs from
     * @param  bool                                                                      $enabled    automatic updates on
     * @param  int                                                                       $dirs       updates-* directories found
     * @param  array{days: list<int>, start: string, end: string, timezone: string}|null $window
     * @param  array<string, mixed>|null                                                 $marker     the install marker, while an install is under way
     * @return array{lines: list<string>, actions: list<string>}
     */
    public static function describe( array $downloaded, $failure, $installed, $checkout, $enabled, $dirs, $window, $marker = null ) {

        $lines = [ sprintf( CoreAPI::t( 'Installed: OWA %s.' ), $installed ) ];

        if ( $checkout !== null ) {

            $lines[] = sprintf( CoreAPI::t( 'OWA runs from a version control checkout (%s): it is updated with that, never automatically.' ), $checkout );

            return [ 'lines' => $lines, 'actions' => [] ];
        }

        $lines[] = ! $enabled ? CoreAPI::t( 'Automatic updates are off.' )
            : ( $window === null ? CoreAPI::t( 'Automatic updates are on: a release installs as soon as it is downloaded.' )
                : sprintf( CoreAPI::t( 'Automatic updates are on: a release installs %s.' ), ReleaseCheck::describeWindow( $window ) ) );

        if ( $dirs > 1 ) {

            $lines[] = CoreAPI::t( 'There is more than one updates-* directory in the data directory: nothing is downloaded or installed until all but one are removed.' );
        }

        if ( $marker !== null ) {

            $lines[] = sprintf( CoreAPI::t( 'OWA %s is being installed.' ), (string) ( $marker['to'] ?? '?' ) );

            return [ 'lines' => $lines, 'actions' => [ 'check' ] ];
        }

        $newer = array_values( array_filter( $downloaded, static fn ( $v ) => Version::parse( (string) $installed ) !== null && Version::compare( $v, (string) $installed ) > 0 ) );

        if ( $newer ) {

            $lines[] = sprintf( CoreAPI::t( 'OWA %s is downloaded and checked.' ), $newer[0] );
        }

        $err = trim( (string) ( $failure['error'] ?? '' ) );

        if ( $err !== '' && ! empty( $failure['booted'] ) ) {

            $lines[] = sprintf( CoreAPI::t( 'OWA %1$s is installed, and finishing it failed: %2$s Fix the cause, then run php cli.php cmd=core-update-schema' ),
                (string) ( $failure['version'] ?? '' ), rtrim( $err, '.' ) . '.' );

        } elseif ( $err !== '' ) {

            $lines[] = sprintf( CoreAPI::t( 'Installing OWA %1$s failed: %2$s Automatic updates skip it until a newer release.' ),
                (string) ( $failure['version'] ?? '' ), rtrim( $err, '.' ) . '.' );
        }

        return [ 'lines' => $lines, 'actions' => [ 'check', 'install' ] ];
    }
}
