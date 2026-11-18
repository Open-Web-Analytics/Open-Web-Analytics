<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A write drops every cached copy of the row, not only the one under id.
 *
 * getByColumn() caches a row under the column it was looked up by, so a site
 * loaded by site_id and by id is cached twice. Refreshing or dropping only the
 * id entry would leave a load by site_id returning the row as it was: a renamed Profile under its old name, an archived one active, a
 * deleted one still there.
 */
final class EntityCacheEveryKeyTest extends TestCase
{
    /** @var \OWA\Module\Base\Entity\Site */
    private $site;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }

        $domain = 'https://cache-key-test-' . bin2hex(random_bytes(4)) . '.example.com';
        $sm     = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');

        $this->site = $sm->createNewSite($domain, 'Entity cache test');

        $this->assertNotEmpty($this->site->get('id'), 'the test site was not created');
    }

    protected function tearDown(): void
    {
        if (!$this->site) {
            return;
        }

        \OWA\Core\CoreAPI::entityFactory('base.site')->delete($this->site->get('id'), 'id');

        $property = (string) $this->site->get('property_id');

        if ($property) {
            \OWA\Core\CoreAPI::entityFactory('base.property')->delete($property, 'id');
        }
    }

    private function bySiteId(): \OWA\Module\Base\Entity\Site
    {
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($this->site->get('site_id'), 'site_id');

        return $site;
    }

    private function byId(): \OWA\Module\Base\Entity\Site
    {
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($this->site->get('id'));

        return $site;
    }

    public function testARenameIsSeenByALoadBySiteId(): void
    {
        $before = $this->bySiteId()->get('name');

        $site = $this->byId();
        $site->set('name', 'Renamed ' . bin2hex(random_bytes(3)));
        $site->update();

        $this->assertNotSame($before, $site->get('name'));
        $this->assertSame($site->get('name'), $this->bySiteId()->get('name'),
            'a load by site_id returned the name from before the update');
    }

    public function testAnArchiveIsSeenByALoadBySiteId(): void
    {
        $this->assertFalse($this->bySiteId()->isArchived());

        $site = $this->byId();
        $site->set('archived_date', \OWA\Core\CoreAPI::getRequestTimestamp());
        $site->update();

        $this->assertTrue($this->bySiteId()->isArchived(),
            'a load by site_id returned the Profile as it was before it was archived');
    }

    public function testADeleteByIdIsSeenByALoadBySiteId(): void
    {
        $this->assertTrue($this->bySiteId()->wasPersisted());

        \OWA\Core\CoreAPI::entityFactory('base.site')->delete($this->site->get('id'), 'id');

        $this->assertNotTrue($this->bySiteId()->wasPersisted(),
            'a load by site_id returned a deleted Profile');
    }

    public function testAnUnsavedChangeIsNotWhatTheNextLoadSees(): void
    {
        $stored = $this->byId()->get('name');

        // Loaded from the database, so this is the entity that gets cached.
        \OWA\Core\CoreAPI::cacheSingleton()->remove('owa_site', 'id' . $this->site->get('id'));
        $site = $this->byId();
        $site->set('name', 'Never saved');

        $this->assertSame($stored, $this->byId()->get('name'),
            'a change nobody saved came back from the cache');
    }

    public function testTheCacheHoldsTheRowsValues(): void
    {
        $this->byId();

        $cached = \OWA\Core\CoreAPI::cacheSingleton()->get('owa_site', 'id' . $this->site->get('id'));

        $this->assertIsArray($cached, 'the cache holds an entity object, not the row');
        $this->assertSame((string) $this->site->get('site_id'), (string) $cached['site_id']);
    }

    public function testADeleteByAnotherColumnIsSeenByALoadById(): void
    {
        $this->assertTrue($this->byId()->wasPersisted());

        \OWA\Core\CoreAPI::entityFactory('base.site')->delete($this->site->get('site_id'), 'site_id');

        $this->assertNotTrue($this->byId()->wasPersisted(),
            'a load by id returned a Profile deleted by site_id');
    }
}
