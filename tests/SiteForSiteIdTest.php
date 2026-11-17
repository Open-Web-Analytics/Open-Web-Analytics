<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Site::forSiteId(): the one way a Profile is loaded by its site_id.
 */
final class SiteForSiteIdTest extends TestCase
{
    /** @var array<int,array{0:string,1:string}> entity => id, deleted afterwards */
    private array $created = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('loads Profiles');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$entity, $id]) {
            if ((string) $id !== '') {
                \OWA\Core\CoreAPI::entityFactory($entity)->delete($id);
            }
        }

        $this->created = [];
    }

    public function testItLoadsTheProfileTheSiteIdNames(): void
    {
        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $made = $sm->createSite('for-site-id.example.test', 'For site id ' . uniqid());

        $this->created[] = ['base.site', (string) $made->get('id')];
        $this->created[] = ['base.property', (string) $made->get('property_id')];

        $site = \OWA\Module\Base\Entity\Site::forSiteId((string) $made->get('site_id'));

        $this->assertTrue($site->wasPersisted());
        $this->assertSame((string) $made->get('id'), (string) $site->get('id'));
        $this->assertSame('for-site-id.example.test', $site->get('domain'));

    }

    public function testNoSiteIdOrAnUnknownOneLoadsNothing(): void
    {
        foreach (['', '0', 'no-such-site-id'] as $site_id) {
            $site = \OWA\Module\Base\Entity\Site::forSiteId($site_id);

            $this->assertInstanceOf(\OWA\Module\Base\Entity\Site::class, $site);
            $this->assertNotTrue($site->wasPersisted(), var_export($site_id, true));
        }
    }
}
