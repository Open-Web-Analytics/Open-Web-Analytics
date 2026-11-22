<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * Step 1: release check. Finds the release this installation updates to,
 * stages it, judges it, and points staged.json at it when it can be
 * installed. Installs nothing: Steps 2-4 read staged.json.
 *
 * One listing of the releases feeds the notifications bell first, on every
 * run whatever follows (the job cmd=fetch-notifications used to be). Then, in
 * order, stopping at the first that applies:
 *   1. Not a full server role, a version control checkout, an unreadable
 *      installed version, automatic updates off, or no newer release the
 *      settings choose: the slot is emptied (end()).
 *   2. The listing failed: staged.json is only repaired (repair()).
 *   3. An unfinished install of the chosen release: left as it is.
 *   4. A release staged by hand, for HAND_HOLD after it was staged: left as
 *      it is, whatever else applies.
 *   5. Downloaded, checked against its SHA-256 and its signature, and
 *      unpacked into staged.tmp/, then swapped into staged/ in place of what
 *      was there, an unfinished install of another release with it
 *      (Staging::swapIn()); again if
 *      GitHub's digest for it changes (the asset was replaced). A failure
 *      leaves the slot as it was, and the next run tries again. The first
 *      failure puts a notice in the bell; a week of them e-mails the admins.
 *   6. The preflight, every run, from the slot. Unmet, or an update this
 *      installation would run that must be installed by hand
 *      ($manual_install): staged.json says it does not install itself, and
 *      the admins are told once.
 *   7. Otherwise staged.json says it installs itself, with the window the
 *      settings give now, and the admins are told when.
 *
 * One release is staged at a time (Staging). Writes happen holding the
 * install lock, taken without waiting; when an install holds it, the run
 * changes nothing.
 *
 * @phpstan-import-type Release from Releases
 * @phpstan-type Outcome array{outcome: string, version: string|null, lines: list<string>, problems: list<string>}
 */
class ReleaseCheck {

    const ROLE      = 'not_full_role';
    const CHECKOUT  = 'vcs_checkout';
    const OFF       = 'auto_update_off';
    const UNKNOWN   = 'unknown_version';
    const UNREACHED = 'listing_failed';
    const CURRENT   = 'up_to_date';
    const CLAIMED   = 'installing';
    const FAILED    = 'staging_failed';
    const UNMET     = 'requirements_unmet';
    const MANUAL    = 'manual_install';
    const STAGED    = 'staged';
    const BUSY      = 'install_running';
    const CHOSEN    = 'chosen';
    const HELD      = 'held_by_hand';

    /** How long a release staged by hand is left alone by the release check, in seconds. */
    const HAND_HOLD = 86400;

    /** The notices' source (NotificationManager). */
    const SOURCE = 'auto_update';

    /** How long a staging may keep failing before the admins are e-mailed. */
    const FAILING_FOR = 604800;

    const DAYS = [ 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 ];

    /** @var Staging */
    private $staging;

    /** @var string */
    private $root;

    /** @var list<string> */
    private $lines = [];

    /** @var array<string, mixed> settings, facts and services a test replaces */
    public $context;

    /**
     * @param Staging|null         $staging
     * @param array<string, mixed> $context in place of what is read from the installation, for a test
     */
    public function __construct( $staging = null, array $context = [] ) {

        $this->staging = $staging ?? new Staging();
        $this->context = $context + [
            'root'      => \OWA\Core\Env::root(),
            'installed' => \OWA\Core\Env::version(),
            'role'      => \OWA\Core\ServerRole::current()->name(),
            'enabled'   => (bool) CoreAPI::getSetting( 'base', 'auto_update' ),
            'level'     => (string) CoreAPI::getSetting( 'base', 'auto_update_level' ),
            'days'      => (string) CoreAPI::getSetting( 'base', 'auto_update_days' ),
            'window'    => CoreAPI::getSetting( 'base', 'auto_update_window' ),
            'immediate' => (bool) CoreAPI::getSetting( 'base', 'auto_update_patch_immediately' ),
            'timezone'  => (string) ( CoreAPI::getSetting( 'base', 'timezone' ) ?: 'UTC' ),
            'url'       => (string) CoreAPI::getSetting( 'base', 'auto_update_releases_url' ),
            'releases'  => null,
            'env'       => null,
            // The services: the bell feed, a notice, when one was first
            // raised, and a module's stored schema version.
            'feed'      => static function ( array $list ) {

                return \OWA\Module\Base\Classes\NotificationManager::record(
                    \OWA\Module\Base\Classes\NotificationManager::fromGithubReleases( $list ),
                    \OWA\Module\Base\Classes\NotificationManager::SOURCE_GITHUB, '',
                    \OWA\Module\Base\Classes\NotificationManager::TYPE_RELEASE );
            },
            'notify'    => static fn ( array $notice ) => \OWA\Module\Base\Classes\NotificationManager::notify( $notice ),
            'raised_at' => static fn ( $key ) => \OWA\Module\Base\Classes\NotificationManager::raisedAt( self::SOURCE, $key ),
            'schema'    => static function ( $module ) {

                $v = CoreAPI::getSetting( $module, 'schema_version' );

                return $v === null || $v === '' || $v === false ? null : (int) $v;
            },
            'now'       => time(),
        ];
        $this->root = (string) $this->context['root'];
    }

    /**
     * @param  bool $dry_run choose only: nothing downloaded, written or announced
     * @return Outcome
     */
    public function run( $dry_run = false ) {

        $installed = (string) $this->context['installed'];
        $list      = null;
        $error     = '';

        try {

            $list = $this->context['releases'] ?? Releases::fetch( (string) $this->context['url'] );

        } catch ( \RuntimeException $e ) {

            $error = $e->getMessage();
        }

        if ( $list !== null && ! $dry_run ) {

            $created = (int) ( $this->context['feed'] )( $list );
            $this->lines[] = sprintf( '%d new release notification%s.', $created, $created === 1 ? '' : 's' );
        }

        if ( $this->context['role'] !== \OWA\Core\ServerRole::FULL ) {

            return $this->end( self::ROLE, null, sprintf( 'This server\'s role is %s; automatic updates run on a full server only.', $this->context['role'] ), $dry_run );
        }

        $checkout = Vcs::checkout( $this->root );

        if ( $checkout !== null ) {

            return $this->end( self::CHECKOUT, null, sprintf( 'OWA runs from a version control checkout (%s): it is updated with that, never automatically.', $checkout ), $dry_run );
        }

        if ( Version::parse( $installed ) === null ) {

            return $this->end( self::UNKNOWN, null, sprintf( 'This installation\'s version, %s, is not a release version.', $installed ), $dry_run );
        }

        // An install that failed and was restored left only the old release
        // to say so; the notices are raised once each (InstallNotices).
        if ( ! $dry_run ) {

            $install = $this->staging->install();

            if ( $install ) {

                InstallNotices::forInstall( $install, $this->context['notify'] );
            }
        }

        if ( ! $this->context['enabled'] ) {

            return $this->end( self::OFF, null, 'Automatic updates are off.', $dry_run );
        }

        if ( $list === null ) {

            return $this->repair( self::UNREACHED, $error, $dry_run );
        }

        $level   = $this->context['level'] === Version::PATCH ? Version::PATCH : Version::MINOR;
        $release = Releases::choose( $list, $installed, $level );

        // Nothing the settings choose now: a staged.json naming what they chose
        // before (a minor, now patch only; a release since installed) goes.
        if ( $release === null ) {

            return $this->end( self::CURRENT, null, sprintf( 'No release newer than %s for automatic %s updates.', $installed,
                $level === Version::PATCH ? 'patch' : 'patch and minor' ), $dry_run );
        }

        $v = $release['version'];
        $this->lines[] = sprintf( 'The release to update to is %s (a %s release): %s', $v, $release['step'], $release['url'] );

        if ( $dry_run ) {

            return $this->outcome( self::CHOSEN, $v );
        }

        if ( $this->staging->hasInstall( $v ) ) {

            return $this->outcome( self::CLAIMED, $v, [], sprintf( '%s is being installed; its staging is the installer\'s.', $v ) );
        }

        $before = $this->staging->staged();

        if ( ( $held = $this->heldUntil( $before ) ) !== null ) {

            return $this->held( $before, $held );
        }

        // Downloaded when the slot does not hold it, and again when the asset
        // GitHub lists now is not the one staged (it was replaced: the slot is
        // emptied first, holding the lock, so nothing installs it meanwhile).
        // A download replaces whatever the slot held, an unfinished install of
        // another release with it (Staging::swapIn()).
        $replaced = $this->staging->isStaged( $v ) && $release['sha256'] !== null
            && ( $before['sha256'] ?? null ) !== $release['sha256'];
        $sha      = (string) ( $before['sha256'] ?? '' );

        if ( $replaced || ! $this->staging->isStaged( $v ) ) {

            if ( $replaced && ! InstallLock::tryExclusive( fn () => $this->staging->clear() ) ) {

                return $this->busy();
            }

            try {

                $sha = $this->staging->download( $release );

            } catch ( \RuntimeException $e ) {

                $this->stagingFailed( $v, $e->getMessage(), $release['url'] );

                return $this->outcome( self::FAILED, $v, [ $e->getMessage() ], sprintf( 'Staging %s failed; the next run tries again.', $v ) );
            }

            $old = (string) ( $this->staging->releaseInfo()['version'] ?? '' );

            if ( ! InstallLock::tryExclusive( fn () => $this->staging->swapIn() ) ) {

                return $this->busy();
            }

            $this->lines[] = sprintf( 'Downloaded %s and checked its SHA-256 and signature%s.', $v, $replaced ? ', as the release\'s asset was replaced'
                : ( $old !== '' && $old !== $v ? sprintf( '; it replaces %s', $old ) : '' ) );
            $before = null;
        }

        $problems = $this->preflight( $v );
        $manual   = $problems ? [] : $this->manualInstall( $v );
        $auto     = ! $problems && ! $manual;
        // "Install now" from the Updates screen stands for its release: no window.
        $now      = $auto && $before !== null && ( $before['version'] ?? null ) === $v && ! empty( $before['install_now'] );
        $window   = $auto && ! $now ? $this->window( $release['step'] ) : null;
        $same     = $before !== null && ( $before['version'] ?? null ) === $v && ! empty( $before['automatic'] ) === $auto
            && empty( $before['by_hand'] ) && ( $before['window'] ?? null ) == $window && ! empty( $before['install_now'] ) === $now;

        // staged.json describes the slot: the release, whether it installs on
        // its own, and the window the settings give now.
        if ( ! $same && ! InstallLock::tryExclusive( fn () => $this->staging->writeStaged( $v, $window, $sha, $auto, false, (int) $this->context['now'], $now ) ) ) {

            return $this->busy();
        }

        if ( $problems ) {

            $this->notify( sprintf( 'unmet:%s:%s', $v, substr( md5( implode( "\n", $problems ) ), 0, 12 ) ), 'warning',
                sprintf( CoreAPI::t( 'OWA %s cannot be installed automatically yet' ), $v ),
                implode( "\n", $problems ) . "\n\n" . CoreAPI::t( 'It is checked again every six hours, and installs once these are fixed.' ),
                $release['url'] );

            return $this->outcome( self::UNMET, $v, $problems, sprintf( '%s cannot be installed here yet; it is judged again at the next run.', $v ) );
        }

        if ( $manual ) {

            $texts = array_map( static fn ( $u ) => sprintf( '%s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] ), $manual );

            $this->notify( 'manual:' . $v, 'warning',
                sprintf( CoreAPI::t( 'OWA %s needs to be installed by hand' ), $v ),
                implode( "\n", $texts ) . "\n\n" . CoreAPI::t( 'It is downloaded and checked. Take the backup each update names, then install it: php cli.php cmd=core-update-install' ),
                $release['url'] );

            return $this->outcome( self::MANUAL, $v, $texts, sprintf( '%s has updates that must be installed by hand; it is staged but will not install itself.', $v ) );
        }

        if ( $same ) {

            return $this->outcome( self::STAGED, $v, [], sprintf( '%s is staged, as it was.', $v ) );
        }

        $when = self::describeWindow( $window );

        if ( $before !== null && ( $before['version'] ?? null ) === $v && ! empty( $before['automatic'] ) ) {

            $this->lines[] = sprintf( 'The window for %s is now %s.', $v, $when );

            return $this->outcome( self::STAGED, $v, [], sprintf( '%s is staged to install %s.', $v, $when ) );
        }

        $this->notify( 'staged:' . $v, 'info',
            sprintf( CoreAPI::t( 'OWA %1$s will be installed %2$s' ), $v, $when ),
            sprintf( CoreAPI::t( 'Automatic updates downloaded and checked OWA %1$s, a %2$s release. To stop it: php cli.php cmd=core-update-unstage' ), $v, $release['step'] ),
            $release['url'] );

        return $this->outcome( self::STAGED, $v, [], sprintf( '%s is staged to install %s.', $v, $when ) );
    }

    /**
     * When a release staged by hand stops being left alone: HAND_HOLD after
     * it was staged, by this server's clock (a time in the future counts as
     * now); null when what is staged was not staged by hand, or its hold is over.
     *
     * @param  array<string, mixed>|null $staged
     * @return int|null
     */
    private function heldUntil( $staged ) {

        if ( empty( $staged['by_hand'] ) ) {

            return null;
        }

        $now   = (int) $this->context['now'];
        $until = min( (int) ( $staged['staged_at'] ?? 0 ), $now ) + self::HAND_HOLD;

        return $until > $now ? $until : null;
    }

    /**
     * @param  array<string, mixed> $staged
     * @param  int                  $until
     * @return Outcome
     */
    private function held( array $staged, $until ) {

        return $this->outcome( self::HELD, (string) $staged['version'], [], sprintf( '%s was staged by hand and is held until %s UTC; this run changes nothing.',
            $staged['version'], gmdate( 'Y-m-d H:i', $until ) ) );
    }

    /**
     * cmd=core-update-stage: stage one named release by hand, in place of the
     * staged one, as Step 1 would, and judge it. staged.json says by hand, so it
     * installs only when an operator installs it (cmd=core-update-install), and
     * the release check leaves it alone for HAND_HOLD. Any version in this
     * major the listing has, older and the installed one included; a major
     * release is a migration, done by hand. Nothing is announced: the operator
     * is reading the output.
     *
     * For an older release, the modules whose stored schema is newer than it
     * expects are listed with the rollback commands to run first, newest first:
     * older code cannot run a newer schema.
     *
     * @param  string $version
     * @return Outcome
     */
    public function stageByHand( $version ) {

        $installed = (string) $this->context['installed'];
        $checkout  = Vcs::checkout( $this->root );

        if ( $checkout !== null ) {

            return $this->outcome( self::CHECKOUT, null, [], sprintf( 'OWA runs from a version control checkout (%s): update it with that.', $checkout ) );
        }

        if ( Version::parse( $installed ) === null || Version::parse( (string) $version ) === null ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( 'Both versions must be release versions: installed %s, asked for %s.', $installed, $version ) );
        }

        if ( Version::step( $installed, $version ) === Version::MAJOR || Version::step( $version, $installed ) === Version::MAJOR ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( '%s is another major version: that is a migration, done by hand from its release notes.', $version ) );
        }

        try {

            $list = $this->context['releases'] ?? Releases::fetch( (string) $this->context['url'] );

        } catch ( \RuntimeException $e ) {

            return $this->outcome( self::UNREACHED, null, [ $e->getMessage() ], 'The releases could not be listed.' );
        }

        $release = Releases::find( $list, $version, $installed );

        if ( $release === null ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( 'There is no published release %s with its tarball.', $version ) );
        }

        $v = $release['version'];

        if ( InstallLock::isHeld() ) {

            return $this->busy();
        }

        $before = $this->staging->staged();
        $sha    = (string) ( $before['sha256'] ?? '' );

        if ( $this->staging->isStaged( $v ) && $release['sha256'] !== null && $sha === $release['sha256'] ) {

            $this->lines[] = sprintf( '%s was already downloaded and checked.', $v );

        } else {

            try {

                $sha = $this->staging->download( $release );

            } catch ( \Throwable $e ) {

                return $this->outcome( self::FAILED, $v, [ $e->getMessage() ], sprintf( 'Staging %s failed.', $v ) );
            }

            $old     = (string) ( $this->staging->releaseInfo()['version'] ?? '' );
            $dropped = (string) ( $this->staging->install()['version'] ?? '' );

            if ( ! InstallLock::tryExclusive( fn () => $this->staging->swapIn() ) ) {

                return $this->busy();
            }

            $this->lines[] = sprintf( 'Downloaded %s and checked its SHA-256 and signature%s.', $v,
                $old !== '' && $old !== $v ? sprintf( '; it replaces %s', $old ) : '' );

            if ( $dropped !== '' ) {

                $this->lines[] = sprintf( 'The unfinished install of %s was dropped: this staging replaces it.', $dropped );
            }
        }

        // Staged by hand: it installs only by cmd=core-update-install, and the
        // release check leaves it alone for HAND_HOLD.
        if ( ! InstallLock::tryExclusive( fn () => $this->staging->writeStaged( $v, null, $sha, false, true, (int) $this->context['now'] ) ) ) {

            return $this->busy();
        }

        $problems = $this->preflight( $v );
        $manual   = $this->manualInstall( $v );
        $rollback = $this->rollbackFirst( $v );

        $step = Version::step( $installed, $v );
        $this->lines[] = $step === null
            ? sprintf( '%s is not newer than the installed %s: installing it goes back.', $v, $installed )
            : sprintf( '%s is a %s release after the installed %s.', $v, $step, $installed );

        foreach ( $manual as $u ) {

            $this->lines[] = sprintf( 'Read before installing -- %s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] );
        }

        if ( $rollback ) {

            return $this->outcome( self::UNMET, $v, $rollback, sprintf( 'This database is newer than %s expects. Undo these updates first, newest first, with the code installed now:', $v ) );
        }

        if ( $problems ) {

            return $this->outcome( self::UNMET, $v, $problems, sprintf( '%s cannot be installed here:', $v ) );
        }

        $until = (int) $this->heldUntil( $this->staging->staged() );

        return $this->outcome( self::STAGED, $v, [], sprintf( '%s is staged. Install it before %s UTC, when the release check may replace it: php cli.php cmd=core-update-install%s',
            $v, gmdate( 'Y-m-d H:i', $until ?: (int) $this->context['now'] + self::HAND_HOLD ), $step === null ? ' --force' : '' ) );
    }

    /**
     * cmd=core-update-unstage [version=]: empty the slot, so nothing installs
     * until a release is staged again (the next release check, unless
     * automatic updates are off). With a version, only if it is the one
     * staged. Refused while an install runs or is unfinished: its live tree
     * may be half copied, and only carrying it on, or its own undo, settles it.
     *
     * @param  string|null $version
     * @return Outcome
     */
    public function cancel( $version = null ) {

        $staged = $this->staging->staged();
        $v      = (string) ( $staged['version'] ?? ( $this->staging->releaseInfo()['version'] ?? '' ) );

        if ( $v === '' || ( $version !== null && $version !== $v ) ) {

            return $this->outcome( self::UNKNOWN, null, [], $version === null ? 'Nothing is staged.' : sprintf( 'Nothing of %s is staged.', $version ) );
        }

        if ( $this->staging->install() ) {

            $u = (string) $this->staging->install()['version'];

            return $this->outcome( self::CLAIMED, $u, [], sprintf( 'An install of %1$s is unfinished; it cannot be cancelled. Carry it on with: php cli.php cmd=core-update-install', $u ) );
        }

        if ( ! InstallLock::tryExclusive( fn () => $this->staging->clear() ) ) {

            return $this->busy();
        }

        return $this->outcome( self::OFF, $v, [], sprintf( 'Cancelled %s: nothing is staged.%s', $v,
            $this->context['enabled'] && empty( $staged['by_hand'] ) ? ' The next release check stages it again; set OWA_AUTO_UPDATE to false to stop that.' : '' ) );
    }

    /**
     * cmd=core-update-ignore-window, and the Updates screen's "Install now": the staged
     * release installs at the next scheduler run, whatever its window.
     * staged.json says install_now, which the release check keeps for that
     * release. Only one that installs itself: one staged by hand is installed
     * with cmd=core-update-install, and one that waits says why.
     *
     * @return Outcome
     */
    public function installNow() {

        $staged = $this->staging->staged();

        if ( $staged === null ) {

            return $this->outcome( self::UNKNOWN, null, [], 'Nothing is staged.' );
        }

        $v = (string) $staged['version'];

        if ( ! empty( $staged['by_hand'] ) ) {

            return $this->outcome( self::HELD, $v, [], sprintf( '%1$s was staged by hand: install it with php cli.php cmd=core-update-install', $v ) );
        }

        if ( empty( $staged['automatic'] ) ) {

            return $this->outcome( self::UNMET, $v, [], sprintf( '%s does not install itself; php cli.php cmd=core-update-status says why.', $v ) );
        }

        if ( ! empty( $staged['install_now'] ) || ( $staged['window'] ?? null ) === null ) {

            return $this->outcome( self::STAGED, $v, [], sprintf( '%s installs at the next scheduler run.', $v ) );
        }

        $done = InstallLock::tryExclusive( fn () => $this->staging->writeStaged( $v, null, (string) ( $staged['sha256'] ?? '' ), true, false,
            (int) ( $staged['staged_at'] ?? $this->context['now'] ), true ) );

        return $done
            ? $this->outcome( self::STAGED, $v, [], sprintf( '%s installs at the next scheduler run, within a minute.', $v ) )
            : $this->busy();
    }

    /**
     * cmd=core-update-status: what automatic updates will do, and the staged release.
     *
     * @return list<string>
     */
    public function status() {

        $c     = $this->context;
        $lines = [ sprintf( 'Installed: %s', $c['installed'] ) ];
        $vcs   = Vcs::checkout( $this->root );

        if ( $vcs !== null ) {

            $lines[] = sprintf( 'Automatic updates: never, OWA runs from a version control checkout (%s).', $vcs );

        } elseif ( ! $c['enabled'] ) {

            $lines[] = 'Automatic updates: off (OWA_AUTO_UPDATE).';

        } else {

            $lines[] = sprintf( 'Automatic updates: %s releases, starting %s; patches %s.',
                $c['level'] === Version::PATCH ? 'patch' : 'patch and minor',
                self::describeWindow( $this->window( Version::MINOR ) ),
                $c['immediate'] ? 'install as soon as they are staged' : 'wait for the same window' );
        }

        $staged = $this->staging->staged();

        if ( $staged === null ) {

            $lines[] = 'Staged: nothing.';

        } else {

            $v       = (string) $staged['version'];
            $held    = $this->heldUntil( $staged );
            $lines[] = sprintf( 'Staged: %s, %s.', $v, ! empty( $staged['by_hand'] )
                ? 'by hand; it installs only by cmd=core-update-install' . ( $held !== null ? ', held until ' . gmdate( 'Y-m-d H:i', $held ) . ' UTC' : '' )
                : ( ! empty( $staged['automatic'] ) ? 'installing ' . self::describeWindow( $staged['window'] ?? null ) : 'waiting (see below); it does not install itself' ) );

            if ( ! $this->staging->isStaged( $v ) ) {

                $lines[] = '    Its download is missing: the next release check downloads it again.';

            } else {

                $problems = $this->preflight( $v );
                $lines[]  = sprintf( '    Downloaded %s UTC; %s.', gmdate( 'Y-m-d H:i', (int) filemtime( $this->staging->releaseDir() ) ),
                    $problems ? 'cannot be installed yet' : 'can be installed' );

                foreach ( $problems as $p ) {

                    $lines[] = '    ' . $p;
                }

                foreach ( $problems ? [] : $this->manualInstall( $v ) as $u ) {

                    $lines[] = sprintf( '    Needs installing by hand -- %s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] );
                }
            }
        }

        if ( $this->staging->count() > 1 ) {

            $lines[] = 'There is more than one updates-* directory in the data directory: nothing installs until all but one are removed.';
        }

        $install = $this->staging->install();

        if ( $install ) {

            $lines[] = 'Unfinished install: ' . json_encode( $install, JSON_UNESCAPED_SLASHES );
        }

        if ( InstallLock::isHeld() ) {

            $lines[] = 'An install is running now.';
        }

        return $lines;
    }

    /**
     * For an older release: the cmd=core-update-schema rollback= commands that bring each
     * module's stored schema down to the one it expects, newest first.
     *
     * @param  string $v
     * @return list<string>
     */
    private function rollbackFirst( $v ) {

        $out = [];

        foreach ( (array) ( $this->staging->releaseInfo()['modules'] ?? [] ) as $module => $expects ) {

            $stored = ( $this->context['schema'] )( (string) $module );

            if ( $expects === null || $stored === null ) {

                continue;
            }

            for ( $seq = $stored; $seq > (int) $expects; $seq-- ) {

                $out[] = sprintf( 'php cli.php cmd=core-update-schema rollback=%s.%03d', $module, $seq );
            }
        }

        return $out;
    }

    /**
     * The updates this installation would run on the way to $v that must be
     * installed by hand: for each module it has, those above its stored
     * schema, up to the release's.
     *
     * @param  string $v
     * @return list<array{module: string, sequence: int, manual_install: string}>
     */
    private function manualInstall( $v ) {

        $release = (array) $this->staging->releaseInfo();
        $modules = (array) ( $release['modules'] ?? [] );
        $out     = [];

        foreach ( (array) ( $release['updates'] ?? [] ) as $u ) {

            $module = (string) ( $u['module'] ?? '' );
            $seq    = (int) ( $u['sequence'] ?? 0 );
            $text   = trim( (string) ( $u['manual_install'] ?? '' ) );
            $stored = $text !== '' ? ( $this->context['schema'] )( $module ) : null;

            if ( $stored !== null && $seq > $stored && ( ! isset( $modules[ $module ] ) || $seq <= (int) $modules[ $module ] ) ) {

                $out[] = [ 'module' => $module, 'sequence' => $seq, 'manual_install' => $text ];
            }
        }

        return $out;
    }

    /**
     * A staging that failed: a notice in the bell the first time, and an
     * e-mail once it has kept failing for FAILING_FOR.
     *
     * @param  string $v
     * @param  string $why
     * @param  string $url
     * @return void
     */
    private function stagingFailed( $v, $why, $url ) {

        $first = ( $this->context['raised_at'] )( 'staging_failing:' . $v );

        if ( $first === null ) {

            $this->notify( 'staging_failing:' . $v, 'info',
                sprintf( CoreAPI::t( 'OWA %s could not be downloaded' ), $v ),
                $why . "\n\n" . CoreAPI::t( 'Automatic updates try again every six hours.' ), $url, false );

        } elseif ( (int) $this->context['now'] - $first >= self::FAILING_FOR ) {

            $this->notify( 'staging_failed:' . $v, 'warning',
                sprintf( CoreAPI::t( 'OWA %s has not downloaded for a week' ), $v ),
                $why . "\n\n" . CoreAPI::t( 'Automatic updates keep trying every six hours.' ), $url );
        }
    }

    /**
     * @param  string $key
     * @param  string $severity
     * @param  string $title
     * @param  string $body
     * @param  string $url
     * @param  bool   $email
     * @return void
     */
    private function notify( $key, $severity, $title, $body, $url, $email = true ) {

        ( $this->context['notify'] )( [
            'source'     => self::SOURCE,
            'source_key' => $key,
            'title'      => $title,
            'body'       => $body,
            'url'        => $url,
            'severity'   => $severity,
            'email'      => $email,
        ] );
    }

    /**
     * @param  string $v
     * @return list<string>
     */
    private function preflight( $v ) {

        $release  = (array) $this->staging->releaseInfo();
        $req      = (array) ( $release['requirements'] ?? [] );
        $env      = $this->context['env'] ?? Preflight::environment( $req );

        return array_merge( Preflight::requirements( $req, $env ), Preflight::install( $this->staging->releaseDir(), $this->staging->releaseDir(), $this->root ),
            $this->handoffProblems( $v ) );
    }

    /**
     * What would stop Step 2: hand-off running the release: the installed
     * release's updater.php (it runs before OWA loads) and the release's own.
     *
     * @param  string $v
     * @return list<string>
     */
    private function handoffProblems( $v ) {

        $problems = [];

        if ( ! is_file( $this->staging->releaseDir() . '/updater.php' ) ) {

            $problems[] = CoreAPI::t( 'The release has no updater.php, so it cannot install itself. Install it by hand.' );
        }

        if ( ! is_file( $this->root . '/updater.php' ) ) {

            $problems[] = CoreAPI::t( 'This installation has no updater.php, so it cannot install a release itself. Install it by hand.' );

        } elseif ( ( $why = Staging::updater()['configRefused']( $this->root ) ) !== null ) {

            $problems[] = sprintf( CoreAPI::t( 'owa-config.php uses %s. It is read before OWA loads, so this installation cannot install a release itself. Use plain values there, or install by hand.' ), $why );
        }

        return $problems;
    }

    /**
     * The window a release may start in, from the settings; null when it may
     * start at once (a patch while patches install immediately, or no window).
     *
     * @param  string $step
     * @return array{days: list<int>, start: string, end: string, timezone: string}|null
     */
    public function window( $step ) {

        $window = (string) ( $this->context['window'] ?: '' );

        if ( ( $step === Version::PATCH && $this->context['immediate'] )
                || ! preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])$/', $window ) ) {

            return null;
        }

        [ $start, $end ] = explode( '-', $window );
        $days = [];

        foreach ( preg_split( '/\s*,\s*/', strtolower( trim( (string) $this->context['days'] ) ) ) ?: [] as $day ) {

            if ( isset( self::DAYS[ $day ] ) ) {

                $days[] = self::DAYS[ $day ];
            }
        }

        $days = $days ? array_values( array_unique( $days ) ) : array_values( self::DAYS );
        sort( $days );

        return [ 'days' => $days, 'start' => $start, 'end' => $end, 'timezone' => (string) $this->context['timezone'] ];
    }

    /**
     * @param  array<string, mixed>|null $window
     * @return string
     */
    public static function describeWindow( $window ) {

        if ( ! $window ) {

            return CoreAPI::t( 'at the next scheduler run' );
        }

        $names = array_flip( self::DAYS );
        $days  = count( (array) $window['days'] ) === 7 ? CoreAPI::t( 'any day' )
            : implode( ', ', array_map( fn ( $d ) => ucfirst( $names[ (int) $d ] ), (array) $window['days'] ) );

        return sprintf( CoreAPI::t( 'between %1$s and %2$s (%3$s), %4$s' ), $window['start'], $window['end'], $window['timezone'], $days );
    }

    /**
     * When GitHub cannot be reached, so nothing can be chosen: keep
     * staged.json while the release it names is staged.
     *
     * @param  string $outcome
     * @param  string $why
     * @param  bool   $dry_run
     * @return Outcome
     */
    private function repair( $outcome, $why, $dry_run ) {

        $staged = $this->staging->staged();
        $v      = $staged['version'] ?? null;

        if ( ! $dry_run && $v !== null && ! $this->staging->isStaged( (string) $v ) && ! $this->staging->install() ) {

            if ( ! InstallLock::tryExclusive( fn () => $this->staging->clear() ) ) {

                return $this->busy();
            }

            $this->lines[] = sprintf( 'Removed staged.json: it named %s, which staged/ does not hold.', $v );
            $v = null;
        }

        return $this->outcome( $outcome, $v, $outcome === self::UNREACHED ? [ $why ] : [], $why );
    }

    /**
     * An outcome in which the settings choose nothing to install: the slot is
     * emptied, staged.json and staged/ in one step holding the lock, so it
     * never installs against the settings and nothing is kept that nothing
     * will install. Not while an unfinished install uses it, nor while a
     * release staged by hand is held.
     *
     * @param  string      $outcome
     * @param  string|null $v
     * @param  string      $why
     * @param  bool        $dry_run
     * @return Outcome
     */
    private function end( $outcome, $v, $why, $dry_run ) {

        $staged = $this->staging->staged();
        $named  = (string) ( $staged['version'] ?? ( $this->staging->releaseInfo()['version'] ?? '' ) );

        if ( $dry_run || $named === '' || $this->staging->install() ) {

            return $this->outcome( $outcome, $v, [], $why );
        }

        if ( ( $held = $this->heldUntil( $staged ) ) !== null ) {

            return $this->held( (array) $staged, $held );
        }

        if ( ! InstallLock::tryExclusive( fn () => $this->staging->clear() ) ) {

            return $this->busy();
        }

        $this->lines[] = sprintf( 'Deleted the staging of %s: nothing will install it now.', $named );

        return $this->outcome( $outcome, $v, [], $why );
    }

    /** @return Outcome */
    private function busy() {

        return $this->outcome( self::BUSY, null, [], 'An install is running; this run changed nothing.' );
    }

    /**
     * @param  string       $outcome
     * @param  string|null  $v
     * @param  list<string> $problems
     * @param  string|null  $line
     * @return Outcome
     */
    private function outcome( $outcome, $v, array $problems = [], $line = null ) {

        if ( $line !== null ) {

            $this->lines[] = $line;
        }

        foreach ( $problems as $p ) {

            $this->lines[] = '  ' . $p;
        }

        return [ 'outcome' => $outcome, 'version' => $v, 'lines' => $this->lines, 'problems' => $problems ];
    }
}
