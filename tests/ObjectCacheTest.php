<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The object cache lasts one request.
 *
 * It had a persistent tier, the File Cache and Memcached modules, that served
 * rows up to a week out of date and kept a file per user agent. Now a row is
 * kept for the rest of the request that read it, and the next request reads
 * it from the database.
 */
final class ObjectCacheTest extends TestCase
{
    public function testWhatOneRequestCachedIsNotWhatTheNextReads(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }

        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite('https://object-cache-' . bin2hex(random_bytes(4)) . '.example.com', 'Object cache test');
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        try {
            $load = function () use ($site): string {
                $code = 'require ' . var_export(\OWA\Core\Env::root() . '/owa.php', true) . ';'
                      . '$_SERVER["HTTP_USER_AGENT"] = "Mozilla/5.0 Chrome/120.0";'
                      . 'new owa(["tracking_mode" => true, "request_type" => "tracking"]);'
                      . '$s = \OWA\Core\CoreAPI::entityFactory("base.site");'
                      . '$s->load(' . var_export((string) $site->get('site_id'), true) . ', "site_id");'
                      . 'echo "NAME=", $s->get("name"), "\n";';

                $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');

                return preg_match('/^NAME=(.*)$/m', $out, $m) ? $m[1] : 'no answer: ' . $out;
            };

            $first = $load();

            $db->query('UPDATE owa_site SET name = ? WHERE id = ?', ['Changed behind the cache', $site->get('id')]);

            $this->assertNotSame('Changed behind the cache', $first);
            $this->assertSame('Changed behind the cache', $load(),
                'a request read the row as an earlier request had cached it');
        } finally {
            \OWA\Core\CoreAPI::entityFactory('base.site')->delete($site->get('id'), 'id');
            \OWA\Core\CoreAPI::entityFactory('base.property')->delete((string) $site->get('property_id'), 'id');
        }
    }

    public function testParsingAUserAgentCachesNothing(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) invented-agent/' . bin2hex(random_bytes(4));

        \OWA\Core\CoreAPI::supportClassFactory('base', 'browscap', $ua);

        $this->assertNull(\OWA\Core\CoreAPI::cacheSingleton()->get('browscap', $ua),
            'a user agent was cached: a queue worker would keep one per agent it ever saw');
    }

    public function testARemovedKeyIsGone(): void
    {
        $cache = new \OWA\Module\Base\Classes\Cache();

        $cache->set('owa_site', 'id1', ['name' => 'x']);
        $this->assertSame(['name' => 'x'], $cache->get('owa_site', 'id1'));

        $cache->remove('owa_site', 'id1');
        $this->assertNull($cache->get('owa_site', 'id1'));
    }
}
