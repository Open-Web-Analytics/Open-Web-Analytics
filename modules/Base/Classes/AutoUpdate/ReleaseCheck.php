<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * Automatic updates: the release check, which installs what it finds.
 *
 * The scheduler runs it every hour (job core-update). Four of those
 * runs a day, six hours apart at hours this installation keeps
 * (listingHour()), list the releases, each after waiting a number of
 * seconds it also keeps (listingDelay()): installations spread over 21,600
 * slots, never all asking the update host and GitHub at once. Every listing
 * feeds the notifications bell first, whatever follows. Run by hand it
 * always lists, at once. Then, stopping at the first that applies:
 *   1. Not a full server role, a version control checkout (the bell has
 *      said what is new), an unreadable installed version, automatic updates
 *      off, or an install under way.
 *   2. With a listing: the release the settings choose. Its tarball is
 *      downloaded through the update host (auto_update_download_url, never
 *      the listing's GitHub address) and checked against its SHA-256 and
 *      its signature, again
 *      if GitHub's digest for it changes (the asset was replaced), and the
 *      installed release's too, once: a failed install puts it back. Every
 *      other tarball is deleted. A failed download puts a notice in the
 *      bell; a week of them e-mails the admins. A release whose install
 *      failed (last-failure.json) is tried again 6 hours on, 3 attempts in
 *      all, and one that did not boot not at all; then it is skipped: it
 *      installs by hand, or a newer one does.
 *   3. The newest downloaded release the settings allow is judged, from its
 *      tarball (judge()). Unmet, or an update in it that must be installed
 *      by hand ($manual_install): the admins are told once, and it waits.
 *   4. Outside the update window it waits. Inside it, or with no window,
 *      it installs (Install): the files are copied when this run of the
 *      scheduler has finished, and the next run finishes the install.
 *
 * Without a listing (GitHub unreachable, or not a listing hour) only 3 and 4
 * apply, to what was downloaded before.
 *
 * @phpstan-import-type Release from Releases
 * @phpstan-type Outcome array{outcome: string, version: string|null, lines: list<string>, problems: list<string>}
 */
class ReleaseCheck {

    const ROLE       = 'not_full_role';
    const CHECKOUT   = 'vcs_checkout';
    const OFF        = 'auto_update_off';
    const UNKNOWN    = 'unknown_version';
    const UNREACHED  = 'listing_failed';
    const CURRENT    = 'up_to_date';
    const FAILED     = 'download_failed';
    const UNMET      = 'requirements_unmet';
    const MANUAL     = 'manual_install';
    const BUSY       = 'install_running';
    const CHOSEN     = 'chosen';
    const SKIPPED    = 'failed_before';
    const WAITING    = 'waiting_for_window';
    const INSTALLING = 'installing';
    const NO_TEMP    = 'temp_dir_unwritable';

    /** The notices' source (NotificationManager). */
    const SOURCE = 'auto_update';

    /** How long a download may keep failing before the admins are e-mailed. */
    const FAILING_FOR = 604800;

    /** Hours between listings in the scheduler's runs. */
    const LISTING_EVERY = 6;

    const DAYS = [ 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 ];

    /** @var Downloads */
    private $downloads;

    /** @var string */
    private $root;

    /** @var list<string> */
    private $lines = [];

    /** @var array<string, mixed> settings, facts and services a test replaces */
    public $context;

    /**
     * @param Downloads|null       $downloads
     * @param array<string, mixed> $context in place of what is read from the installation, for a test
     */
    public function __construct( $downloads = null, array $context = [] ) {

        $this->downloads = $downloads ?? new Downloads();
        $this->context   = $context + [
            'root'      => \OWA\Core\Env::root(),
            'installed' => \OWA\Core\Env::version(),
            'role'      => \OWA\Core\ServerRole::current()->name(),
            'enabled'   => (bool) CoreAPI::getSetting( 'base', 'auto_update' ),
            'level'     => (string) CoreAPI::getSetting( 'base', 'auto_update_level' ),
            'days'      => (string) CoreAPI::getSetting( 'base', 'auto_update_days' ),
            'window'    => CoreAPI::getSetting( 'base', 'auto_update_window' ),
            'timezone'  => (string) ( CoreAPI::getSetting( 'base', 'timezone' ) ?: 'UTC' ),
            'url'       => (string) CoreAPI::getSetting( 'base', 'auto_update_releases_url' ),
            'download'  => (string) CoreAPI::getSetting( 'base', 'auto_update_download_url' ),
            // The scheduler's run: it lists only in its listing hours, and says nothing after the copy.
            'scheduled' => false,
            // What fixes this installation's listing hour and second: its auth
            // secret, which differs per installation however it was cloned,
            // and is only hashed here, never sent.
            'seed'      => hash( 'sha256', 'owa-release-listing|' . ( defined( 'OWA_AUTH_KEY' ) ? (string) constant( 'OWA_AUTH_KEY' ) : '' )
                . '|' . (string) CoreAPI::getSetting( 'base', 'public_url' ) . '|' . \OWA\Core\Env::root() ),
            'sleep'     => static fn ( $seconds ) => sleep( (int) $seconds ),
            'releases'  => null,
            'env'       => null,
            // Preflight::temp()'s problems, for a test; null tries PHP's own.
            'temp'      => null,
            // The services: the bell feed, a notice, when one was first
            // raised, and a module's stored schema version.
            'feed'      => static function ( array $list ) {

                return \OWA\Module\Base\Classes\NotificationManager::record(
                    \OWA\Module\Base\Classes\NotificationManager::fromGithubReleases( $list ),
                    \OWA\Module\Base\Classes\NotificationManager::SOURCE_GITHUB, '',
                    \OWA\Module\Base\Classes\NotificationManager::TYPE_RELEASE );
            },
            'notify'    => [ InstallNotices::class, 'send' ],
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
     * @param  bool $dry_run choose only: nothing downloaded, written, announced or installed
     * @return Outcome
     */
    public function run( $dry_run = false ) {

        $installed = (string) $this->context['installed'];
        $listed    = $dry_run || ! $this->context['scheduled'] || $this->listingHour();
        $list      = null;
        $error     = '';

        if ( $listed ) {

            if ( $this->context['scheduled'] && ! $dry_run ) {

                ( $this->context['sleep'] )( $this->listingDelay() );
            }

            try {

                $list = $this->context['releases'] ?? Releases::fetch( Releases::listingUrl( (string) $this->context['url'], $installed ) );

            } catch ( \RuntimeException $e ) {

                $error = $e->getMessage();
            }
        }

        if ( $list !== null && ! $dry_run ) {

            $created = (int) ( $this->context['feed'] )( $list );
            $this->lines[] = sprintf( '%d new release notification%s.', $created, $created === 1 ? '' : 's' );
        }

        if ( $this->context['role'] !== \OWA\Core\ServerRole::FULL ) {

            return $this->outcome( self::ROLE, null, [], sprintf( 'This server\'s role is %s; automatic updates run on a full server only.', $this->context['role'] ) );
        }

        $checkout = Vcs::checkout( $this->root );

        if ( $checkout !== null ) {

            return $this->outcome( self::CHECKOUT, null, [], sprintf( 'OWA runs from a version control checkout (%s): it is updated with that, never automatically.', $checkout ) );
        }

        if ( Version::parse( $installed ) === null ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( 'This installation\'s version, %s, is not a release version.', $installed ) );
        }

        $failure = $this->downloads->failure();

        if ( $failure && ! $dry_run ) {

            InstallNotices::forFailure( $failure, $this->context['notify'] );
        }

        if ( ! $this->context['enabled'] ) {

            if ( ! $dry_run ) {

                $this->downloads->prune( [ $installed ] );
            }

            return $this->outcome( self::OFF, null, [], 'Automatic updates are off.' );
        }

        if ( ! $dry_run && $this->installing() ) {

            return $this->outcome( self::BUSY, null, [], 'An install is under way; this run changed nothing.' );
        }

        // Every run, not only when a release is due: the job fails, so System
        // Health says so before a security release needs it.
        $temp = $this->context['temp'] ?? Preflight::temp( $this->downloads->tarballBytes( $installed, $installed ) );

        if ( $temp ) {

            return $this->outcome( self::NO_TEMP, null, $temp, 'No release can be installed:' );
        }

        $level   = $this->context['level'] === Version::PATCH ? Version::PATCH : Version::MINOR;
        $missing = null;

        if ( $listed && $list === null ) {

            $this->lines[] = sprintf( 'The releases could not be listed: %s', $error );

        } elseif ( $list !== null ) {

            $release = Releases::choose( $list, $installed, $level );

            if ( $release === null ) {

                if ( ! $dry_run ) {

                    $this->downloads->prune( [ $installed ] );
                }

                return $this->outcome( self::CURRENT, null, [], sprintf( 'No release newer than %s for automatic %s updates.', $installed,
                    $level === Version::PATCH ? 'patch' : 'patch and minor' ) );
            }

            $v = $release['version'];
            $this->lines[] = sprintf( 'The release to update to is %s (a %s release): %s', $v, $release['step'], $release['url'] );

            if ( $dry_run ) {

                return $this->outcome( self::CHOSEN, $v );
            }

            if ( $this->skips( $v ) ) {

                $retry = (int) ( $this->downloads->failure()['retry_after'] ?? 0 );

                return $this->outcome( self::SKIPPED, $v, [], $retry > 0
                    ? sprintf( 'Installing %s failed; it is tried again after %s UTC. To try it now: php cli.php cmd=core-install --version=%s', $v, gmdate( 'Y-m-d H:i', $retry ), $v )
                    : sprintf( 'Installing %s failed before, so it is skipped until a newer release; to try it again: php cli.php cmd=core-install --version=%s', $v, $v ) );
            }

            // Downloaded when it has not been, and again when the asset GitHub
            // lists now is not the one downloaded (it was replaced).
            $replaced = $this->downloads->has( $v ) && $release['sha256'] !== null
                && ! hash_equals( (string) $release['sha256'], (string) hash_file( 'sha256', $this->downloads->tarball( $v ) ) );

            if ( $replaced || ! $this->downloads->has( $v ) ) {

                try {

                    $this->downloads->download( Releases::via( $release, (string) $this->context['download'] ) );

                } catch ( \RuntimeException $e ) {

                    $this->downloadFailed( $v, $e->getMessage(), $release['url'] );

                    return $this->outcome( self::FAILED, $v, [ $e->getMessage() ], sprintf( 'Downloading %s failed; the next listing tries again.', $v ) );
                }

                $this->lines[] = sprintf( 'Downloaded %s and checked its SHA-256 and signature%s.', $v, $replaced ? ', as the release\'s asset was replaced' : '' );
            }

            $missing = $this->ensureInstalled( $list );

            // The release to install, and the installed one a failed install puts back.
            $this->downloads->prune( [ $v, $installed ] );
        }

        $v = $this->candidate( $level );

        if ( $v === null ) {

            return $this->outcome( $listed && $list === null ? self::UNREACHED : self::CURRENT, null, [], sprintf( 'Nothing newer than %s is downloaded to install.', $installed ) );
        }

        if ( $dry_run ) {

            return $this->outcome( self::CHOSEN, $v );
        }

        if ( $missing === null && ! $this->downloads->has( $installed ) ) {

            $missing = sprintf( CoreAPI::t( 'The installed release, %s, has not been downloaded: a failed install puts it back, so nothing installs until the next listing downloads it.' ), $installed );
        }

        $judged   = $this->judge( $v );
        $rollback = $judged['rollback']
            ? array_merge( [ sprintf( CoreAPI::t( 'This database is newer than %s expects. Undo these updates first, newest first, with the code installed now:' ), $v ) ], $judged['rollback'] )
            : [];
        $problems = array_merge( $judged['problems'], $rollback, $missing === null ? [] : [ $missing ] );
        $url      = Releases::page( $v );

        if ( $problems ) {

            $this->notify( sprintf( 'unmet:%s:%s', $v, substr( md5( implode( "\n", $problems ) ), 0, 12 ) ), 'warning',
                sprintf( CoreAPI::t( 'OWA %s cannot be installed automatically yet' ), $v ),
                implode( "\n", $problems ) . "\n\n" . CoreAPI::t( 'It is checked again every hour, and installs once these are fixed.' ), $url );

            return $this->outcome( self::UNMET, $v, $problems, sprintf( '%s cannot be installed here yet; it is judged again at the next run.', $v ) );
        }

        if ( $judged['manual'] ) {

            $texts = array_map( static fn ( $u ) => sprintf( '%s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] ), $judged['manual'] );

            $this->notify( 'manual:' . $v, 'warning',
                sprintf( CoreAPI::t( 'OWA %s needs to be installed by hand' ), $v ),
                implode( "\n", $texts ) . "\n\n" . sprintf( CoreAPI::t( 'It is downloaded and checked. Take the backup each update names, then install it: php cli.php cmd=core-install --version=%s' ), $v ), $url );

            return $this->outcome( self::MANUAL, $v, $texts, sprintf( '%s has updates that must be installed by hand; it will not install itself.', $v ) );
        }

        $window = $this->window();

        if ( $window !== null && ! self::inWindow( $window, (int) $this->context['now'] ) ) {

            return $this->outcome( self::WAITING, $v, [], sprintf( '%s installs %s.', $v, self::describeWindow( $window ) ) );
        }

        $result = Install::prepare( $this->downloads, $v, $installed, $this->root, (bool) $this->context['scheduled'] );

        return $this->outcome( $result['outcome'] === Install::OK ? self::INSTALLING : ( $result['outcome'] === Install::BUSY ? self::BUSY : self::FAILED ),
            $v, $result['lines'], $result['message'] );
    }

    /**
     * Whether this hour is one of the four a day the scheduler's runs list
     * the releases in: six hours apart, at an offset this installation keeps
     * (the seed).
     *
     * @return bool
     */
    public function listingHour() {

        $offset = hexdec( substr( md5( (string) $this->context['seed'] ), 6, 6 ) ) % self::LISTING_EVERY;

        return ( (int) gmdate( 'G', (int) $this->context['now'] ) - $offset + 24 ) % self::LISTING_EVERY === 0;
    }

    /**
     * The seconds, 0-59, a scheduled run waits before it lists: kept by this
     * installation (the seed), so the installations that share an hour and a
     * minute do not ask in the same second.
     *
     * @return int
     */
    public function listingDelay() {

        return (int) ( hexdec( substr( md5( (string) $this->context['seed'] ), 12, 6 ) ) % 60 );
    }

    /**
     * The newest downloaded release the settings allow: newer than the
     * installed one, the same major, a patch for patch-only updates, and not
     * one whose install failed.
     *
     * @param  string $level
     * @return string|null
     */
    private function candidate( $level ) {

        $installed = (string) $this->context['installed'];

        foreach ( $this->downloads->versions() as $v ) {

            $step = Version::step( $installed, $v );

            if ( $step !== null && $step !== Version::MAJOR && ( $level !== Version::PATCH || $step === Version::PATCH ) && ! $this->skips( $v ) ) {

                return $v;
            }
        }

        return null;
    }

    /**
     * Whether automatic updates skip this release now: installing it over
     * the installed one failed (last-failure.json), and it is not yet
     * retry_after, or never is.
     *
     * @param  string $v
     * @return bool
     */
    private function skips( $v ) {

        $failure = $this->downloads->failure();
        $retry   = (int) ( $failure['retry_after'] ?? 0 );

        return ( $failure['version'] ?? null ) === $v && ( $failure['from'] ?? null ) === (string) $this->context['installed']
            && ( $retry === 0 || (int) $this->context['now'] < $retry );
    }

    /**
     * cmd=core-update-status: what automatic updates will do, and what is downloaded.
     *
     * @return list<string>
     */
    public function status() {

        $c         = $this->context;
        $installed = (string) $c['installed'];
        $lines     = [ sprintf( 'Installed: %s', $installed ) ];
        $vcs       = Vcs::checkout( $this->root );
        $window    = $this->window();

        if ( $vcs !== null ) {

            $lines[] = sprintf( 'Automatic updates: never, OWA runs from a version control checkout (%s).', $vcs );

        } elseif ( ! $c['enabled'] ) {

            $lines[] = 'Automatic updates: off (OWA_AUTO_UPDATE).';

        } else {

            $lines[] = sprintf( 'Automatic updates: %s releases, installed %s.',
                $c['level'] === Version::PATCH ? 'patch' : 'patch and minor', $window === null ? 'as soon as they are downloaded' : self::describeWindow( $window ) );
        }

        foreach ( $this->downloads->versions() as $v ) {

            if ( $v === $installed ) {

                $lines[] = sprintf( 'Downloaded: %s, the installed release, which a failed install puts back.', $v );

                continue;
            }

            $judged  = $this->judge( $v );
            $lines[] = sprintf( 'Downloaded: %s, %s UTC; %s.', $v, gmdate( 'Y-m-d H:i', (int) filemtime( $this->downloads->tarball( $v ) ) ),
                $this->skips( $v ) ? ( empty( $this->downloads->failure()['retry_after'] ) ? 'skipped, its install failed' : 'its install failed, tried again later' )
                    : ( $judged['problems'] ? 'cannot be installed yet' : 'can be installed' ) );

            foreach ( $judged['problems'] as $p ) {

                $lines[] = '    ' . $p;
            }

            foreach ( $judged['problems'] ? [] : $judged['manual'] as $u ) {

                $lines[] = sprintf( '    Needs installing by hand -- %s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] );
            }
        }

        if ( $this->downloads->count() > 1 ) {

            $lines[] = 'There is more than one updates-* directory in the data directory: nothing installs until all but one are removed.';
        }

        $failure = $this->downloads->failure();

        if ( $failure ) {

            $lines[] = sprintf( 'Installing %s over %s failed, %s UTC: %s', $failure['version'] ?? '?', $failure['from'] ?? '?',
                gmdate( 'Y-m-d H:i', (int) ( $failure['at'] ?? 0 ) ), $failure['error'] ?? '' );
        }

        $marker = Downloads::updater()['readMarker']( $this->root );

        if ( $marker !== null ) {

            $lines[] = $marker === []
                ? 'owa-update.php, the install marker, cannot be read.'
                : sprintf( 'Installing %s over %s: %s since %s UTC.', $marker['to'] ?? '?', $marker['from'] ?? '?', [
                    'waiting' => 'waiting for the other runs of cli.php to end',
                    'copying' => 'copying the files',
                    'copied'  => 'copied, waiting for the next run of cli.php to boot it',
                    'booted'  => 'booted, finishing',
                ][ (string) ( $marker['phase'] ?? '' ) ] ?? (string) ( $marker['phase'] ?? '' ), gmdate( 'Y-m-d H:i', (int) ( $marker['since'] ?? 0 ) ) );
        }

        if ( InstallLock::isHeld() ) {

            $lines[] = 'The install lock is held: an install is running now.';
        }

        return $lines;
    }

    /**
     * A downloaded release's checks, read from its tarball: what stops it
     * installing here (its requirements, and what the copy needs), the
     * updates in it that must be installed by hand, and, for one older than
     * the database, the rollbacks to run first.
     *
     * @param  string $v
     * @return array{problems: list<string>, manual: list<array{module: string, sequence: int, manual_install: string}>, rollback: list<string>}
     */
    public function judge( $v ) {

        // The file list first: it proves the tarball (Downloads::open()), and says why when it does not.
        try {

            $files = $this->downloads->files( $v );

        } catch ( \RuntimeException $e ) {

            return [ 'problems' => [ $e->getMessage() ], 'manual' => [], 'rollback' => [] ];
        }

        $release = (array) $this->downloads->releaseInfo( $v );
        $req     = (array) ( $release['requirements'] ?? [] );
        $env     = $this->context['env'] ?? Preflight::environment( $req );

        $problems = array_merge( Preflight::requirements( $req, $env ), Preflight::install( $files, $this->root ),
            Preflight::temp( $this->downloads->tarballBytes( $v, (string) $this->context['installed'] ) ) );

        // What the next run of cli.php needs, before OWA loads, to boot it or put this one back.
        foreach ( [ 'updater.php', 'cli.php', 'owa_env.php', 'Core/Env.php', 'modules/Base/Classes/AutoUpdate/Tar.php' ] as $needed ) {

            if ( ! isset( $files[ $needed ] ) ) {

                $problems[] = sprintf( CoreAPI::t( 'The release has no %s, so it cannot install itself. Install it by hand.' ), $needed );
            }
        }

        return [ 'problems' => $problems, 'manual' => $this->manualInstall( $v ), 'rollback' => $this->rollbackFirst( $v ) ];
    }

    /**
     * The installed release's tarball, which a failed install puts back:
     * downloaded and checked once, from $list or the listing, or by its
     * version when the listing does not have it (Releases::byTag()). Null
     * when it is there, else why it is not.
     *
     * @param  list<array<string, mixed>>|null $list the listing, when the caller has it
     * @return string|null
     */
    public function ensureInstalled( $list = null ) {

        $installed = (string) $this->context['installed'];

        if ( $this->downloads->has( $installed ) ) {

            return null;
        }

        try {

            try {

                $list = $list ?? $this->context['releases'] ?? Releases::fetch( Releases::listingUrl( (string) $this->context['url'], $installed ) );

            } catch ( \RuntimeException $e ) {

                $list = [];
            }

            $listed = Releases::find( $list, $installed, $installed );
            $this->downloads->download( $listed !== null ? Releases::via( $listed, (string) $this->context['download'] )
                : Releases::byTag( $installed, $installed, (string) $this->context['download'] ) );
            $this->lines[] = sprintf( 'Downloaded the installed release, %s, and checked its SHA-256 and signature: a failed install puts it back.', $installed );

            return null;

        } catch ( \RuntimeException $e ) {

            return sprintf( CoreAPI::t( 'The installed release, %1$s, could not be downloaded: %2$s. A failed install puts it back, so nothing installs until it is.' ), $installed, rtrim( $e->getMessage(), '.' ) );
        }
    }

    /** @return bool whether an install is under way: the install marker, or the lock */
    private function installing() {

        return Downloads::updater()['readMarker']( $this->root ) !== null || InstallLock::isHeld();
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

        foreach ( (array) ( $this->downloads->releaseInfo( $v )['modules'] ?? [] ) as $module => $expects ) {

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

        $release = (array) $this->downloads->releaseInfo( $v );
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
     * A download that failed: a notice in the bell the first time, and an
     * e-mail once it has kept failing for FAILING_FOR.
     *
     * @param  string $v
     * @param  string $why
     * @param  string $url
     * @return void
     */
    private function downloadFailed( $v, $why, $url ) {

        $first = ( $this->context['raised_at'] )( 'download_failing:' . $v );

        if ( $first === null ) {

            $this->notify( 'download_failing:' . $v, 'info',
                sprintf( CoreAPI::t( 'OWA %s could not be downloaded' ), $v ),
                $why . "\n\n" . CoreAPI::t( 'Automatic updates try again every six hours.' ), $url, false );

        } elseif ( (int) $this->context['now'] - $first >= self::FAILING_FOR ) {

            $this->notify( 'download_failed:' . $v, 'warning',
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
     * The window an update may start in, from the settings; null when it may
     * start at any time.
     *
     * @return array{days: list<int>, start: string, end: string, timezone: string}|null
     */
    public function window() {

        $window = (string) ( $this->context['window'] ?: '' );

        if ( ! preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])$/', $window ) ) {

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
     * Whether $now is inside the window. One that crosses midnight starts on
     * its day and ends on the next.
     *
     * @param  array{days: list<int>, start: string, end: string, timezone: string} $window
     * @param  int                                                                    $now
     * @return bool
     */
    public static function inWindow( array $window, $now ) {

        try {

            $tz = new \DateTimeZone( (string) $window['timezone'] );

        } catch ( \Exception $e ) {

            $tz = new \DateTimeZone( 'UTC' );
        }

        $t     = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
        $hm    = $t->format( 'H:i' );
        $start = (string) $window['start'];
        $end   = (string) $window['end'];
        $day   = (int) $t->format( 'N' );

        $inside = $start <= $end ? ( $hm >= $start && $hm < $end ) : ( $hm >= $start || $hm < $end );
        $on_day = $start <= $end || $hm >= $start ? $day : ( $day === 1 ? 7 : $day - 1 );

        return $inside && in_array( $on_day, array_map( 'intval', (array) $window['days'] ), true );
    }

    /**
     * @param  array<string, mixed> $window
     * @return string
     */
    public static function describeWindow( array $window ) {

        $names = array_flip( self::DAYS );
        $days  = count( (array) $window['days'] ) === 7 ? CoreAPI::t( 'any day' )
            : implode( ', ', array_map( fn ( $d ) => ucfirst( $names[ (int) $d ] ), (array) $window['days'] ) );

        return sprintf( CoreAPI::t( 'between %1$s and %2$s (%3$s), %4$s' ), $window['start'], $window['end'], $window['timezone'], $days );
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
