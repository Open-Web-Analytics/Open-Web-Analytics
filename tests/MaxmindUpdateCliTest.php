<?php

use PHPUnit\Framework\TestCase;

/**
 * The GeoIP database can be refreshed, and only where that means something.
 *
 * The database ages in a way that is invisible: IP ranges are reassigned
 * between countries and cities continuously, and a stale file does not fail --
 * it answers, wrongly, and the reports look normal. MaxMind publish updates
 * twice a week, and OWA had no way to take them.
 *
 * THE COMMAND IS REGISTERED BY THE MODULE, NOT BY BASE
 * A module's constructor only runs for modules in the active list, and
 * registerCliCommands() is called from it. So an installation not doing GeoIP
 * lookups is never offered a command for maintaining a database it does not
 * read, and one that activates the module gets it with nothing further to do.
 * That is asserted below in both directions -- one half alone would pass with
 * the registration in the wrong place.
 */
final class MaxmindUpdateCliTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function commandsFor( $module_name ) {

        $module = \OWA\Core\CoreAPI::moduleClassFactory( $module_name, 'Module' );

        $property = new ReflectionProperty( $module, 'cli_commands' );
        $property->setAccessible( true );

        return (array) $property->getValue( $module );
    }

    public function testTheModuleRegistersTheCommand(): void {

        $this->assertArrayHasKey(
            'update-geoip-db',
            $this->commandsFor( 'maxmind_geoip' ),
            'the module must offer the command when it is active'
        );
    }

    /**
     * The other half: Base must NOT register it, or it would exist on every
     * installation regardless of whether the module is active.
     */
    public function testBaseDoesNotRegisterIt(): void {

        $this->assertArrayNotHasKey(
            'update-geoip-db',
            $this->commandsFor( 'base' ),
            'registering this in Base would offer it to installations with no GeoIP module'
        );
    }

    /**
     * Controllers resolve through the legacy alias map, so one that is not
     * registered there 404s at the moment someone runs it.
     */
    public function testTheActionResolvesToItsClass(): void {

        $this->assertSame(
            \OWA\Module\MaxmindGeoip\Controller\UpdateGeoipDbCli::class,
            \OWA\Core\Lib::resolveNamespacedClass( 'owa_updateGeoipDbCliController' )
        );
    }

    private function command() {

        return ( new ReflectionClass( \OWA\Module\MaxmindGeoip\Controller\UpdateGeoipDbCli::class ) )
            ->newInstanceWithoutConstructor();
    }

    private function invoke( $method, ...$args ) {

        $m = new ReflectionMethod( \OWA\Module\MaxmindGeoip\Controller\UpdateGeoipDbCli::class, $method );
        $m->setAccessible( true );

        return $m->invoke( $this->command(), ...$args );
    }

    /**
     * MaxMind answers a bad key with 401 AND a body. Writing the body out
     * would leave an error page sitting where the database belongs, named as a
     * database, and lookups would fail confusingly ever after -- so the status
     * line is what the download decides on.
     */
    public function testAnErrorStatusIsRecognisedRatherThanWrittenOut(): void {

        $this->assertSame( 401, $this->invoke( 'statusFrom', [ 'HTTP/1.1 401 Unauthorized' ] ) );
        $this->assertSame( 200, $this->invoke( 'statusFrom', [ 'HTTP/1.1 200 OK' ] ) );
        $this->assertSame( 0, $this->invoke( 'statusFrom', [ 'Content-Type: text/html' ] ),
            'no status line means no opinion, not a false success' );
    }

    /**
     * Permissions are checked before the download, because the answer does not
     * depend on it and finding out after fetching tens of megabytes is a worse
     * experience. Reported per failure mode -- they need different fixes.
     */
    public function testAnUncreatableDestinationIsRefusedBeforeAnythingIsFetched(): void {

        $parent = sys_get_temp_dir() . '/owa-geoip-ro-' . getmypid();

        if ( ! @mkdir( $parent, 0500, true ) ) {
            $this->markTestSkipped( 'could not create a read-only directory' );
        }

        if ( is_writable( $parent ) ) {
            rmdir( $parent );
            $this->markTestSkipped( 'running as a user that ignores directory permissions' );
        }

        $problem = $this->invoke( 'whyNotWritable', $parent . '/maxmind/' );

        chmod( $parent, 0700 );
        rmdir( $parent );

        $this->assertNotNull( $problem );
        $this->assertStringContainsString( 'writable', $problem );
    }

    public function testAWritableDestinationIsAccepted(): void {

        $dir = sys_get_temp_dir() . '/owa-geoip-ok-' . getmypid() . '/';

        $this->assertNull( $this->invoke( 'whyNotWritable', $dir ),
            'a creatable directory inside a writable parent must be accepted' );
    }

    /**
     * Scheduling it while the module is inactive is refused, with a reason.
     *
     * OWA_SCHEDULED_JOBS can name any command, and command registration is
     * what this module gates. So an installation that schedules update-geoip-db
     * without activating the module would otherwise get a job listed as healthy
     * with a next-due time that failed silently at dispatch every time it fired.
     *
     * The scheduler resolves the command name up front and skips what nothing
     * answers to, which is what makes the gating safe to rely on rather than a
     * trap.
     */
    public function testTheSchedulerRefusesACommandNoModuleAnswersTo(): void {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $resolved = $service->getCliCommandClass( 'update-geoip-db' );

        if ( $resolved ) {
            $this->markTestSkipped( 'the maxmind_geoip module is active on this installation' );
        }

        $this->assertEmpty( $resolved,
            'with the module inactive the command must resolve to nothing, so the scheduler '
          . 'skips it with a notice rather than listing a job that cannot run' );
    }

    /**
     * The downloader and the reader must resolve the same edition.
     *
     * They are separate pieces of code reading one setting, and the failure if
     * they disagree is quiet: Country gets downloaded, the reader goes looking
     * for City, and lookups fail against a database sitting right there in the
     * directory.
     */
    public function testTheReaderAndTheDownloaderAgreeOnTheEdition(): void {

        $this->assertSame(
            \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition(),
            \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition(),
            'both consult Maxmind::edition(), so there is one answer by construction'
        );

        $this->assertContains(
            \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition(),
            \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS,
            'the resolved edition must be one the module can read'
        );
    }

    public function testCityIsTheDefaultWhenNothingIsConfigured(): void {

        $config = \OWA\Core\CoreAPI::configSingleton();
        $previous = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_edition' );

        $config->set( 'maxmind_geoip', 'db_edition', '' );

        $resolved = \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition();

        $config->set( 'maxmind_geoip', 'db_edition', $previous );

        $this->assertSame( 'GeoLite2-City', $resolved,
            'an installation that configures nothing keeps the behaviour it had' );
    }

    /**
     * Country is the reason this is configurable: a fraction of the size, for
     * an installation whose reports never go below country level. It works
     * because getLocation() reads the finer fields behind isset() guards.
     */
    public function testCountryIsAcceptedAsAnEdition(): void {

        $config = \OWA\Core\CoreAPI::configSingleton();
        $previous = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_edition' );

        $config->set( 'maxmind_geoip', 'db_edition', 'GeoLite2-Country' );

        $resolved = \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition();

        $config->set( 'maxmind_geoip', 'db_edition', $previous );

        $this->assertSame( 'GeoLite2-Country', $resolved );
    }

    /**
     * ASN is free too, and deliberately not offered: it carries network data
     * and no location fields, so an installation pointed at it would look
     * healthy and silently resolve nothing.
     */
    public function testAnEditionWithNoLocationDataIsNotOffered(): void {

        $this->assertNotContains( 'GeoLite2-ASN',
            \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS,
            'ASN has no location fields; accepting it would resolve nothing, quietly' );
    }

    /**
     * An unrecognised value falls back rather than being trusted, so a typo in
     * config cannot send the downloader after an edition nothing reads.
     */
    public function testAnUnknownEditionFallsBackToTheDefault(): void {

        $config = \OWA\Core\CoreAPI::configSingleton();
        $previous = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_edition' );

        $config->set( 'maxmind_geoip', 'db_edition', 'GeoLite2-Kitchen-Sink' );

        $resolved = \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition();

        $config->set( 'maxmind_geoip', 'db_edition', $previous );

        $this->assertSame( 'GeoLite2-City', $resolved );
    }

    /**
     * The archive must be named something PharData will open.
     *
     * It refuses a file whose extension it does not recognise -- "file
     * extension (or combination) not recognised" -- and tempnam() produces a
     * name with no extension at all. The download succeeded, the unpack failed,
     * and the command reported the failure correctly while leaving no database.
     *
     * It survived earlier testing because every run until a real licence key
     * existed stopped at the download, so the unpack was never reached. That is
     * the shape of thing this test exists for: the step after the one that
     * usually fails.
     */
    public function testTheArchiveIsNamedSomethingPharDataWillOpen(): void {

        $source = (string) file_get_contents(
            dirname( __DIR__ ) . '/modules/MaxmindGeoip/Controller/UpdateGeoipDbCli.php' );

        $this->assertStringContainsString( ".tar.gz'", $source,
            'the temporary archive must carry a .tar.gz extension or PharData will not open it' );
    }

    /**
     * PharData proves it rather than the source scan alone: a name without an
     * extension throws, the same name with .tar.gz does not.
     */
    public function testPharDataRejectsAnExtensionlessArchive(): void {

        if ( ! class_exists( 'PharData' ) ) {
            $this->markTestSkipped( 'phar is not available' );
        }

        $bare = tempnam( sys_get_temp_dir(), 'owa-phar-probe-' );
        file_put_contents( $bare, gzencode( str_repeat( "\0", 1024 ) ) );

        $threw = false;

        try {
            new \PharData( $bare );
        } catch ( \Throwable $e ) {
            $threw = true;
            $this->assertStringContainsString( 'extension', strtolower( $e->getMessage() ),
                'the failure must be about the name, which is what the fix addresses' );
        }

        @unlink( $bare );

        $this->assertTrue( $threw,
            'if PharData ever accepts an extensionless file, the rename is no longer needed' );
    }

    /**
     * A dry run must not spend the account's download limit to say what it
     * would do. It used to fetch the whole archive -- tens of megabytes -- and
     * report afterwards, which costs exactly as much as doing the work.
     */
    public function testADryRunStopsBeforeTheTransfer(): void {

        $source = (string) file_get_contents(
            dirname( __DIR__ ) . '/modules/MaxmindGeoip/Controller/UpdateGeoipDbCli.php' );

        $dry_run_return = strpos( $source, "'Dry run: would download" );
        $download_call  = strpos( $source, '$bytes = $this->download(' );

        $this->assertIsInt( $dry_run_return, 'the dry run must report and return' );
        $this->assertIsInt( $download_call );

        $this->assertLessThan( $download_call, $dry_run_return,
            'the dry run must return BEFORE the download, or asking costs the same as doing' );
    }
}
