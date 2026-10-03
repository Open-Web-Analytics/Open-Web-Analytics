<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * MemcachedCache's constructor ended in parent::__construct(), and its parent,
 * CacheType, has no constructor: "Cannot call constructor", a fatal on every
 * install with the module active. Without the PECL extension it reached that
 * line first, so the fatal did not even need memcached installed.
 */
final class MemcachedCacheConstructTest extends TestCase
{
    public function testItConstructsWithoutTheExtension(): void
    {
        if (extension_loaded('memcached')) {
            $this->markTestSkipped('covers the path without the PECL extension');
        }

        $cache = new \OWA\Module\MemcachedCache\Classes\MemcachedCache(['cache_id' => 'alice']);

        $this->assertNull($cache->mc, 'no extension, no client');
        $this->assertSame('alice', $cache->cache_id);
    }
}
