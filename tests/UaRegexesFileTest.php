<?php

use PHPUnit\Framework\TestCase;

/**
 * Where the user-agent patterns come from: the ua-regexes setting, else an
 * override in owa-data/conf/ua-parser/ (Core\Overrides), else the copy that
 * ships with the release. The override is PHP the cron user runs, so it is
 * used only when nobody but the owner of OWA's files can write it and the
 * directories above it. There is no command that downloads them.
 *
 * Run against a temporary data directory, so a real override on the machine
 * running the tests is neither required nor disturbed.
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

    /** An override in conf/ is used with nothing to configure. */
    public function testAMaintainedFileIsPreferredOverTheBundledCopy(): void {

        $this->withMaintainedFile( function () {

            $this->assertSame(
                $this->file,
                \OWA\Module\Base\Classes\Browscap::regexesFile( $this->data ),
                'an override must be picked up without configuration'
            );
        } );
    }

    /** cmd=instance-info reports on the file the parser uses. */
    public function testInstanceInfoReportsTheFileInConf(): void {

        $this->withMaintainedFile( function () {

            $report = $this->instanceInfoFreshness();

            $this->assertStringContainsString( $this->file . ', updated ' . date( 'Y-m-d' ), $report );
            $this->assertStringNotContainsString( 'the copy that ships with this release', $report );
        } );
    }

    /** A file at the old location is not used, so instance-info says the bundled copy is. */
    public function testInstanceInfoIgnoresTheOldLocation(): void {

        $old = umask( 022 );
        mkdir( $this->data . 'ua-parser', 0755 );
        file_put_contents( $this->data . 'ua-parser/regexes.php', "<?php return ['user_agent_parsers' => []];" );
        umask( $old );

        $this->assertStringContainsString( 'the copy that ships with this release', $this->instanceInfoFreshness() );
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

    public function testTheBundledCopyIsUsedWithoutAnOverride(): void {

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

    /** The download command is gone, under its 2.0 name and its 1.x one. */
    public function testThereIsNoDownloadCommand(): void {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->loadCliCommands();

        foreach ( [ 'ua-update-definitions', 'update-ua-regexes' ] as $command ) {

            $this->assertEmpty( $s->getCliCommandClass( $command ), $command );
            $this->assertArrayNotHasKey( $command, \OWA\Core\Controller\Cli::RENAMED );
        }

        $this->assertNull( $s->getMapValue( 'actions', 'base.uaUpdateDefinitionsCli' ) ?: null );
        $this->assertFalse( class_exists( 'OWA\\Module\\Base\\Controller\\UaUpdateDefinitionsCli' ) );
    }

    public function testItsDirectorySettingIsRetired(): void {

        $this->assertNull( \OWA\Core\CoreAPI::configSingleton()->registeredField( 'base', 'ua_regexes_dir' ) ?: null );
        $this->assertArrayHasKey( 'OWA_UA_REGEXES_DIR', \OWA\Core\Requirements::RETIRED_CONSTANTS );
    }

    /**
     * The file in use, when it is not the bundled copy, must be loadable by
     * the parser.
     */
    public function testAPatternsFileInUseParses(): void {

        $installed = \OWA\Module\Base\Classes\Browscap::regexesFile();

        if ( $installed === null ) {
            $this->markTestSkipped( 'no patterns file of its own in use on this installation' );
        }

        $parser = UAParser\Parser::create( $installed );
        $result = $parser->parse( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );

        $this->assertSame( 'Spider', $result->device->family,
            'the patterns in use must still classify a known crawler' );
    }
}
