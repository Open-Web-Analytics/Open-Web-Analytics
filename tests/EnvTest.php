<?php

use OWA\Core\Env;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Core\Env: where the installation is, and which release.
 *
 * The data and cache directories here are this process's, booted with the
 * install's own owa-config.php; DataDirConfigTest boots with OWA_DATA_DIR
 * and OWA_CACHE_DIR set.
 */
final class EnvTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = array();

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            \OWA\Core\CoreAPI::setSetting('base', $key, $value);
        }
        $this->saved = array();
    }

    private function setForTest(string $key, $value): void
    {
        if (!array_key_exists($key, $this->saved)) {
            $this->saved[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);
        }
        \OWA\Core\CoreAPI::setSetting('base', $key, $value);
    }

    public function testTheRootIsTheCheckout(): void
    {
        $this->assertSame(dirname(__DIR__), Env::root());
        $this->assertFileExists(Env::root() . '/owa.php');
    }

    public function testTheCodeDirectoriesAreUnderTheRootAndEndInASlash(): void
    {
        $this->assertSame(Env::root() . '/modules/', Env::modulesDir());
        $this->assertSame(Env::root() . '/conf/', Env::confDir());
        $this->assertSame(Env::root() . '/vendor/', Env::vendorDir());

        $this->assertDirectoryExists(Env::modulesDir() . 'Base');
        $this->assertFileExists(Env::confDir() . 'messages.php');
    }

    public function testTheVersionIsTheClassConstant(): void
    {
        $this->assertSame(Env::VERSION, Env::version());
        $this->assertNotSame('', Env::version());
    }

    public function testTheDataDirectoryDefaultsToOwaData(): void
    {
        if (defined('OWA_DATA_DIR')) {
            $this->markTestSkipped('this install sets OWA_DATA_DIR');
        }

        $this->assertSame(Env::root() . '/owa-data/', Env::defaultDataDir());
        $this->assertSame(Env::defaultDataDir(), Env::dataDir());
    }

    public function testTheCacheDirectoryDefaultsToCachesInTheDataDirectory(): void
    {
        if (defined('OWA_DATA_DIR') || defined('OWA_CACHE_DIR')) {
            $this->markTestSkipped('this install sets OWA_DATA_DIR or OWA_CACHE_DIR');
        }

        $this->assertSame(Env::dataDir() . 'caches/', Env::cacheDir());
    }

    public function testBothFollowTheirSettings(): void
    {
        $this->setForTest('data_dir', '/srv/owa-data');
        $this->setForTest('cache_dir', '');

        $this->assertSame('/srv/owa-data/', Env::dataDir());
        $this->assertSame('/srv/owa-data/caches/', Env::cacheDir(), 'an empty cache_dir follows the data directory');

        $this->setForTest('cache_dir', '/var/cache/owa');

        $this->assertSame('/var/cache/owa/', Env::cacheDir());
    }

    public function testWithSlashEndsInExactlyOne(): void
    {
        $this->assertSame('/a/b/', Env::withSlash('/a/b'));
        $this->assertSame('/a/b/', Env::withSlash('/a/b/'));
        $this->assertSame('/a/b/', Env::withSlash('/a/b//'));
    }

    /**
     * The Release Packager stamps the version into Core/Env.php. Applying its
     * sed expression to the file must change that one line, to the version.
     */
    public function testTheReleasePackagerStampsTheVersion(): void
    {
        $yml = (string) file_get_contents(Env::root() . '/.github/workflows/main.yml');

        $this->assertSame(1, preg_match('~sed -i "s/(.+?)/(.+?)/" Core/Env\.php~', $yml, $m),
            'main.yml must rewrite the version in Core/Env.php');
        $this->assertStringContainsString('git -C ./ add Core/Env.php', $yml);
        $this->assertStringNotContainsString('owa_env.php', $yml);

        // The pattern is matched literally here, so it must hold no regex syntax.
        $this->assertDoesNotMatchRegularExpression('~[.*\[\]^$\\\\]~', $m[1]);

        $source  = (string) file_get_contents(Env::root() . '/Core/Env.php');
        $stamped = str_replace($m[1], str_replace('${{ env.VERSION }}', '2.0.0', $m[2]), $source);

        $this->assertSame(1, substr_count($source, $m[1]), 'the pattern must match exactly one line');
        $this->assertSame(1, preg_match("~const VERSION = '([^']*)';~", $stamped, $v));
        $this->assertSame('2.0.0', $v[1]);

        $changed = array_diff_assoc(explode("\n", $stamped), explode("\n", $source));
        $this->assertCount(1, $changed);
    }
}
