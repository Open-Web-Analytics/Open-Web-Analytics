<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * What the Updates screen says about automatic updates, from what the web
 * user can read: staged.json and install.json (0644 in the updates
 * directory), the installed version and the settings. The screen changes
 * nothing itself: its actions are jobs the scheduler runs as the install
 * owner (Controller\UpdatesRun).
 */
class UpdatesState {

    /** The screen's actions, and the command each queues. */
    const ACTIONS = [
        'check'  => 'core-update-check',
        'now'    => 'core-update-ignore-window',
        'cancel' => 'core-update-unstage',
    ];

    /**
     * The installation's state, read now.
     *
     * @return array{lines: list<string>, actions: list<string>}
     */
    public static function read() {

        $staging = new Staging();

        return self::describe( $staging->staged(), $staging->install(), \OWA\Core\Env::version(), time(),
            Vcs::checkout( \OWA\Core\Env::root() ), (bool) CoreAPI::getSetting( 'base', 'auto_update' ), $staging->count(),
            $staging->staged() !== null && $staging->isStaged( (string) $staging->staged()['version'] ) );
    }

    /**
     * The sentences the screen shows, and the actions it offers.
     *
     * @param  array<string, mixed>|null $staged    staged.json
     * @param  array<string, mixed>|null $install   install.json
     * @param  string                    $installed
     * @param  int                       $now
     * @param  string|null               $checkout  the version control directory OWA runs from
     * @param  bool                      $enabled   automatic updates on
     * @param  int                       $dirs      updates-* directories found
     * @param  bool                      $present   staged/ holds what staged.json names
     * @return array{lines: list<string>, actions: list<string>}
     */
    public static function describe( $staged, $install, $installed, $now, $checkout, $enabled, $dirs, $present ) {

        $lines   = [ sprintf( CoreAPI::t( 'Installed: OWA %s.' ), $installed ) ];
        $actions = [];

        if ( $checkout !== null ) {

            $lines[] = sprintf( CoreAPI::t( 'OWA runs from a version control checkout (%s): it is updated with that, never automatically.' ), $checkout );

            return [ 'lines' => $lines, 'actions' => [] ];
        }

        $lines[]   = $enabled ? CoreAPI::t( 'Automatic updates are on.' ) : CoreAPI::t( 'Automatic updates are off.' );
        $actions[] = 'check';

        if ( $dirs > 1 ) {

            $lines[] = CoreAPI::t( 'There is more than one updates-* directory in the data directory: nothing is staged or installed until all but one are removed.' );
        }

        if ( $staged === null ) {

            $lines[] = CoreAPI::t( 'Nothing is staged.' );

        } else {

            $v = (string) $staged['version'];

            if ( Version::parse( $installed ) !== null && Version::compare( $v, $installed ) <= 0 && $install === null ) {

                $lines[] = sprintf( CoreAPI::t( '%1$s is staged, but %2$s is installed: it will not install, and the next release check removes it.' ), $v, $installed );

            } elseif ( ! $present ) {

                $lines[] = sprintf( CoreAPI::t( '%s is staged, but its download is missing: the next release check downloads it again.' ), $v );

            } elseif ( ! empty( $staged['by_hand'] ) ) {

                $lines[] = sprintf( CoreAPI::t( '%1$s was staged by hand. It installs only with: php cli.php cmd=core-update-install' ), $v );
                $until   = min( (int) ( $staged['staged_at'] ?? 0 ), $now ) + ReleaseCheck::HAND_HOLD;

                if ( $until > $now ) {

                    $lines[] = sprintf( CoreAPI::t( 'The release check leaves it alone until %s UTC.' ), gmdate( 'Y-m-d H:i', $until ) );
                }

            } elseif ( empty( $staged['automatic'] ) ) {

                $lines[] = sprintf( CoreAPI::t( '%s is staged but does not install itself: a requirement is not met, or an update in it must be installed by hand. php cli.php cmd=core-update-status says which.' ), $v );

            } elseif ( ! empty( $staged['install_now'] ) || ( $staged['window'] ?? null ) === null ) {

                $lines[] = sprintf( CoreAPI::t( '%s is staged and installs at the next scheduler run.' ), $v );

            } else {

                $lines[]   = sprintf( CoreAPI::t( '%1$s is staged and installs %2$s.' ), $v, ReleaseCheck::describeWindow( $staged['window'] ) );
                $actions[] = $install === null ? 'now' : null;
            }

            if ( $install === null ) {

                $actions[] = 'cancel';
            }
        }

        if ( $install !== null ) {

            $iv  = (string) ( $install['version'] ?? '' );
            $err = trim( (string) ( $install['error'] ?? '' ) );

            $lines[] = $err === ''
                ? sprintf( CoreAPI::t( 'An install of %s is in progress.' ), $iv )
                : sprintf( CoreAPI::t( 'An install of %1$s is unfinished: attempt %2$d failed (%3$s). It is tried again after %4$s UTC.' ),
                    $iv, (int) ( $install['attempts'] ?? 0 ), $err, gmdate( 'Y-m-d H:i', (int) ( $install['retry_after'] ?? $now ) ) );
        }

        return [ 'lines' => $lines, 'actions' => array_values( array_filter( $actions ) ) ];
    }
}
