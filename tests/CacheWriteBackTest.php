<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * What the cache writes to its persistent store when a request ends.
 *
 * Cache::set() marks a key to be written at the end of the request. A key
 * removed after that was written anyway, as whatever the warm cache held for
 * it -- nothing -- so every row a request loaded and then deleted left a file
 * holding null behind.
 */
final class CacheWriteBackTest extends TestCase
{
    /** A persistent store that records what it is given. */
    private function store(): \OWA\Core\CacheType
    {
        return new class extends \OWA\Core\CacheType {
            public $written = [];
            public $removed = [];

            function set($collection, $id, $value) { $this->written[$collection . '/' . $id] = $value; return true; }
            function remove($collection, $id) { $this->removed[] = $collection . '/' . $id; return true; }
        };
    }

    private function cache(\OWA\Core\CacheType $store): \OWA\Module\Base\Classes\Cache
    {
        $cache = new \OWA\Module\Base\Classes\Cache();
        $cache->cold = $store;

        return $cache;
    }

    public function testAKeySetIsWrittenAtTheEnd(): void
    {
        $store = $this->store();
        $cache = $this->cache($store);

        $cache->set('owa_site', 'id1', ['name' => 'kept']);
        $cache->persistCache();

        $this->assertSame([['name' => 'kept']], array_values($store->written));
    }

    public function testAKeySetThenRemovedIsNotWrittenAtTheEnd(): void
    {
        $store = $this->store();
        $cache = $this->cache($store);

        $cache->set('owa_site', 'id1', ['name' => 'gone']);
        $cache->set('owa_site', 'id2', ['name' => 'kept']);
        $cache->remove('owa_site', 'id1');
        $cache->persistCache();

        $this->assertSame([['name' => 'kept']], array_values($store->written),
            'a removed key was written back at the end of the request');
        $this->assertCount(1, $store->removed, 'the removal still reaches the store');
    }

    public function testNothingIsWrittenForAKeyTheWarmCacheNoLongerHolds(): void
    {
        $store = $this->store();
        $cache = $this->cache($store);

        $cache->set('owa_site', 'id1', ['name' => 'x']);
        $cache->warm->remove('owa_site', $cache->hash('id1'));
        $cache->persistCache();

        $this->assertSame([], $store->written, 'a null was written for a key with nothing in it');
    }
}
