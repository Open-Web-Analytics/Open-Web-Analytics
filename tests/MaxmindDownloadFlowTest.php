<?php

use PHPUnit\Framework\TestCase;

/**
 * The download flow, tested without touching MaxMind or a licence key.
 *
 * WHY THIS IS NOT AN INTEGRATION TEST
 * The real thing costs a download from an account's limit -- MaxMind rate-limit
 * and say so -- and needs a credential that must not be in a repository, a CI
 * secret store, or a developer's shell history. A test suite that needed either
 * would be a test suite nobody runs, and what can go wrong is mostly either
 * side of the HTTP: the archive has to be named so PharData will open it, and
 * a dry run has to stop before the download. Both are reachable with the
 * transport replaced, which is what this does. The
 * controller's download() and lastModified() are protected precisely so a test
 * can stand in for them, and dataDir() so nothing is written near a real
 * installation's 60MB database.
 *
 * The fixture archive is built here rather than committed: it is a real
 * .tar.gz, nested in a dated directory the way MaxMind's is, so the code that
 * locates the .mmdb inside it is genuinely exercised.
 */
final class MaxmindDownloadFlowTest extends TestCase {

    /** @var string */
    private $dir;

    /** @var string */
    private $fixture;

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void {

        if ( ! class_exists( 'PharData' ) ) {
            $this->markTestSkipped( 'phar is required to build the fixture archive' );
        }

        $this->dir = sys_get_temp_dir() . '/owa-geoip-test-' . getmypid() . '-' . uniqid() . '/';
        mkdir( $this->dir, 0755, true );

        $this->fixture = $this->buildArchive( 'GeoLite2-City' );
    }

    protected function tearDown(): void {

        // Dotfiles too: the download-attempt marker is one.
        foreach ( array_diff( scandir( $this->dir ) ?: [], [ '.', '..' ] ) as $name ) {

            $path = $this->dir . $name;
            is_dir( $path ) ? $this->removeTree( $path ) : @unlink( $path );
        }

        @rmdir( $this->dir );
        @unlink( $this->fixture );
    }

    private function removeTree( $dir ) {

        foreach ( new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {

            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }

        @rmdir( $dir );
    }

    /**
     * A .tar.gz shaped like MaxMind's: the database nested inside a directory
     * named for the edition and the date it was built.
     */
    private function buildArchive( $edition, $contents = 'FIXTURE-DATABASE-CONTENTS' ) {

        $staging = sys_get_temp_dir() . '/owa-geoip-fixture-' . uniqid() . '/';
        $nested  = $staging . $edition . '_20260814/';

        mkdir( $nested, 0755, true );
        file_put_contents( $nested . $edition . '.mmdb', $contents );
        file_put_contents( $nested . 'COPYRIGHT.txt', 'fixture' );

        $tar = sys_get_temp_dir() . '/owa-geoip-fixture-' . uniqid() . '.tar';

        $phar = new PharData( $tar );
        $phar->buildFromDirectory( $staging );
        $phar->compress( Phar::GZ );

        unset( $phar );
        @unlink( $tar );
        $this->removeTree( $staging );

        return $tar . '.gz';
    }

    /**
     * The controller with the network replaced and the destination redirected.
     */
    private function controller( array $options = [] ) {

        $fixture = $this->fixture;
        $dir     = $this->dir;

        $controller = new class( $fixture, $dir, $options )
            extends \OWA\Module\MaxmindGeoip\Controller\GeoipUpdateDbCli {

            public $downloads = 0;
            public $head_requests = 0;
            public $messages = [];
            private $fixture;
            private $dir;
            private $options;

            public function __construct( $fixture, $dir, array $options ) {

                $this->fixture = $fixture;
                $this->dir     = $dir;
                $this->options = $options;
                $this->params  = isset( $options['params'] ) ? $options['params'] : [];
                $this->data    = [];
            }

            protected function dataDir() { return $this->dir; }

            protected function download( $url, $destination ) {

                $this->downloads++;

                if ( isset( $this->options['download_fails'] ) ) {
                    $this->download_status = isset( $this->options['download_status'] ) ? $this->options['download_status'] : 0;
                    return $this->options['download_fails'];
                }

                if ( isset( $this->options['download_junk'] ) ) {
                    file_put_contents( $destination, 'NOT-AN-ARCHIVE' );
                    return (int) filesize( $destination );
                }

                copy( $this->fixture, $destination );

                return (int) filesize( $destination );
            }

            protected function lastModified( $url ) {

                $this->head_requests++;

                $this->check_status = isset( $this->options['check_status'] ) ? $this->options['check_status'] : 0;

                return isset( $this->options['remote_mtime'] ) ? $this->options['remote_mtime'] : 0;
            }

            protected function write( $lines ) {

                foreach ( (array) $lines as $line ) { $this->messages[] = $line; }
            }

            // The outcome the parent would record, without its notice().
            protected function refuse( $msg ) { return $this->outcome( 'refused', $msg ); }
            protected function fail( $msg )   { return $this->outcome( 'failed', $msg ); }
            protected function usageError( $msg ) { $this->usage_errors++; return $this->outcome( 'failed', $msg ); }

            /** @var int how many usage errors, which exit 2 */
            public $usage_errors = 0;
            protected function skip( $msg )   { return $this->outcome( 'ok', $msg ); }

            private function outcome( $outcome, $msg ) {

                $this->messages[]  = $msg;
                $this->cli_outcome = $outcome;
                $this->cli_message = $this->cli_message ?: $msg;

                return null;
            }

            public function outcomeIs() { return $this->getCliOutcome()['outcome']; }

            public function retried() { return $this->getCliOutcome()['retry']; }

            public function said( $needle ) {

                return (bool) array_filter( $this->messages, function ( $m ) use ( $needle ) {
                    return stripos( (string) $m, $needle ) !== false;
                } );
            }
        };

        return $controller;
    }

    private function withKey( array $params = [] ) {

        return array_merge( [ 'license-key' => 'fixture-key-not-a-real-one' ], $params );
    }

    public function testAFullRunWritesTheDatabaseWhereLookupsReadIt(): void {

        $c = $this->controller( [ 'params' => $this->withKey() ] );
        $c->action();

        $this->assertFileExists( $this->dir . 'GeoLite2-City.mmdb',
            'the database must end up at the path the module reads' );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ),
            'the file located inside the nested archive directory must be the one installed' );
    }

    /**
     * The bug that a real key exposed: the archive is nested in a dated
     * directory, so the .mmdb has to be found rather than assumed.
     */
    public function testItFindsTheDatabaseInsideADatedDirectory(): void {

        $c = $this->controller( [ 'params' => $this->withKey() ] );
        $c->action();

        $this->assertFileDoesNotExist( $this->dir . 'GeoLite2-City_20260814',
            'the wrapper directory must not be left behind' );
        $this->assertSame( [], glob( $this->dir . '.update-*' ) ?: [],
            'the working directory must be cleaned up' );
    }

    /**
     * Another user can write the database's directory (the data directory's
     * maxmind/ was shared): a link it puts at a name this command writes is
     * never written through, and the working directory is one this run made.
     */
    public function testNothingIsWrittenThroughALinkPutInTheDirectory(): void {

        $victim = sys_get_temp_dir() . '/owa-geoip-victim-' . bin2hex( random_bytes( 4 ) );
        symlink( $victim, $this->dir . '.GeoLite2-City.download-attempt' );

        $c = $this->controller( [ 'params' => $this->withKey() ] );
        $c->action();

        $this->assertSame( 'ok', $c->outcomeIs() );
        $this->assertFileDoesNotExist( $victim, 'the attempt marker was not written through the link' );
        $this->assertSame( '0644', sprintf( '%04o', fileperms( $this->dir . 'GeoLite2-City.mmdb' ) & 0777 ),
            'readable by the web server, set before it was moved into place' );
        @unlink( $victim );
    }

    /** removeTree() deletes nothing through a link: the iterator would follow it. */
    public function testRemovingATreeNeverFollowsALink(): void {

        $target = sys_get_temp_dir() . '/owa-geoip-keep-' . bin2hex( random_bytes( 4 ) );
        mkdir( $target );
        file_put_contents( $target . '/keep.txt', 'x' );
        symlink( $target, $this->dir . 'link' );

        $cli = ( new ReflectionClass( \OWA\Module\MaxmindGeoip\Controller\GeoipUpdateDbCli::class ) )->newInstanceWithoutConstructor();
        ( new ReflectionMethod( $cli, 'removeTree' ) )->invoke( $cli, $this->dir . 'link/' );

        $this->assertFileExists( $target . '/keep.txt' );

        unlink( $this->dir . 'link' );
        unlink( $target . '/keep.txt' );
        rmdir( $target );
    }

    /**
     * The other bug: asking what would happen must not cost what doing it
     * costs. MaxMind rate-limit downloads.
     */
    public function testADryRunMakesNoDownload(): void {

        $c = $this->controller( [ 'params' => $this->withKey( [ 'dry-run' => 1 ] ) ] );
        $c->action();

        $this->assertSame( 0, $c->downloads, 'a dry run must not transfer the archive' );
        $this->assertFileDoesNotExist( $this->dir . 'GeoLite2-City.mmdb' );
        $this->assertTrue( $c->said( 'Dry run' ) );
    }

    public function testAnUpToDateCopyIsNotDownloadedAgain(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );
        touch( $this->dir . 'GeoLite2-City.mmdb', time() );

        $c = $this->controller( [
            'params'       => $this->withKey(),
            'remote_mtime' => time() - 86400,   // MaxMind's copy is older
        ] );
        $c->action();

        $this->assertSame( 1, $c->head_requests, 'it must ask before deciding' );
        $this->assertSame( 0, $c->downloads, 'nothing to fetch, so nothing should be fetched' );
        $this->assertSame( 'EXISTING', file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ) );
        $this->assertTrue( $c->said( 'Already current' ) );
        $this->assertSame( 'refused', $c->outcomeIs(), 'by hand, an already current copy is a refusal' );
        $this->assertTrue( $c->said( '--force' ) );
    }

    public function testANewerRemoteCopyIsDownloaded(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );
        touch( $this->dir . 'GeoLite2-City.mmdb', time() - 86400 * 7 );

        $c = $this->controller( [
            'params'       => $this->withKey(),
            'remote_mtime' => time(),
        ] );
        $c->action();

        $this->assertSame( 1, $c->downloads );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ),
            'a newer remote copy must replace the local one' );
    }

    /** By hand, an update check with no answer still downloads. */
    public function testByHandACheckWithNoAnswerDownloads(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );

        $c = $this->controller( [ 'params' => $this->withKey() ] );
        $c->action();

        $this->assertSame( 1, $c->head_requests );
        $this->assertSame( 1, $c->downloads );
    }

    // -----------------------------------------------------------------------
    // Scheduled runs (scheduled=1, the refresh-geoip-db job)
    // -----------------------------------------------------------------------

    private function scheduled( array $options = [] ) {

        return $this->controller( array_merge(
            [ 'params' => $this->withKey( [ 'scheduled' => 1 ] ) ], $options ) );
    }

    private function localCopy( $age ) {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );
        touch( $this->dir . 'GeoLite2-City.mmdb', time() - $age );
    }

    private function marker() {

        return $this->dir . '.GeoLite2-City.download-attempt';
    }

    /** Most weeks: the job did its work, so System Health shows success, not a refusal. */
    public function testAScheduledRunWithACurrentCopyIsASuccess(): void {

        $this->localCopy( 0 );

        $c = $this->scheduled( [ 'remote_mtime' => time() - 86400 ] );
        $c->action();

        $this->assertSame( 0, $c->downloads );
        $this->assertSame( 'ok', $c->outcomeIs() );
        $this->assertStringStartsWith( 'Already current', $c->getCliOutcome()['message'],
            'the job history records why nothing was downloaded' );
        $this->assertFalse( $c->said( '--force' ), 'the hint is for a person at a terminal' );
    }

    public function testAScheduledRunWithoutAKeySkipsAsASuccess(): void {

        $config      = \OWA\Core\CoreAPI::configSingleton();
        $previous_db = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_license_key' );
        $previous_ws = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_license_key' );

        $config->set( 'maxmind_geoip', 'db_license_key', '' );
        $config->set( 'maxmind_geoip', 'ws_license_key', '' );

        $c = $this->controller( [ 'params' => [ 'scheduled' => 1 ] ] );
        $c->action();

        $config->set( 'maxmind_geoip', 'db_license_key', $previous_db );
        $config->set( 'maxmind_geoip', 'ws_license_key', $previous_ws );

        $this->assertSame( 0, $c->head_requests );
        $this->assertSame( 0, $c->downloads );
        $this->assertSame( 'ok', $c->outcomeIs() );
        $this->assertCount( 1, $c->messages, 'one line saying why' );
        $this->assertStringContainsString( 'no MaxMind licence key', $c->messages[0] );
    }

    public function testAScheduledRunDownloadsANewerCopy(): void {

        $this->localCopy( 86400 * 7 );

        $c = $this->scheduled( [ 'remote_mtime' => time() - 3600 ] );
        $c->action();

        $this->assertSame( 1, $c->head_requests );
        $this->assertSame( 1, $c->downloads );
        $this->assertSame( 'ok', $c->outcomeIs() );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS', file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ) );
        $this->assertFileDoesNotExist( $this->marker(), 'an installed download leaves no marker' );
    }

    public function testAScheduledRunWithNoLocalCopyDownloadsWithoutAsking(): void {

        $c = $this->scheduled();
        $c->action();

        $this->assertSame( 0, $c->head_requests );
        $this->assertSame( 1, $c->downloads );
        $this->assertFileExists( $this->dir . 'GeoLite2-City.mmdb' );
    }

    /**
     * No answer from the update check: a failure, never a download spent on a
     * copy that may be current. Retried by the scheduler with another free
     * HEAD -- except a rejected key, which the same HEAD would only hear
     * again: that failure waits for the next scheduled run.
     */
    public function testAScheduledRunDoesNotDownloadWhenTheCheckGetsNoAnswer(): void {

        $this->localCopy( 86400 );

        foreach ( [
            0   => [ 'Could not reach MaxMind', true ],
            200 => [ 'gave no Last-Modified date', true ],
            401 => [ 'rejected the licence key', false ],
            403 => [ 'rejected the licence key', false ],
        ] as $status => [ $said, $retried ] ) {

            $c = $this->scheduled( [ 'check_status' => $status ] );
            $c->action();

            $this->assertSame( 1, $c->head_requests );
            $this->assertSame( 0, $c->downloads, "HTTP $status" );
            $this->assertSame( 'failed', $c->outcomeIs(), "HTTP $status" );
            $this->assertSame( $retried, $c->retried(), "HTTP $status" );
            $this->assertTrue( $c->said( $said ), "HTTP $status" );
            $this->assertSame( ! $retried, $c->said( 'Not retried before the next scheduled run' ), "HTTP $status" );
            $this->assertSame( 'EXISTING', file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ) );
        }
    }

    /** With no local copy a scheduled run downloads without asking; a rejected key there waits too. */
    public function testAScheduledDownloadWithARejectedKeyIsNotRetriedHourly(): void {

        foreach ( [ 401 => false, 403 => false, 500 => true ] as $status => $retried ) {

            $c = $this->scheduled( [ 'download_fails' => "HTTP $status from MaxMind.", 'download_status' => $status ] );
            $c->action();

            $this->assertSame( 1, $c->downloads, "HTTP $status" );
            $this->assertSame( 'failed', $c->outcomeIs(), "HTTP $status" );
            $this->assertSame( $retried, $c->retried(), "HTTP $status" );
        }
    }

    /** By hand a rejected key is an ordinary failure; nothing schedules it. */
    public function testByHandARejectedKeyIsAnOrdinaryFailure(): void {

        $c = $this->controller( [
            'params'          => $this->withKey(),
            'download_fails'  => 'MaxMind rejected the licence key.',
            'download_status' => 401,
        ] );
        $c->action();

        $this->assertSame( 'failed', $c->outcomeIs() );
        $this->assertFalse( $c->said( 'Not retried' ) );
    }

    /**
     * The scheduler retries a failed job hourly. A download that did not
     * install is not downloaded again for a day, except by hand.
     */
    public function testAScheduledRunDoesNotRepeatADownloadThatDidNotInstall(): void {

        $first = $this->scheduled( [ 'download_junk' => 1 ] );
        $first->action();

        $this->assertSame( 1, $first->downloads );
        $this->assertSame( 'failed', $first->outcomeIs() );
        $this->assertFileExists( $this->marker() );

        $retry = $this->scheduled();
        $retry->action();

        $this->assertSame( 0, $retry->downloads, 'the hourly retry must not spend another download' );
        $this->assertSame( 'failed', $retry->outcomeIs() );
        $this->assertTrue( $retry->said( 'did not install' ) );

        touch( $this->marker(), time() - \OWA\Module\MaxmindGeoip\Controller\GeoipUpdateDbCli::RETRY_DOWNLOAD_AFTER - 1 );

        $next_day = $this->scheduled();
        $next_day->action();

        $this->assertSame( 1, $next_day->downloads, 'a day later it downloads again' );
        $this->assertSame( 'ok', $next_day->outcomeIs() );
        $this->assertFileDoesNotExist( $this->marker() );
    }

    public function testByHandADownloadThatDidNotInstallCanBeRetriedAtOnce(): void {

        $this->scheduled( [ 'download_junk' => 1 ] )->action();

        $c = $this->controller( [ 'params' => $this->withKey() ] );
        $c->action();

        $this->assertSame( 1, $c->downloads );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS', file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ) );
    }

    public function testForceDownloadsEvenWhenCurrent(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );

        $c = $this->controller( [
            'params'       => $this->withKey( [ 'force' => 1 ] ),
            'remote_mtime' => time() - 86400,
        ] );
        $c->action();

        $this->assertSame( 1, $c->downloads );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ) );
    }

    /**
     * A failed download must leave the working database exactly as it was. It
     * is read by every tracking request while this runs.
     */
    public function testAFailedDownloadLeavesTheExistingDatabaseAlone(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );

        $c = $this->controller( [
            'params'         => $this->withKey( [ 'force' => 1 ] ),
            'download_fails' => 'MaxMind rejected the licence key.',
        ] );
        $c->action();

        $this->assertSame( 'EXISTING', file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ),
            'the live database must survive a failed update' );
        $this->assertTrue( $c->said( 'rejected' ) );
    }

    /**
     * No key, no request. Worth asserting because the failure without it is an
     * HTTP error nobody can act on.
     */
    public function testNothingIsRequestedWithoutALicenceKey(): void {

        $config = \OWA\Core\CoreAPI::configSingleton();
        $previous_db = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_license_key' );
        $previous_ws = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_license_key' );

        $config->set( 'maxmind_geoip', 'db_license_key', '' );
        $config->set( 'maxmind_geoip', 'ws_license_key', '' );

        $c = $this->controller();
        $c->action();

        $config->set( 'maxmind_geoip', 'db_license_key', $previous_db );
        $config->set( 'maxmind_geoip', 'ws_license_key', $previous_ws );

        $this->assertSame( 0, $c->downloads );
        $this->assertSame( 0, $c->head_requests );
        $this->assertTrue( $c->said( 'licence key' ) );
    }

    public function testAnEditionTheModuleCannotReadIsRefusedBeforeAnyRequest(): void {

        $c = $this->controller( [
            'params' => $this->withKey( [ 'edition' => 'GeoLite2-ASN' ] ),
        ] );
        $c->action();

        $this->assertSame( 0, $c->downloads );
        $this->assertTrue( $c->said( 'not an edition' ) );

        // An argument the command cannot act on: a usage error, exit code 2.
        $this->assertSame( 1, $c->usage_errors );
        $this->assertSame( 'failed', $c->outcomeIs() );
    }

    private function installDatabase( $found, $destination ) {

        $m = new ReflectionMethod(
            \OWA\Module\MaxmindGeoip\Controller\GeoipUpdateDbCli::class, 'installDatabase' );

        return $m->invoke( $this->controller(), $found, $destination );
    }

    /**
     * The database being replaced is kept.
     *
     * A download that succeeds but produces a bad file, or an upstream release
     * with a regression in it, would otherwise leave no way back.
     */
    public function testTheReplacedDatabaseIsKept(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'THE-OLD-DATABASE' );

        $c = $this->controller( [ 'params' => $this->withKey( [ 'force' => 1 ] ) ] );
        $c->action();

        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ),
            'the new database must be installed' );

        $this->assertSame( 'THE-OLD-DATABASE',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb.previous' ),
            'and the one it replaced must still exist' );

        $this->assertTrue( $c->said( '.previous' ),
            'the command must say where the replaced copy went, or nobody will know to delete it' );
    }

    /**
     * Exactly one. The database is replaced twice a week and is 60MB; keeping
     * every previous copy would fill a disk quietly.
     */
    public function testOnlyOnePreviousCopyIsKept(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'GENERATION-1' );

        $this->controller( [ 'params' => $this->withKey( [ 'force' => 1 ] ) ] )->action();
        $this->controller( [ 'params' => $this->withKey( [ 'force' => 1 ] ) ] )->action();
        $this->controller( [ 'params' => $this->withKey( [ 'force' => 1 ] ) ] )->action();

        $copies = glob( $this->dir . '*.previous*' ) ?: [];

        $this->assertCount( 1, $copies,
            'three runs must leave one previous copy, not three' );
    }

    public function testAFirstInstallLeavesNoPreviousCopy(): void {

        $this->controller( [ 'params' => $this->withKey() ] )->action();

        $this->assertFileDoesNotExist( $this->dir . 'GeoLite2-City.mmdb.previous',
            'there was nothing to keep, so nothing should be kept' );
    }

    /**
     * The important half: if putting the new file in place fails after the old
     * one has been moved aside, the old one goes back. An installation must not
     * be left with NO database, which is strictly worse than the stale one it
     * started with.
     */
    public function testAFailedInstallPutsTheOriginalBack(): void {

        $destination = $this->dir . 'GeoLite2-City.mmdb';
        file_put_contents( $destination, 'THE-ONLY-DATABASE' );

        // A source that does not exist, so the second rename cannot succeed.
        $result = $this->installDatabase( $this->dir . 'no-such-file.mmdb', $destination );

        $this->assertIsString( $result, 'the failure must be reported' );
        $this->assertStringContainsString( 'still in place', $result );

        $this->assertFileExists( $destination,
            'a failed update must never leave an installation with no database' );
        $this->assertSame( 'THE-ONLY-DATABASE', file_get_contents( $destination ) );
    }

    public function testAFailedFirstInstallReportsWithoutClaimingARestore(): void {

        $destination = $this->dir . 'GeoLite2-City.mmdb';

        $result = $this->installDatabase( $this->dir . 'no-such-file.mmdb', $destination );

        $this->assertIsString( $result );
        $this->assertStringNotContainsString( 'still in place', $result,
            'there was no database to keep, so the message must not say one survived' );
        $this->assertFileDoesNotExist( $destination );
    }
}
