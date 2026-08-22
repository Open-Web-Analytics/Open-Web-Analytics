<?php

use PHPUnit\Framework\TestCase;

/**
 * The MaxMind reader is a dependency, not a copy in the tree.
 *
 * It was a hand-vendored MaxMind-DB-Reader-php-1.0.3 under the module's
 * includes/, autoloaded by a classmap in the root composer.json. Which worked,
 * and pinned the installation to a 1.0.3 that nothing could update: no version
 * constraint, no lock entry, no way to learn that the package was thirteen
 * minor versions further on.
 *
 * It is now maxmind-db/reader, declared by the module that uses it -- the
 * project merges modules/star/composer.json, so a module's dependencies belong
 * in the module rather than the root.
 *
 * These assertions exist because the migration can be half-undone in either
 * direction and still appear to work locally: a returning classmap would shadow
 * the package, and a returning includes/ directory would ship dead code.
 */
final class MaxmindReaderPackagingTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function root(): string {

        return dirname( __DIR__ );
    }

    public function testTheReaderIsLoadedFromTheComposerPackage(): void {

        $this->assertTrue( class_exists( \MaxMind\Db\Reader::class ),
            'the reader must resolve, or every GeoIP lookup fatals' );

        $file = ( new ReflectionClass( \MaxMind\Db\Reader::class ) )->getFileName();

        $this->assertStringContainsString( 'vendor/maxmind-db/reader', $file,
            'the reader must come from the package, not from a copy inside the module' );
    }

    public function testTheVendoredCopyIsGone(): void {

        $this->assertDirectoryDoesNotExist(
            $this->root() . '/modules/MaxmindGeoip/includes',
            'a returning copy would ship dead code and could shadow the package'
        );
    }

    /**
     * Declared by the module, which is where this project puts a module's
     * dependencies -- wikimedia/composer-merge-plugin merges them from there.
     */
    public function testTheModuleDeclaresTheDependencyItself(): void {

        $file = $this->root() . '/modules/MaxmindGeoip/composer.json';

        $this->assertFileExists( $file, 'the module must declare what it needs' );

        $composer = json_decode( (string) file_get_contents( $file ), true );

        $this->assertArrayHasKey( 'maxmind-db/reader', $composer['require'] ?? [],
            'the reader must be a declared requirement, so it is locked and updatable' );
    }

    /**
     * In require, not require-dev: the release build installs with --no-dev, so
     * a dev dependency would be absent from the tarball and every lookup would
     * fatal on a released installation while working perfectly here.
     */
    public function testTheDependencyShipsInReleaseBuilds(): void {

        $composer = json_decode(
            (string) file_get_contents( $this->root() . '/modules/MaxmindGeoip/composer.json' ), true );

        $this->assertArrayNotHasKey( 'maxmind-db/reader', $composer['require-dev'] ?? [],
            'the release build runs composer install --no-dev' );
    }

    public function testTheRootNoLongerClassmapsTheModule(): void {

        $composer = json_decode(
            (string) file_get_contents( $this->root() . '/composer.json' ), true );

        $classmap = $composer['autoload']['classmap'] ?? [];

        // Asserted on the filtered list rather than in a loop: the classmap is
        // empty now, so a loop would run no assertions at all and the test
        // would pass by not looking.
        $offending = array_values( array_filter( $classmap, static function ( $path ) {
            return strpos( (string) $path, 'MaxmindGeoip' ) !== false;
        } ) );

        $this->assertSame( [], $offending,
            'a classmap over the module would load a copy in preference to the package' );
    }

    /**
     * The narrow surface is why the version jump is safe: OWA uses the
     * constructor and get(), and nothing else.
     */
    public function testTheApiTheModuleUsesIsPresent(): void {

        $reader = new ReflectionClass( \MaxMind\Db\Reader::class );

        $this->assertTrue( $reader->hasMethod( 'get' ),
            'Maxmind::getLocation() calls get() on the reader' );
        $this->assertTrue( $reader->getConstructor() && $reader->getConstructor()->isPublic(),
            'the module constructs the reader with a database path' );
    }
}
