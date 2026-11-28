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
 *   2. With a listing: the release the settings choose (auto_update_level:
 *      the highest newer release in the installed major, or of any major).
 *      Its tarball is
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
 * A run that is not a dry run holds the install lock from before it
 * downloads or deletes anything until the copy is handed off (Install::$pending)
 * or the run ends; a run that finds it held changes nothing.
 *
 * Without a listing (the update host unreachable, or not a listing hour) only 3 and 4
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
    const FOREIGN    = 'files_of_another_user';

    /** The notices' source (NotificationManager). */
    const SOURCE = 'auto_update';

    /** Hours between listings in the scheduler's runs. */
    const LISTING_EVERY = 6;

    const DAYS = [ 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 ];

    /** An update window, HH:MM-HH:MM, as a regular expression's body: the auto_update_window setting's pattern too. */
    const WINDOW = '([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])';

    /** A day of DAYS, as a regular expression's body: the auto_update_days setting's pattern too. */
    const DAY = '(mon|tue|wed|thu|fri|sat|sun)';

    /** @var Downloads */
    private $downloads;

    /** @var string */
    private $root;

    /** @var list<string> */
    private $lines = [];

    /** @var bool whether this run listed the releases */
    private $listed = false;

    /** @var string|null where a failed run failed, when its outcome does not say (Problem::STAGES) */
    private $stage = null;

    /** @var array<string, mixed>|null the install lock this run holds (updater.php `tryLock`) */
    private $hold = null;

    /** @var string what failed and why, for a run whose problems do not say it */
    private $why = '';

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
            // secret, its public URL and its directory, only hashed here, never
            // sent. A clone copies owa-config.php, so the URL and the
            // directory are what set it apart.
            'seed'      => hash( 'sha256', 'owa-release-listing|' . ( defined( 'OWA_AUTH_KEY' ) ? (string) constant( 'OWA_AUTH_KEY' ) : '' )
                . '|' . (string) CoreAPI::getSetting( 'base', 'public_url' ) . '|' . \OWA\Core\Env::root() ),
            'sleep'     => static fn ( $seconds ) => sleep( (int) $seconds ),
            // Who runs this: the files automatic updates keep must be this user's (Downloads::foreign()).
            'uid'       => function_exists( 'posix_geteuid' ) ? posix_geteuid() : null,
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
            // What stops a release before anything changes, while it lasts.
            'problem_get' => [ Problem::class, 'stored' ],
            'problem_set' => [ Problem::class, 'store' ],
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

        $this->listed = false;
        $this->stage  = null;
        $this->why    = '';
        $this->hold   = null;

        try {

            $result = $this->check( $dry_run );

        } finally {

            // Every outcome but an install handed to the copy lets the lock go.
            if ( $this->hold !== null ) {

                Install::unlock( $this->hold );
                $this->hold = null;
            }
        }

        if ( ! $dry_run ) {

            $this->problem( $result );
        }

        return $result;
    }

    /**
     * A failure before the copy (Problem): kept from the check that finds it,
     * noticed once it has lasted, cleared by a check that gets past it.
     *
     * @param  array<string, mixed> $result
     * @return void
     */
    private function problem( array $result ) {

        $was   = ( $this->context['problem_get'] )();
        $stage = $this->stage ?? ( Problem::STAGES[ $result['outcome'] ] ?? null );

        if ( $stage === null ) {

            // An install under way decides nothing; a run that did not list cannot say a listing or download works again.
            if ( $was === null || $result['outcome'] === self::BUSY
                || ( ! $this->listed && in_array( $was['stage'] ?? '', Problem::LISTING, true ) ) ) {

                return;
            }

            ( $this->context['problem_set'] )( null );

            return;
        }

        $installed = (string) $this->context['installed'];
        $u         = Downloads::updater();
        $log       = array_reduce( array_merge( [ sprintf( 'Release check (%s), OWA %s installed.', $this->context['scheduled'] ? 'the scheduler\'s' : 'by hand', $installed ) ], $result['lines'] ),
            static fn ( $m, $line ) => $u['logged']( $m, $line ), [] )['log'];

        $lines = array_values( array_filter( $result['lines'], static fn ( $l ) => strpos( (string) $l, '  ' ) !== 0 ) );
        $error = $stage === 'listing' ? $this->why
            : ( $result['problems'] ? implode( ' ', array_map( 'trim', $result['problems'] ) ) : (string) end( $lines ) );

        $p = Problem::next( $was, $stage, $result['version'], $installed, $error, $log, (int) $this->context['now'] );

        if ( Problem::describe( $p )['notify'] ) {

            Problem::notify( $p, $this->context['notify'] );
            $p['noticed'] = true;
        }

        ( $this->context['problem_set'] )( $p );
    }

    /**
     * @param  bool $dry_run
     * @return Outcome
     */
    private function check( $dry_run ) {

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

                $error     = $e->getMessage();
                $this->why = $error;
            }
        }

        // Listed, and it worked: only such a run says a listing or download problem is over.
        $this->listed = $list !== null;

        if ( $list !== null && ! $dry_run ) {

            // A release automatic updates will install is not announced: its notice is the one that says it installed.
            $level    = $this->level();
            $installs = $this->context['enabled'] && $this->context['role'] === \OWA\Core\ServerRole::FULL
                && Vcs::checkout( $this->root ) === null && Version::parse( $installed ) !== null;
            $announce = $installs ? array_values( array_filter( $list,
                static fn ( $r ) => Releases::installable( is_array( $r ) ? $r : [], $installed, $level ) === null ) ) : $list;

            $created = (int) ( $this->context['feed'] )( $announce );
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

        // Before anything here is read: a failure record another user wrote was never this installation's.
        $foreign = $this->foreignProblems();

        if ( $foreign ) {

            return $this->outcome( self::FOREIGN, null, $foreign, 'Automatic updates cannot run:' );
        }

        if ( ! $dry_run ) {

            $this->hold = Downloads::updater()['readMarker']( $this->root ) === null
                ? Install::lock( 'release check' ) : null;

            if ( $this->hold === null ) {

                return $this->outcome( self::BUSY, null, [], 'An install is under way; this run changed nothing.' );
            }
        }

        $failure = $this->downloads->failure();

        if ( $failure && ! $dry_run ) {

            InstallNotices::forFailure( $failure, $this->context['notify'] );
        }

        if ( ! $this->context['enabled'] ) {

            if ( ! $dry_run ) {

                $this->downloads->prune( [ $installed, $this->downloads->pending( $installed ) ] );
            }

            return $this->outcome( self::OFF, null, [], 'Automatic updates are off.' );
        }

        // Every run, not only when a release is due: the job fails, so System
        // Health says so before a security release needs it.
        $temp = $this->context['temp'] ?? Preflight::temp( $this->downloads->tarballBytes( $installed, $installed ) );

        if ( $temp ) {

            return $this->outcome( self::NO_TEMP, null, $temp, 'No release can be installed:' );
        }

        $level   = $this->level();
        $missing = null;

        if ( $listed && $list === null ) {

            $this->lines[] = sprintf( 'The releases could not be listed: %s', $error );

        } elseif ( $list !== null ) {

            $release = Releases::choose( $list, $installed, $level );

            if ( $release === null ) {

                if ( ! $dry_run ) {

                    $this->downloads->prune( [ $installed, $this->downloads->pending( $installed ) ] );
                }

                return $this->outcome( self::CURRENT, null, [], sprintf( 'No release newer than %s for automatic %s updates.', $installed,
                    self::describeLevel( $level ) ) );
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

                    return $this->outcome( self::FAILED, $v, [ $e->getMessage() ], sprintf( 'Downloading %s failed; the next listing tries again.', $v ) );
                }

                $this->lines[] = sprintf( 'Downloaded %s and checked its SHA-256 and signature%s.', $v, $replaced ? ', as the release\'s asset was replaced' : '' );
            }

            $missing = $this->ensureInstalled( $list );

            // The release to install, replacing any other pending one, and the installed one a failed install puts back.
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

        // prepare() takes the hold: it hands it to the copy, or lets it go.
        $hold       = $this->hold;
        $this->hold = null;
        $result     = Install::prepare( $this->downloads, $v, $installed, $this->root, (bool) $this->context['scheduled'],
            array_merge( [ sprintf( 'Release check (%s), OWA %s installed.', $this->context['scheduled'] ? 'the scheduler\'s' : 'by hand', $installed ) ], $this->lines ), $hold );

        if ( $result['outcome'] !== Install::OK && $result['outcome'] !== Install::BUSY ) {

            $this->stage = 'prepare';
        }

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
     * installed one, a step the level allows, and not one whose install
     * failed.
     *
     * @param  string $level
     * @return string|null
     */
    private function candidate( $level ) {

        $installed = (string) $this->context['installed'];

        foreach ( $this->downloads->versions() as $v ) {

            if ( Version::allows( $level, $installed, $v ) && ! $this->skips( $v ) ) {

                return $v;
            }
        }

        return null;
    }

    /**
     * A downloaded release's upgrades_from (release.php) when the installed
     * release is older than it; null when it installs over the installed
     * release, names no upgrades_from, or is not downloaded.
     *
     * @param  string $v
     * @return string|null
     */
    private function upgradesFrom( $v ) {

        $from      = (string) ( $this->downloads->releaseInfo( $v )['upgrades_from'] ?? '' );
        $installed = (string) $this->context['installed'];

        if ( Version::parse( $from ) === null || Version::parse( $installed ) === null ) {

            return null;
        }

        return Version::compare( $installed, $from ) < 0 ? $from : null;
    }

    /** @return string Version::MAJOR when auto_update_level says so, else Version::MINOR */
    private function level() {

        return $this->context['level'] === Version::MAJOR ? Version::MAJOR : Version::MINOR;
    }

    /**
     * The releases a level installs, in words.
     *
     * @param  string $level
     * @return string
     */
    private static function describeLevel( $level ) {

        return $level === Version::MAJOR ? 'patch, minor and major' : 'patch and minor';
    }

    /** The constant that sets auto_update_level. */
    const LEVEL_CONSTANT = 'OWA_AUTO_UPDATE_LEVEL';

    /** The values auto_update_level takes. */
    const LEVELS = [ Version::MINOR, Version::MAJOR ];

    /**
     * What is wrong with OWA_AUTO_UPDATE_LEVEL: a value that is not one of
     * LEVELS. Every request is refused while there is one
     * (Settings::configProblems()).
     *
     * @param  mixed $value the constant's value
     * @return list<string>
     */
    public static function levelProblems( $value ) {

        if ( is_string( $value ) && in_array( $value, self::LEVELS, true ) ) {

            return [];
        }

        return [ sprintf( CoreAPI::t( '%1$s is %2$s, which is not a level. It is minor (patch and minor releases) or major (patch, minor and major releases).' ),
            self::LEVEL_CONSTANT, is_scalar( $value ) ? '"' . (string) $value . '"' : gettype( $value ) ) ];
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

            $lines[] = 'Automatic updates: off (the auto_update setting).';

        } else {

            $lines[] = sprintf( 'Automatic updates: %s releases, installed %s.',
                self::describeLevel( $this->level() ), $window === null ? 'as soon as they are downloaded' : self::describeWindow( $window ) );
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

            $d     = InstallNotices::describe( $failure );
            $lines = array_merge( $lines, [ sprintf( '%s (%s UTC): %s', $d['title'], gmdate( 'Y-m-d H:i', (int) ( $failure['at'] ?? 0 ) ), implode( ' ', $d['lines'] ) ) ],
                $d['log'] ? array_merge( [ 'What the install did:' ], array_map( static fn ( $l ) => '  ' . $l, $d['log'] ) ) : [] );
        }

        $problem = ( $c['problem_get'] )();

        if ( $problem ) {

            $d     = Problem::describe( $problem );
            $lines = array_merge( $lines, [ sprintf( '%s: %s', $d['title'], implode( ' ', $d['lines'] ) ) ],
                $d['log'] ? array_merge( [ 'What the last check did:' ], array_map( static fn ( $l ) => '  ' . $l, $d['log'] ) ) : [] );
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
     * installing here (the oldest release it installs over, its
     * requirements, and what the copy needs), the
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

        $from     = $this->upgradesFrom( $v );
        $problems = array_merge( $from === null ? [] : [ sprintf( CoreAPI::t( 'OWA %1$s installs over %2$s or later; OWA %3$s is installed. Install %2$s first.' ),
                $v, $from, (string) $this->context['installed'] ) ],
            Preflight::requirements( $req, $env ), Preflight::install( $files, $this->root ),
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

    /**
     * The update files another user made (Downloads::foreign()), as problems;
     * none without the posix extension, which says who runs this.
     *
     * @return list<string>
     */
    public function foreignProblems() {

        $uid = $this->context['uid'];

        return $uid === null ? [] : Downloads::foreignProblems( $this->downloads->foreign( (int) $uid ), (int) $uid );
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
     * start at any time. A window not of the form HH:MM-HH:MM is ignored
     * (ignoredWindow()); without one, Update Days alone allow any hour of
     * those days, 00:00-24:00.
     *
     * @return array{days: list<int>, start: string, end: string, timezone: string}|null
     */
    public function window() {

        $window = (string) ( $this->context['window'] ?: '' );
        $valid  = (bool) preg_match( '/^' . self::WINDOW . '$/', $window );
        $days   = [];

        foreach ( preg_split( '/\s*,\s*/', strtolower( trim( (string) $this->context['days'] ) ) ) ?: [] as $day ) {

            if ( isset( self::DAYS[ $day ] ) ) {

                $days[] = self::DAYS[ $day ];
            }
        }

        $days = $days ? array_values( array_unique( $days ) ) : array_values( self::DAYS );
        sort( $days );

        if ( $valid ) {

            [ $start, $end ] = explode( '-', $window );

        } elseif ( count( $days ) < count( self::DAYS ) ) {

            [ $start, $end ] = [ '00:00', '24:00' ];

        } else {

            return null;
        }

        return [ 'days' => $days, 'start' => $start, 'end' => $end, 'timezone' => (string) $this->context['timezone'] ];
    }

    /**
     * An auto_update_window that is set but not of the form HH:MM-HH:MM, as
     * OWA_AUTO_UPDATE_WINDOW can be: ignored, so updates may start at any
     * time (of the Update Days). Null for a valid or empty one.
     *
     * @param  mixed $value the setting
     * @return string|null the sentence System Health and the Updates screen show
     */
    public static function ignoredWindow( $value ) {

        $value = is_scalar( $value ) ? trim( (string) $value ) : '';

        if ( $value === '' || preg_match( '/^' . self::WINDOW . '$/', $value ) ) {

            return null;
        }

        return sprintf( CoreAPI::t( 'The update window "%s" is not of the form HH:MM-HH:MM, such as 02:00-06:00, so it is ignored: updates may start at any time.' ), $value );
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

        if ( $window['start'] === '00:00' && $window['end'] === '24:00' ) {

            return sprintf( CoreAPI::t( 'at any hour (%1$s), %2$s' ), $window['timezone'], $days );
        }

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
