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

    public function testAnObjectInsideAnArrayIsAMissToo(): void
    {
        $cache = $this->cache();
        $cache->set('owa_site', 'k3', ['row' => new \ArrayObject(['id' => '7'])]);

        $this->assertFalse($cache->get('owa_site', 'k3'));
    }
}
