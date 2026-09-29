<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The site list an admin page draws its picker from: one query, whatever the
 * number of sites.
 *
 * It re-loaded every site by id after reading them all, so each admin page ran
 * one query per site -- 403 on an install that had collected leftover test
 * sites.
 */
final class SitesAllowedListTest extends TestCase
{
    private array $domains = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates sites');
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);

        $manager = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');

        foreach (['a', 'b', 'c'] as $label) {
            $domain = 'sites-list-' . $label . '-' . bin2hex(random_bytes(4)) . '.example.com';
            $this->domains[$label] = $domain;
            $manager->createNewSite($domain, 'Sites list ' . $label);
        }

        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE owa_site SET archived_date = ? WHERE domain = ?',
            [time(), $this->domains['c']]);
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->domains as $domain) {
            $db->query('DELETE FROM owa_site WHERE domain = ?', [$domain]);
            $db->query('DELETE FROM owa_property WHERE domain = ?', [$domain]);
        }
    }

    private function sites(): array
    {
        $controller = new class([]) extends \OWA\Core\Controller {
            public function sites() { return $this->getSitesAllowedForCurrentUser(); }
        };

        return $controller->sites();
    }

    public function testOneQueryReadsEverySite(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $before = $db->num_queries;
        $sites = $this->sites();
        $queries = $db->num_queries - $before;

        $this->assertSame(1, $queries, 'one query per site is the defect');
        $this->assertGreaterThanOrEqual(2, count($sites));
    }

    public function testEachSiteIsAsLoadingItWouldBeAndArchivedOnesAreLeftOut(): void
    {
        $byDomain = [];

        foreach ($this->sites() as $site_id => $site) {
            $this->assertTrue($site->wasPersisted());
            $this->assertSame($site_id, $site->get('site_id'));
            $byDomain[$site->get('domain')] = $site;
        }

        $this->assertArrayHasKey($this->domains['a'], $byDomain);
        $this->assertArrayNotHasKey($this->domains['c'], $byDomain, 'archived');

        // Against the stored row, not load(): load() may answer from the
        // cache, which holds the entity as it was created, before the column
        // defaults applied.
        $stored = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row('SELECT * FROM owa_site WHERE domain = ?',
            [$this->domains['b']]);

        $this->assertEquals($stored, $byDomain[$this->domains['b']]->_getProperties());
    }
}
