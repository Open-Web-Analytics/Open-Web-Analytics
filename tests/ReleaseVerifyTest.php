<?php

use PHPUnit\Framework\TestCase;

/**
 * .github/scripts/release-verify.sh, which the Release Packager runs before it
 * signs: the tarball holds the release commit's files unchanged, and beyond
 * them only build output. On a small tree packed by release-tarball.sh.
 */
final class ReleaseVerifyTest extends TestCase
{
    private const VERSION = '9.9.9';

    private string $dir;

    protected function setUp(): void {

        $this->dir = sys_get_temp_dir() . '/owa-release-verify-' . bin2hex( random_bytes( 4 ) );

        // The files release-tarball.sh requires in a tarball, and one more.
        foreach ( [ 'updater.php', 'cli.php', 'owa_env.php', 'Core/Env.php', 'modules/Base/Classes/AutoUpdate/Tar.php', 'owa-data/index.php' ] as $f ) {

            @mkdir( dirname( "$this->dir/clean/$f" ), 0755, true );
            file_put_contents( "$this->dir/clean/$f", "<?php // $f\n" );
        }

        // The build: composer's vendor/ and cmd=version-info's release.php.
        exec( sprintf( 'cp -a %s %s', escapeshellarg( "$this->dir/clean" ), escapeshellarg( "$this->dir/built" ) ) );
        mkdir( "$this->dir/built/vendor" );
        file_put_contents( "$this->dir/built/vendor/autoload.php", "<?php\n" );
        file_put_contents( "$this->dir/built/release.php", "<?php exit; ?>\n{}" );
    }

    protected function tearDown(): void {

        exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
    }

    private static function sh( string $cmd, string $cwd ): array {

        exec( 'cd ' . escapeshellarg( $cwd ) . " && $cmd 2>&1", $out, $code );

        return [ $code, implode( "\n", $out ) ];
    }

    private function tarball(): string {

        [ $code, $out ] = self::sh( 'bash ' . escapeshellarg( dirname( __DIR__ ) . '/.github/scripts/release-tarball.sh' ) . ' ' . self::VERSION, "$this->dir/built" );
        $this->assertSame( 0, $code, $out );

        return "$this->dir/built/owa_" . self::VERSION . '_packaged.tar';
    }

    /** A member appended to the tarball under $name. */
    private function append( string $tar, string $name ): void {

        file_put_contents( "$this->dir/extra", "<?php evil();\n" );
        [ $code, $out ] = self::sh( sprintf( 'tar -rf %s --transform %s extra', escapeshellarg( $tar ), escapeshellarg( 's,^extra$,' . $name . ',' ) ), $this->dir );
        $this->assertSame( 0, $code, $out );
    }

    private function verify( string $tar ): array {

        return self::sh( 'bash ' . escapeshellarg( dirname( __DIR__ ) . '/.github/scripts/release-verify.sh' ) . ' ' . self::VERSION . ' ' . escapeshellarg( $tar ), "$this->dir/clean" );
    }

    private function assertRefused( string $why, string $tar ): void {

        [ $code, $out ] = $this->verify( $tar );

        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( "The tarball does not match the release commit: $why", $out );
    }

    public function testTheCommitsFilesAndBuildOutputPass(): void {

        [ $code, $out ] = $this->verify( $this->tarball() );

        $this->assertSame( 0, $code, $out );
        $this->assertStringContainsString( "release commit's 6 files unchanged", $out );
    }

    public function testAChangedFileIsRefused(): void {

        file_put_contents( "$this->dir/built/Core/Env.php", "<?php evil();\n" );

        $this->assertRefused( './Core/Env.php is changed.', $this->tarball() );
    }

    public function testAFileOutsideTheBuildOutputIsRefused(): void {

        file_put_contents( "$this->dir/built/Core/Extra.php", "<?php\n" );

        $this->assertRefused( './Core/Extra.php is not in the release commit.', $this->tarball() );
    }

    public function testAMissingFileIsRefused(): void {

        unlink( "$this->dir/built/owa-data/index.php" );

        $this->assertRefused( './owa-data/index.php is missing.', $this->tarball() );
    }

    public function testASymlinkIsRefused(): void {

        symlink( '../Core/Env.php', "$this->dir/built/vendor/link" );

        $this->assertRefused( 'a member is not a file or a directory', $this->tarball() );
    }

    /** Extracted, the second copy would win. */
    public function testAFileInItTwiceIsRefused(): void {

        $tar = $this->tarball();
        $this->append( $tar, './Core/Env.php' );

        $this->assertRefused( './Core/Env.php is in it twice.', $tar );
    }

    /** Under vendor/ by its prefix, outside it once resolved. */
    public function testANameThatClimbsOutOfTheBuildOutputIsRefused(): void {

        $tar = $this->tarball();
        $this->append( $tar, './vendor/../Core/Extra.php' );

        $this->assertRefused( "a member's name is not a plain path: ./vendor/../Core/Extra.php", $tar );
    }

    /** extensions/ is third-party code, which no release ships: the packager refuses to make such a tarball. */
    public function testThePackagerRefusesExtensions(): void {

        mkdir( "$this->dir/built/extensions/Acme", 0755, true );
        file_put_contents( "$this->dir/built/extensions/Acme/Module.php", "<?php\n" );

        [ $code, $out ] = self::sh( 'bash ' . escapeshellarg( dirname( __DIR__ ) . '/.github/scripts/release-tarball.sh' ) . ' ' . self::VERSION, "$this->dir/built" );

        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( 'The tarball holds extensions/, which is third-party code no release ships', $out );
        $this->assertFileDoesNotExist( "$this->dir/built/owa_" . self::VERSION . '_packaged.tar', 'and leaves none' );
    }

    /** And the check before signing refuses one that holds it. */
    public function testATarballWithExtensionsIsRefused(): void {

        $tar = $this->tarball();
        $this->append( $tar, './extensions/Acme/Module.php' );

        $this->assertRefused( 'it holds ./extensions/Acme/Module.php: extensions/ is third-party code, which no release ships.', $tar );
    }
}
