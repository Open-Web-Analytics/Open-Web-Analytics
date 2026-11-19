<?php

use PHPUnit\Framework\TestCase;

/**
 * The shipped .htaccess grants api/ and public/ only as directories of the
 * install root, the one holding owa_env.php, and the legacy tracker paths only
 * when the request does not map to another existing file.
 *
 * The two <If> expressions are read from the file and evaluated here against
 * the file a request maps to (REQUEST_FILENAME: the last existing path
 * segment, the rest being PATH_INFO), with a list of existing files standing
 * in for -f.
 */
final class HtaccessAllowlistTest extends TestCase
{
    private const ROOT = '/srv/www/owa';

    private const FILES = [
        self::ROOT . '/owa_env.php',
        self::ROOT . '/cli.php',
        self::ROOT . '/owa-config.php',
        self::ROOT . '/api/index.php',
        self::ROOT . '/public/base/dist/owa.tracker.js',
        self::ROOT . '/vendor/acme/lib/api/run.php',
        self::ROOT . '/vendor/acme/lib/public/index.php',
        self::ROOT . '/modules/Base/Module.php',
        // An install root whose own path has a public/ segment.
        '/home/site/public/owa/owa_env.php',
        '/home/site/public/owa/public/base/dist/owa.tracker.js',
        '/home/site/public/owa/cli.php',
    ];

    private static function htaccess(): string
    {
        return (string) file_get_contents(__DIR__ . '/../.htaccess');
    }

    /** @return list<string> the expression of each <If> that grants */
    private static function grants(): array
    {
        preg_match_all('/<If "(.+)">\s*Require all granted\s*<\/If>/', self::htaccess(), $m);

        return $m[1];
    }

    private static function exists(string $path): bool
    {
        return in_array($path, self::FILES, true);
    }

    /** The api/ and public/ grant, evaluated for a request mapped to $filename. */
    private function rootDirectoriesGrant(string $filename): bool
    {
        $found = array_values(array_filter(self::grants(), fn ($e) => strpos($e, 'owa_env.php') !== false && strpos($e, 'api|public') !== false));
        $this->assertCount(1, $found, 'one grant for the root api/ and public/');

        $this->assertSame(1, preg_match(
            "/^\\(%\\{REQUEST_FILENAME\\} =~ m#([^#]+)# && -f '\\$1\\/owa_env\\.php'\\) \\|\\| \\(%\\{REQUEST_FILENAME\\} =~ m#([^#]+)# && -f '\\$1\\/owa_env\\.php'\\)$/",
            $found[0], $shape), 'the grant has the shape evaluated here: ' . $found[0]);

        foreach ([$shape[1], $shape[2]] as $regex) {
            if (preg_match('#' . $regex . '#', $filename, $g) && self::exists($g[1] . '/owa_env.php')) {
                return true;
            }
        }

        return false;
    }

    /** The legacy tracker grant, evaluated for a request for $uri mapped to $filename. */
    private function legacyGrant(string $uri, string $filename): bool
    {
        $found = array_values(array_filter(self::grants(), fn ($e) => strpos($e, 'owa\.tracker') !== false));
        $this->assertCount(1, $found, 'one grant for the legacy tracker paths');

        $this->assertSame(1, preg_match(
            '/^%\{REQUEST_URI\} =~ m#([^#]+)# && \(! -f %\{REQUEST_FILENAME\} \|\| %\{REQUEST_FILENAME\} =~ m#([^#]+)#i\)$/',
            $found[0], $shape), 'the grant has the shape evaluated here: ' . $found[0]);

        return preg_match('#' . $shape[1] . '#', $uri)
            && ( ! self::exists($filename) || preg_match('#' . $shape[2] . '#i', $filename));
    }

    /** @return array<string, array{string, bool}> */
    public static function mappedFiles(): array
    {
        return [
            'a REST URL maps to api/'           => [self::ROOT . '/api', true],
            'api/index.php'                     => [self::ROOT . '/api/index.php', true],
            'a built asset'                     => [self::ROOT . '/public/base/dist/owa.tracker.js', true],
            'public/ itself'                    => [self::ROOT . '/public', true],
            'a script with /public/ PATH_INFO'  => [self::ROOT . '/cli.php', false],
            'the config with /api/ PATH_INFO'   => [self::ROOT . '/owa-config.php', false],
            'a nested api/ directory'           => [self::ROOT . '/vendor/acme/lib/api/run.php', false],
            'a nested public/ directory'        => [self::ROOT . '/vendor/acme/lib/public/index.php', false],
            'a public/ in the root path: asset' => ['/home/site/public/owa/public/base/dist/owa.tracker.js', true],
            'a public/ in the root path: REST'  => ['/home/site/public/owa/api', true],
            'a public/ in the root path: script with PATH_INFO' => ['/home/site/public/owa/cli.php', false],
            'a directory named apis'            => [self::ROOT . '/apis', false],
        ];
    }

    /** @dataProvider mappedFiles */
    public function testApiAndPublicAreGrantedOnlyUnderTheInstallRoot(string $filename, bool $granted): void
    {
        $this->assertSame($granted, $this->rootDirectoriesGrant($filename));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function legacyRequests(): array
    {
        return [
            'the dist entry point'     => ['/owa/modules/base/dist/owa.tracker.js', self::ROOT . '/modules', true],
            'the js entry point'       => ['/owa/modules/base/js/owa.tracker-combined-min.js', self::ROOT . '/modules', true],
            'a 1.x file left in place' => ['/owa/modules/base/dist/owa.tracker.js', self::ROOT . '/modules/base/dist/owa.tracker.js', true],
            'a script with the path as PATH_INFO' => ['/owa/cli.php/modules/base/dist/owa.tracker.js', self::ROOT . '/cli.php', false],
            'a class file with the path as PATH_INFO' => ['/owa/modules/Base/Module.php/modules/base/dist/owa.tracker.js', self::ROOT . '/modules/Base/Module.php', false],
            'another module file'      => ['/owa/modules/base/dist/owa.heatmap.js', self::ROOT . '/modules', false],
        ];
    }

    /** @dataProvider legacyRequests */
    public function testTheLegacyTrackerPathsAreGrantedOnlyAsThemselves(string $uri, string $filename, bool $granted): void
    {
        $this->assertSame($granted, $this->legacyGrant($uri, $filename));
    }

    /**
     * Whether a request mapped to $filename is granted by the entry-point rules:
     * the <FilesMatch> grant, the <If> that denies those names outside the
     * install root, then the api/ and public/ grant, which comes after it.
     */
    private function entryPointAccess(string $filename): bool
    {
        $htaccess = self::htaccess();

        $this->assertSame(1, preg_match('/<FilesMatch "([^"]+)">\s*Require all granted\s*<\/FilesMatch>/', $htaccess, $files));
        $this->assertSame(1, preg_match(
            "/<If \"%\\{REQUEST_FILENAME\\} =~ m#([^#]+)# && ! -f '\\$1\\/owa_env\\.php'\">\\s*Require all denied\\s*<\\/If>/",
            $htaccess, $deny, PREG_OFFSET_CAPTURE), 'one denial of the entry-point names outside the root');
        $this->assertLessThan(strpos($htaccess, 'api|public'), $deny[0][1], 'the denial comes before the api/ grant');

        $granted = (bool) preg_match('#' . $files[1] . '#', basename($filename));

        if (preg_match('#' . $deny[1][0] . '#', $filename, $g) && !self::exists($g[1] . '/owa_env.php')) {
            $granted = false;
        }

        return $this->rootDirectoriesGrant($filename) || $granted;
    }

    /** @return array<string, array{string, bool}> */
    public static function entryPoints(): array
    {
        return [
            'index.php'                       => [self::ROOT . '/index.php', true],
            'log.php'                         => [self::ROOT . '/log.php', true],
            'install.php'                     => [self::ROOT . '/install.php', true],
            'blank.php'                       => [self::ROOT . '/blank.php', true],
            'api/index.php'                   => [self::ROOT . '/api/index.php', true],
            'a root with public/ in its path' => ['/home/site/public/owa/index.php', true],
            'the install template'            => [self::ROOT . '/modules/Base/templates/install.php', false],
            'modules/index.php'               => [self::ROOT . '/modules/index.php', false],
            'a vendor index.php'              => [self::ROOT . '/vendor/acme/lib/public/index.php', false],
            'a nested api/index.php'          => [self::ROOT . '/vendor/acme/lib/api/index.php', false],
            'another root file'               => [self::ROOT . '/cli.php', false],
        ];
    }

    /** @dataProvider entryPoints */
    public function testTheEntryPointsAreGrantedOnlyInTheInstallRoot(string $filename, bool $granted): void
    {
        $this->assertSame($granted, $this->entryPointAccess($filename));
    }

    /** No grant tests REQUEST_URI for api/ or public/, which matches them anywhere in a path. */
    public function testNoGrantMatchesApiOrPublicInTheUri(): void
    {
        foreach (self::grants() as $expr) {
            $this->assertDoesNotMatchRegularExpression('/REQUEST_URI\}[^&|]*(api|public)/', $expr);
        }
    }
}
