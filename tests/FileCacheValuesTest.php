<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The file cache rebuilds plain data and nothing else.
 *
 * It unserialized whatever a file held, so a file naming a class rebuilt an
 * object of it. Entities now cache a row's values, so a file holding an object
 * is either from before that or not the cache's own, and it is a miss.
 */
final class FileCacheValuesTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-filecache-test-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function cache(): \OWA\Module\FileCache\Classes\FileCache
    {
        $cache = new \OWA\Module\FileCache\Classes\FileCache(['cache_dir' => $this->dir, 'cache_id' => 1]);
        $cache->setCollectionExpirationPeriod('owa_site', 3600);

        return $cache;
    }

    public function testARowsValuesComeBack(): void
    {
        $cache = $this->cache();
        $cache->set('owa_site', 'k1', ['id' => '7', 'site_id' => 'abc', 'name' => 'A']);

        $this->assertSame(['id' => '7', 'site_id' => 'abc', 'name' => 'A'], $cache->get('owa_site', 'k1'));
    }

    public function testAFileHoldingAnObjectIsAMissAndIsRemoved(): void
    {
        $cache = $this->cache();
        $cache->set('owa_site', 'k2', new \ArrayObject(['id' => '7']));

        $file = $cache->makeCollectionDirPath('owa_site') . 'k2.php';
        $this->assertFileExists($file);

        $this->assertFalse($cache->get('owa_site', 'k2'), 'an object was rebuilt from a cache file');
        $this->assertFileDoesNotExist($file);
    }

    public function testEntriesAreGroupReadableAndWritableAndDirectoriesKeepTheirGroup(): void
    {
        $cache = $this->cache();
        $cache->set('owa_site', 'k4', ['id' => '7']);

        $dir  = $cache->makeCollectionDirPath('owa_site');
        $file = $dir . 'k4.php';

        clearstatcache();
        $this->assertSame('0660', substr(sprintf('%o', fileperms($file)), -4), 'the group cannot write the entry');
        $this->assertSame('2770', substr(sprintf('%o', fileperms($dir)), -4), 'the directory dropped setgid or group write');
    }

    public function testADirectoryMadeWithTheOldModeIsBroughtUpToDate(): void
    {
        $cache = $this->cache();
        $dir   = $cache->makeCollectionDirPath('owa_site');

        mkdir($this->dir . '1/', 0750);
        mkdir($dir, 0750);
        chmod($dir, 0750);

        $cache->set('owa_site', 'k8', ['id' => '7']);

        clearstatcache();
        $this->assertSame('2770', substr(sprintf('%o', fileperms($dir)), -4));
    }

    public function testAWriteThatCannotLandLeavesNoTempFileAnywhere(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes through a read-only directory');
        }

        $cache = $this->cache();
        $cache->set('owa_site', 'warm', ['id' => '1']);

        $dir = $cache->makeCollectionDirPath('owa_site');
        chmod($dir, 0500);

        $before = glob(sys_get_temp_dir() . '/tmp_k5*') ?: [];

        try {
            $written = $cache->set('owa_site', 'k5', ['id' => '7']);
        } finally {
            chmod($dir, 0700);
        }

        $this->assertFalse($written);
        $this->assertSame($before, glob(sys_get_temp_dir() . '/tmp_k5*') ?: [],
            'the entry was written to the system temp directory instead');
        $this->assertSame([], glob($dir . 'tmp_*') ?: []);
    }

    public function testAFlushRemovesEntriesAndKeepsTheStructure(): void
    {
        $cache = $this->cache();
        $cache->set('owa_site', 'k6', ['id' => '7']);
        touch($cache->makeCollectionDirPath('owa_site') . 'tmp_k7abc');
        touch($this->dir . 'cache.lock');

        $this->assertTrue($cache->flush());

        $dir = $cache->makeCollectionDirPath('owa_site');
        $this->assertFileDoesNotExist($dir . 'k6.php');
        $this->assertFileDoesNotExist($dir . 'tmp_k7abc');
        $this->assertFileExists($dir . 'index.php');
        $this->assertFileExists($this->dir . 'cache.lock');
    }

    public function testAFlushOfACacheDirectoryThatIsNotThereIsNotAnError(): void
    {
        $cache = new \OWA\Module\FileCache\Classes\FileCache(['cache_dir' => $this->dir . 'missing/', 'cache_id' => 1]);

        $this->assertTrue($cache->flush());
    }

    public function testTheExpiryPeriodIsReturned(): void
    {
        $cache = new \OWA\Module\Base\Classes\Cache();
        $cache->cold = $this->cache();

        $this->assertSame(3600, $cache->getCollectionExpirationPeriod('owa_site'));
    }

    public function testAnObjectInsideAnArrayIsAMissToo(): void
    {
        $cache = $this->cache();
        $cache->set('owa_site', 'k3', ['row' => new \ArrayObject(['id' => '7'])]);

        $this->assertFalse($cache->get('owa_site', 'k3'));
    }
}
