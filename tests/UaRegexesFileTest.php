<?php

use PHPUnit\Framework\TestCase;

/**
 * Where the user-agent patterns come from, and why it is not the bundled copy.
 *
 * The patterns live in uap-core, a different project from the PHP library that
 * reads them. uap-core updates roughly monthly and has tagged no release since
 * 2019, so consumers follow its master branch. The PHP library bundles whatever
 * copy existed when ITS maintainers last cut a release -- July 2025.
 *
 * So the bundled patterns are as old as the library's last release however new
 * the library is, and bumping the composer constraint does not help: there is
 * nothing newer to bump to. cmd=update-ua-regexes replaces them.
 *
 * It writes to owa-data/ua-parser/, outside the source tree, which survives an
 * upgrade -- the same arrangement the Maxmind module uses for its database, for
 * the same reason. Writing inside vendor/ would last until the next composer
 * install.
 */
final class UaRegexesFileTest extends TestCase {

    /** @var string */
    private $dir;

    /** @var string */
    private $file;

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void {

        $this->dir  = OWA_DATA_DIR . 'ua-parser/';
        $this->file = $this->dir . 'regexes.php';
    }

    /**
     * Runs against a temporary copy so a real refreshed file on the machine
     * running the tests is neither required nor disturbed.
     */
    private function withMaintainedFile( callable $test ): void {

        $existed = file_exists( $this->file );
        $backup  = $existed ? file_get_contents( $this->file ) : null;

        if ( ! is_dir( $this->dir ) ) {
            mkdir( $this->dir, 0755, true );
        }

        file_put_contents( $this->file, "<?php return ['user_agent_parsers' => []];" );

        try {
            $test();
        } finally {
            if ( $existed ) {
                file_put_contents( $this->file, $backup );
            } else {
                @unlink( $this->file );
            }
        }
    }

    /**
     * The point of the change: a refreshed file is used with nothing to
     * configure. Requiring a setting as well would mean most installations
     * refreshed the patterns and carried on using the old ones.
     */
    public function testAMaintainedFileIsPreferredOverTheBundledCopy(): void {

        $this->withMaintainedFile( function () {

            $this->assertSame(
                $this->file,
                \OWA\Module\Base\Classes\Browscap::regexesFile(),
                'a refreshed file must be picked up without configuration'
            );
        } );
    }

    public function testTheBundledCopyIsUsedWhenNothingHasBeenRefreshed(): void {

        if ( file_exists( $this->file ) ) {
            $this->markTestSkipped( 'this installation has a refreshed file in place' );
        }

        $this->assertNull(
            \OWA\Module\Base\Classes\Browscap::regexesFile(),
            'with nothing installed the library falls back to its own copy'
        );
    }

    /**
     * Readable, not merely present. An unreadable file taking precedence would
     * break user-agent parsing entirely in favour of a copy that works.
     */
    public function testAnUnreadableFileIsIgnoredRatherThanPreferred(): void {

        $this->withMaintainedFile( function () {

            if ( ! @chmod( $this->file, 0000 ) || is_readable( $this->file ) ) {
                $this->markTestSkipped( 'cannot make a file unreadable as this user' );
            }

            $resolved = \OWA\Module\Base\Classes\Browscap::regexesFile();

            chmod( $this->file, 0644 );

            $this->assertNull( $resolved, 'an unreadable file must not shadow a working one' );
        } );
    }

    public function testTheRefreshCommandIsRegistered(): void {

        $module = \OWA\Core\CoreAPI::moduleClassFactory( 'base', 'Module' );

        $property = new ReflectionProperty( $module, 'cli_commands' );
        $property->setAccessible( true );

        $this->assertArrayHasKey(
            'update-ua-regexes',
            (array) $property->getValue( $module ),
            'the command must be reachable as cli.php cmd=update-ua-regexes'
        );
    }

    /**
     * The action name has to resolve to the class. Controllers are reached
     * through the legacy alias map, so a new one that is not registered there
     * is a 404 at the moment someone runs it -- which is exactly what happened
     * while writing this.
     */
    public function testTheCommandResolvesToItsClass(): void {

        $this->assertSame(
            \OWA\Module\Base\Controller\UpdateUaRegexesCli::class,
            \OWA\Core\Lib::resolveNamespacedClass( 'owa_updateUaRegexesCliController' ),
            'the action name must resolve, or the command 404s when it is run'
        );
    }

    /**
     * The refreshed file must be loadable by the parser. A truncated download
     * or a failed conversion would otherwise be discovered by every request
     * afterwards rather than by the command that wrote it.
     */
    public function testARefreshedFileParsesIfOneIsPresent(): void {

        if ( ! is_readable( $this->file ) ) {
            $this->markTestSkipped( 'no refreshed patterns file on this installation' );
        }

        $parser = UAParser\Parser::create( $this->file );
        $result = $parser->parse( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );

        $this->assertSame( 'Spider', $result->device->family,
            'the refreshed patterns must still classify a known crawler' );
    }

    private function command() {

        // Without the constructor: the CLI controller base exits unless the
        // process was started by cli.php, and this method does not depend on
        // any of that.
        return ( new ReflectionClass( \OWA\Module\Base\Controller\UpdateUaRegexesCli::class ) )
            ->newInstanceWithoutConstructor();
    }

    private function whyNotWritable( $dir ) {

        $m = new ReflectionMethod( \OWA\Module\Base\Controller\UpdateUaRegexesCli::class, 'whyNotWritable' );
        $m->setAccessible( true );

        return $m->invoke( $this->command(), $dir );
    }

    /**
     * Permissions are checked BEFORE the download.
     *
     * The installer checks owa-data/logs/ and owa-data/caches/, but not
     * owa-data itself -- nothing needed to create a directory there until this
     * command did. So a passing install check does not imply this will work,
     * and it has to ask for itself.
     */
    public function testAWritableDestinationIsAccepted(): void {

        $this->assertNull( $this->whyNotWritable( $this->dir ),
            'owa-data is writable on a working installation, so this must pass' );
    }

    public function testAnUnwritableParentIsRefusedWithTheReasonAndThePath(): void {

        $parent = sys_get_temp_dir() . '/owa-ua-ro-' . getmypid();

        if ( ! @mkdir( $parent, 0500, true ) ) {
            $this->markTestSkipped( 'could not create a read-only directory' );
        }

        if ( is_writable( $parent ) ) {
            rmdir( $parent );
            $this->markTestSkipped( 'running as a user that ignores directory permissions' );
        }

        // A directory that does not exist, inside a parent that cannot be
        // written -- the case where mkdir would fail.
        $problem = $this->whyNotWritable( $parent . '/ua-parser/' );

        chmod( $parent, 0700 );
        rmdir( $parent );

        $this->assertNotNull( $problem, 'an uncreatable destination must be refused' );
        $this->assertStringContainsString( 'writable', $problem,
            'the message must say what is wrong, not just that something failed' );
    }

    /**
     * The awkward case in practice: the directory is fine but the file was
     * written by a different user, so the refresh cannot replace it. A generic
     * permissions message would send someone to chmod the wrong thing.
     */
    public function testAnUnwritableExistingFileIsReportedSeparately(): void {

        $this->withMaintainedFile( function () {

            if ( ! @chmod( $this->file, 0400 ) || is_writable( $this->file ) ) {
                $this->markTestSkipped( 'cannot make a file read-only as this user' );
            }

            $problem = $this->whyNotWritable( $this->dir );

            chmod( $this->file, 0644 );

            $this->assertNotNull( $problem, 'an unreplaceable file must be refused' );
            $this->assertStringContainsString( 'regexes.php', $problem,
                'the message must name the file, since that is what needs fixing' );
        } );
    }
}
