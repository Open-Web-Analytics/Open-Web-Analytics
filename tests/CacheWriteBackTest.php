<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * When the cache writes to its persistent store, and what it writes.
 *
 * It wrote when the request ended: every key set during the request, as the
 * warm cache held it then. A long process wrote back rows as they were when it
 * read them, over changes saved in the meantime; a key set then removed was
 * written anyway, as null; and the entity cached was the caller's own object,
 * unsaved changes and all. Now a value is written when it is cached, and an
 * entity caches its row's values.
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

    public function testAKeyIsWrittenWhenItIsSet(): void
    {
        $store = $this->store();
        $cache = $this->cache($store);

        $cache->set('owa_site', 'id1', ['name' => 'kept']);

        $this->assertSame([['name' => 'kept']], array_values($store->written),
            'the value waited for the end of the request');
    }

    public function testNothingIsWrittenWhenTheRequestEnds(): void
    {
        $store = $this->store();
        $cache = $this->cache($store);

        $cache->set('owa_site', 'id1', ['name' => 'first']);
        $store->written = [];

        $cache->persistCache();

        $this->assertSame([], $store->written,
            'the end of the request wrote back what the process had read');
    }

    public function testAKeySetThenRemovedIsGoneFromTheStore(): void
    {
        $store = $this->store();
        $cache = $this->cache($store);

        $cache->set('owa_site', 'id1', ['name' => 'gone']);
        $cache->remove('owa_site', 'id1');
        $cache->persistCache();

        $this->assertSame([$cache->hash('id1')], array_map(fn($k) => substr($k, strlen('owa_site/')), $store->removed));
        $this->assertNull($cache->get('owa_site', 'id1'));
    }

    public function testParsingAUserAgentCachesNothing(): void
    {
        $store = $this->store();
        $cache = \OWA\Core\CoreAPI::cacheSingleton();
        $saved = $cache->cold;
        $cache->cold = $store;

        $ua = 'Mozilla/5.0 (X11; Linux x86_64) invented-agent/' . bin2hex(random_bytes(4));

        try {
            \OWA\Core\CoreAPI::supportClassFactory('base', 'browscap', $ua);
            $held = $cache->get('browscap', $ua);
        } finally {
            $cache->cold = $saved;
        }

        $this->assertSame([], $store->written,
            'a user agent was cached: one entry per agent anyone cares to send');
        $this->assertNull($held, 'a user agent was cached, to be written for the next request');
    }
}
