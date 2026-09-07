<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * An unbuilt download must SAY what is missing, not answer with a blank 500.
 *
 * WHY IT MATTERS
 * A GitHub release page offers three downloads: the packaged tarball, which
 * carries vendor/ and public/, and the two "Source code" archives, which carry
 * neither and cannot be run as they are. People take the source archive, and
 * what OWA did about it was nothing visible -- every entry point, install.php
 * included, answered 500 with an empty body:
 *
 *   PHP Fatal error: Uncaught Error: Class "OWA\Core\Caller" not found in owa.php:19
 *
 * OWA's own classes load through Composer's PSR-4 map, so with no vendor/ the
 * autoloader that would find them is the thing that is missing. The installer
 * has had a "Dependencies: missing -- run composer install" check the whole
 * time (modules/Base/Controller/InstallCheckEnv.php); it could never run,
 * because reaching it needed the autoloader it was there to ask about.
 *
 * Three things had to be true for that screen to render, and this test pins all
 * three, because each fails silently and none is covered by booting normally:
 *
 *   1. owa_env.php registers a fallback PSR-4 autoloader for OWA's own classes.
 *   2. Nothing on the boot path REQUIRES a Composer package. Monolog was the
 *      only one -- constructed eagerly in Error::__construct() -- and is now
 *      built lazily. A single new `use` of a vendor class on this path puts the
 *      blank 500 back, which is exactly what this test is here to catch.
 *   3. The environment check screen renders at all. It reads $view->checks, and
 *      the view forwarded only $view->errors, so ANY failing check was a fatal.
 *
 * HOW IT IS RUN
 * OWA_PATH is pre-defined to a directory of symlinks holding everything the
 * real tree has EXCEPT vendor/ and public/. owa_env.php derives every other
 * path from OWA_PATH and only falls back to __DIR__ when it is not already
 * defined, so the installer boots believing it lives somewhere with no
 * dependencies and no built assets -- without copying the tree or touching it.
 */
final class SourceCheckoutInstallTest extends TestCase
{
    /**
     * What a source checkout does NOT have, plus what this fake root supplies
     * itself. Everything else in the repository root is linked in as-is, rather
     * than listed here: a list of what to include goes stale silently the next
     * time the tree gains a directory, and the failure would look like this
     * test finding a bug.
     */
    private const OMITTED = [
        '.', '..', '.git',
        'vendor', 'public',          // the two the packaged tarball carries
        'node_modules', 'test-results', 'playwright-report', '.phpunit.cache',
        'owa-data',                  // created for real below
        'owa-config.php',            // a fresh install has none
    ];

    private string $fake = '';

    private function root(): string
    {
        return dirname(__DIR__);
    }

    protected function setUp(): void
    {
        $this->fake = sys_get_temp_dir() . '/owa-src-checkout-' . getmypid() . '-' . uniqid();

        if ( ! mkdir( $this->fake, 0777, true ) ) {
            $this->fail( 'could not create the fake install root' );
        }

        $entries = scandir( $this->root() );

        foreach ( $entries as $entry ) {

            if ( in_array( $entry, self::OMITTED, true ) ) {

                continue;
            }

            symlink( $this->root() . '/' . $entry, $this->fake . '/' . $entry );
        }

        // The pieces the installer must find, whatever else the tree holds.
        foreach ( [ 'Core', 'modules', 'owa.php', 'owa_env.php' ] as $needed ) {

            $this->assertFileExists( $this->fake . '/' . $needed,
                $needed . ' must be present for the installer to boot' );
        }

        // Real directories: the installer checks these are writable, and the
        // logger would write into them if it had a logger to write with.
        mkdir( $this->fake . '/owa-data/logs', 0777, true );
        mkdir( $this->fake . '/owa-data/caches', 0777, true );
    }

    protected function tearDown(): void
    {
        if ( $this->fake && is_dir( $this->fake ) ) {
            exec( 'rm -rf ' . escapeshellarg( $this->fake ) );
        }
    }

    /**
     * Run install.php as the installer, from a root with no vendor/ or public/.
     */
    private function runInstaller( string $do ): string
    {
        $script = sprintf(
            'define("OWA_PATH", %s);' .
            '$_GET["do"] = %s;' .
            '$_SERVER["REQUEST_METHOD"] = "GET";' .
            '$_SERVER["SCRIPT_NAME"] = "/install.php";' .
            '$_SERVER["HTTP_HOST"] = "localhost";' .
            '$_SERVER["SERVER_NAME"] = "localhost";' .
            '$_SERVER["SERVER_PORT"] = "80";' .
            '$_SERVER["REQUEST_URI"] = "/install.php";' .
            'include %s;',
            var_export( $this->fake, true ),
            var_export( $do, true ),
            var_export( $this->root() . '/install.php', true )
        );

        return (string) shell_exec(
            escapeshellarg( PHP_BINARY ) . ' -d error_reporting=E_ALL -r ' . escapeshellarg( $script ) . ' 2>&1'
        );
    }

    public function testTheInstallerBootsWithoutVendorOrPublic(): void
    {
        $this->assertDirectoryDoesNotExist( $this->fake . '/vendor' );
        $this->assertDirectoryDoesNotExist( $this->fake . '/public' );

        $out = $this->runInstaller( 'base.installStart' );

        $this->assertStringNotContainsString( 'Fatal error', $out,
            'install.php must not fatal on a source checkout; got: ' . substr( $out, 0, 400 ) );

        $this->assertStringNotContainsString( 'Class "OWA\Core\Caller" not found', $out,
            'the fallback PSR-4 autoloader in owa_env.php is not registering' );

        $this->assertStringContainsString( 'Install Open Web Analytics', $out,
            'the installer should render its start screen' );
    }

    /**
     * The screen must NAME both problems. This is the whole point: a person who
     * downloaded the wrong archive has to be told which command to run.
     */
    public function testTheEnvironmentCheckNamesBothMissingPieces(): void
    {
        $out = $this->runInstaller( 'base.installCheckEnv' );

        $this->assertStringNotContainsString( 'Fatal error', $out,
            'the environment check must render, not fatal; got: ' . substr( $out, 0, 400 ) );

        $this->assertStringContainsString( 'Dependencies', $out );
        $this->assertStringContainsString( 'composer install', $out,
            'the missing vendor/ must be reported with its remedy' );

        $this->assertStringContainsString( 'Built assets', $out );
        $this->assertStringContainsString( 'npm run build', $out,
            'the missing public/ must be reported with its remedy' );
    }

    /**
     * Every check is listed, not just the failures -- which is what $view->checks
     * carries. If the view stops forwarding it the screen fatals, so assert on a
     * check that PASSES: its presence proves the full list rendered.
     */
    public function testTheScreenListsPassingChecksToo(): void
    {
        $out = $this->runInstaller( 'base.installCheckEnv' );

        $this->assertStringContainsString( 'PHP version', $out,
            '$view->checks is not reaching the template' );

        $this->assertStringContainsString( 'Database driver', $out );
    }

    /**
     * With no public/ there is no logo file, and an <img> pointing at one is a
     * broken image on every install screen. It must degrade to text instead.
     */
    public function testTheLogoDegradesToTextWhenAssetsAreNotBuilt(): void
    {
        $out = $this->runInstaller( 'base.installCheckEnv' );

        $this->assertStringNotContainsString( 'owa-logo-100w.png', $out,
            'the logo <img> must not be emitted when public/ was never built' );

        $this->assertStringContainsString( 'Open Web Analytics', $out,
            'the name should still appear, as text' );
    }
}
