<?php

use PHPUnit\Framework\TestCase;

/**
 * Every root-level tooling file is kept out of the release tarball.
 *
 * The exclude list in .github/workflows/main.yml named its paths one at a time,
 * so a config file added to the repository root shipped to users unless someone
 * remembered to extend the list. Nobody did for
 * playwright.install-nostash.config.js, which went out in the 1.13.0 tarball --
 * inert, because tests/ is excluded and nothing in an installation reads it, but
 * not part of a release either.
 *
 * This asserts the list covers what is in the tree now, rather than that it
 * contains any particular string: a pattern rewritten from an exact path to a
 * glob still passes, and a new config file dropped in the root still fails.
 */
final class ReleaseTarballExcludesTest extends TestCase {

    /**
     * Root-level files that are build or test tooling rather than part of an
     * installation. Globs, because the point is to catch the next one added.
     */
    private const TOOLING = [
        'playwright*.config.js',
        'jest.config.js',
        'babel.config.js',
        'webpack.config.js',
        'phpunit.xml',
        'phpstan*.neon',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
    ];

    private function root(): string {

        return dirname( __DIR__ );
    }

    /**
     * The --exclude patterns from the packaging step.
     *
     * @return string[]
     */
    private function excludes(): array {

        $yml = (string) file_get_contents( $this->root() . '/.github/workflows/main.yml' );

        $line = '';

        foreach ( preg_split( '/\R/', $yml ) as $candidate ) {

            if ( strpos( $candidate, 'tar --directory' ) !== false ) {

                $line = $candidate;
                break;
            }
        }

        $this->assertNotSame( '', $line,
            'the packaging step must still be a tar command this test can read' );

        preg_match_all( "/--exclude='([^']+)'/", $line, $matches );

        $this->assertNotEmpty( $matches[1],
            'the packaging step must still carry an exclude list' );

        return $matches[1];
    }

    public function testEveryRootToolingFileIsExcluded(): void {

        $excludes = $this->excludes();

        $found = [];

        foreach ( self::TOOLING as $pattern ) {

            foreach ( glob( $this->root() . '/' . $pattern ) ?: [] as $path ) {

                // Archive members are written relative to the root, as './name'.
                $found[] = './' . basename( $path );
            }
        }

        // Asserted before the loop: with an empty list the loop would run no
        // assertions at all and the test would pass by not looking.
        $this->assertNotEmpty( $found,
            'no tooling files found in the repository root -- this test has stopped testing anything' );

        foreach ( $found as $member ) {

            $covered = false;

            foreach ( $excludes as $pattern ) {

                // fnmatch() without FNM_PATHNAME lets '*' cross a slash, which
                // is how tar's --exclude patterns behave too.
                if ( fnmatch( $pattern, $member ) ) {

                    $covered = true;
                    break;
                }
            }

            $this->assertTrue( $covered,
                "$member would ship in the release tarball; add it to the exclude list in .github/workflows/main.yml" );
        }
    }
}
