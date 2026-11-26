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
 * nothing newer to bump to. cmd=ua-update-definitions replaces them.
 *
 * It writes to owa-data/conf/ua-parser/ (Core\Overrides), which updates leave
 * alone. Writing inside vendor/ would last until the next composer install.
 * The file is PHP the cron user runs, so it is used only when nobody but the
 * owner of OWA's files can write it and the directories above it.
 *
 * Run against a temporary data directory, so a real refreshed file on the
 * machine running the tests is neither required nor disturbed.
 */
final class UaRegexesFileTest extends TestCase {

    /** @var string the temporary data directory */
    private $data;

    /** @var string */
    private $dir;

    /** @var string */
    private $file;

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void {

        $this->data = sys_get_temp_dir() . '/owa-ua-data-' . bin2hex( random_bytes( 4 ) ) . '/';
        $this->dir  = $this->data . 'conf/ua-parser/';
        $this->file = $this->dir . 'regexes.php';

        $old = umask( 022 );
        mkdir( $this->dir, 0755, true );
        umask( $old );
    }

    protected function tearDown(): void {

        @chmod( $this->file, 0644 );
        @unlink( $this->file );
        @rmdir( $this->dir );
        @rmdir( $this->data . 'conf' );
        @unlink( $this->data . 'ua-parser/regexes.php' );
        @rmdir( $this->data . 'ua-parser' );
        @rmdir( $this->data );
    }

    private function withMaintainedFile( callable $test ): void {

        $old = umask( 022 );
        file_put_contents( $this->file, "<?php return ['user_agent_parsers' => []];" );
        umask( $old );

        $test();
    }

    /**
     * A refreshed file is used with nothing to configure. Requiring a setting as well would mean most installations
     * refreshed the patterns and carried on using the old ones.
     */
    public function testAMaintainedFileIsPreferredOverTheBundledCopy(): void {

        $this->withMaintainedFile( function () {

            $this->assertSame(
                $this->file,
                \OWA\Module\Base\Classes\Browscap::regexesFile( $this->data ),
                'a refreshed file must be picked up without configuration'
            );
        } );
    }

    /** cmd=instance-info reports on the file the parser uses: where ua-update-definitions writes it. */
    public function testInstanceInfoReportsTheFileInConf(): void {

        $this->withMaintainedFile( function () {

            $report = $this->instanceInfoFreshness();

            $this->assertStringContainsString( 'updated ' . date( 'Y-m-d' ), $report );
            $this->assertStringNotContainsString( 'never updated', $report );
        } );
    }

    /** A file at the old location is not used, so instance-info says the bundled copy is. */
    public function testInstanceInfoIgnoresTheOldLocation(): void {

        $old = umask( 022 );
        mkdir( $this->data . 'ua-parser', 0755 );
        file_put_contents( $this->data . 'ua-parser/regexes.php', "<?php return ['user_agent_parsers' => []];" );
        umask( $old );

        $this->assertStringContainsString( 'never updated (using the bundled copy)', $this->instanceInfoFreshness() );
    }

    private function instanceInfoFreshness(): string {

        $cli = ( new ReflectionClass( \OWA\Module\Base\Controller\InstanceInfoCli::class ) )
            ->newInstanceWithoutConstructor();

        $rows = ( new ReflectionMethod( $cli, 'freshness' ) )->invoke( $cli, $this->data );

        return implode( "\n", array_filter( (array) $rows, static function ( $row ) {

            return strpos( (string) $row, 'User-agent patterns' ) !== false;
        } ) );
    }

    /** One the web server could have written is not run: the bundled copy is used. */
    public function testAFileOthersCanWriteIsNotUsed(): void {

        $this->withMaintainedFile( function () {

            chmod( $this->dir, 0775 );

            $this->assertNull( \OWA\Module\Base\Classes\Browscap::regexesFile( $this->data ) );

            chmod( $this->dir, 0755 );
            chmod( $this->file, 0666 );

            $this->assertNull( \OWA\Module\Base\Classes\Browscap::regexesFile( $this->data ) );
        } );
    }

    /** Where 2.0's first builds and 1.x put it, the data directory itself, it is no longer read. */
    public function testTheOldLocationIsNotRead(): void {

        mkdir( $this->data . 'ua-parser', 0755 );
        file_put_contents( $this->data . 'ua-parser/regexes.php', "<?php return ['user_agent_parsers' => []];" );

        $this->assertNull( \OWA\Module\Base\Classes\Browscap::regexesFile( $this->data ) );
        $this->assertContains( $this->data . 'ua-parser/regexes.php', \OWA\Core\Overrides::misplaced( $this->data ) );
    }

    public function testTheBundledCopyIsUsedWhenNothingHasBeenRefreshed(): void {

        $this->assertNull(
            \OWA\Module\Base\Classes\Browscap::regexesFile( $this->data ),
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

            $resolved = \OWA\Module\Base\Classes\Browscap::regexesFile( $this->data );

            chmod( $this->file, 0644 );

            $this->assertNull( $resolved, 'an unreadable file must not shadow a working one' );
        } );
    }

    public function testTheRefreshCommandIsRegistered(): void {

        $module = \OWA\Core\CoreAPI::moduleClassFactory( 'base', 'Module' );

        $property = new ReflectionProperty( $module, 'cli_commands' );

        $this->assertArrayHasKey(
            'ua-update-definitions',
            (array) $property->getValue( $module ),
            'the command must be reachable as cli.php cmd=ua-update-definitions'
        );
    }

    /**
     * The action name has to resolve to the class. This asks the ACTION REGISTRY,
     * which is where a registered action's class actually comes from -- not the
     * alias map, which would answer for a name that is merely listed and stay
     * silent for a class that loads perfectly well. It still catches what it was
     * written for: an action that 404s the moment someone runs it.
     */
    public function testTheCommandResolvesToItsClass(): void {

        $this->assertSame(
            \OWA\Module\Base\Controller\UaUpdateDefinitionsCli::class,
            ( \OWA\Core\CoreAPI::serviceSingleton()
                ->getMapValue( 'actions', 'base.uaUpdateDefinitionsCli' )['class_name'] ?? null ),
            'the action name must resolve, or the command 404s when it is run'
        );
    }

    /**
     * The refreshed file must be loadable by the parser. A truncated download
     * or a failed conversion would otherwise be discovered by every request
     * afterwards rather than by the command that wrote it.
     */
    public function testARefreshedFileParsesIfOneIsPresent(): void {

        $installed = \OWA\Module\Base\Classes\Browscap::regexesFile();

        if ( $installed === null ) {
            $this->markTestSkipped( 'no refreshed patterns file in use on this installation' );
        }

        $parser = UAParser\Parser::create( $installed );
        $result = $parser->parse( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );

        $this->assertSame( 'Spider', $result->device->family,
            'the refreshed patterns must still classify a known crawler' );
    }

    private function command() {

        // Without the constructor: the CLI controller base exits unless the
        // process was started by cli.php, and this method does not depend on
        // any of that.
        return ( new ReflectionClass( \OWA\Module\Base\Controller\UaUpdateDefinitionsCli::class ) )
            ->newInstanceWithoutConstructor();
    }

    private function whyNotWritable( $dir ) {

        $m = new ReflectionMethod( \OWA\Module\Base\Controller\UaUpdateDefinitionsCli::class, 'whyNotWritable' );

        return $m->invoke( $this->command(), $dir );
    }

    /**
     * Permissions are checked BEFORE the download.
     *
     * The installer checks owa-data/logs/, but not where this command writes.
     * So a passing install check does not imply this will work, and it has to
     * ask for itself.
     */
    public function testAWritableDestinationIsAccepted(): void {

        $this->assertNull( $this->whyNotWritable( $this->dir ) );
        $this->assertNull( $this->whyNotWritable( $this->data . 'conf/not-yet/ua-parser/' ),
            'a missing directory is made, from the nearest one there' );
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
