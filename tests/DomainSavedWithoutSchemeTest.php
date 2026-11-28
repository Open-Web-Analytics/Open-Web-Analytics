<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/PropertyFixtures.php';

/**
 * Every save of a Profile or Property domain stores host[:port]: a scheme and
 * trailing slashes typed with it are not stored (SiteManager::domainOnly()).
 *
 * The paths: SiteManager::createSite() (the add-Profile screen, POST /v1/sites
 * and cmd=add-site all call it with the typed domain), the Profile edit
 * screen, the Property screen on create and on edit, and the installer's first
 * site.
 */
final class DomainSavedWithoutSchemeTest extends TestCase
{
    /** @var array<int,array{0:string,1:string}> entity => id, deleted afterwards */
    private array $created = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('saves Profiles and Properties');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$entity, $id]) {
            if ((string) $id === '') {
                continue;
            }

            if ($entity === 'base.property') {
                owa_test_remove_property($id);
            } else {
                \OWA\Core\CoreAPI::entityFactory($entity)->delete($id);
            }
        }

        $this->created = [];
    }

    private function track($site): void
    {
        $this->created[] = ['base.site', (string) $site->get('id')];

        if ($site->get('property_id')) {
            $this->created[] = ['base.property', (string) $site->get('property_id')];
        }
    }

    /** The domain column as stored, read past the entity cache. */
    private function storedDomain(string $entity, $id)
    {
        $table = \OWA\Core\CoreAPI::entityFactory($entity)->getTableName();
        $row   = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf('SELECT domain FROM %s WHERE id = ?', $table), [(string) $id]);

        return $row ? ((array) $row)['domain'] : false;
    }

    private function data($controller): array
    {
        return (array) (new \ReflectionProperty(\OWA\Core\Controller::class, 'data'))->getValue($controller);
    }

    public function testAProfileCreatedWithASchemeStoresTheHost(): void
    {
        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createSite('https://scheme-create.example.test:8443/', 'Scheme create ' . uniqid());

        $this->assertNotEmpty($site, 'createSite() created nothing');
        $this->track($site);

        $this->assertSame('scheme-create.example.test:8443', $this->storedDomain('base.site', $site->get('id')));
        $this->assertSame('scheme-create.example.test:8443', $this->storedDomain('base.property', $site->get('property_id')));
    }

    public function testCreateNewSiteFindsTheDomainHoweverItIsTyped(): void
    {
        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite('http://scheme-new.example.test', 'Scheme new ' . uniqid());

        $this->assertNotEmpty($site);
        $this->track($site);
        $this->assertSame('scheme-new.example.test', $this->storedDomain('base.site', $site->get('id')));

        $this->assertEmpty($sm->createNewSite('https://scheme-new.example.test/'),
            'the same domain typed with another scheme is the site already there');
    }

    public function testTheProfileEditScreenStoresTheHost(): void
    {
        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createSite('scheme-edit.example.test', 'Scheme edit ' . uniqid());
        $this->track($site);

        $controller = new \OWA\Module\Base\Controller\SitesEdit([
            'siteId' => (string) $site->get('site_id'),
            'name'   => 'Scheme edit renamed',
            'domain' => 'HTTPS://scheme-edit.example.test/',
        ]);
        $controller->action();

        $this->assertSame('scheme-edit.example.test', $this->storedDomain('base.site', $site->get('id')));
    }

    public function testThePropertyScreenStoresTheHostOnCreateAndOnEdit(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyEdit([
            'name'         => 'Scheme property ' . uniqid(),
            'domain'       => 'https://scheme-property.example.test/',
            'propertyType' => 'web',
        ]);
        $controller->action();

        $id = (string) ($this->data($controller)['propertyId'] ?? '');
        $this->assertNotSame('', $id);
        $this->created[] = ['base.property', $id];

        $this->assertSame('scheme-property.example.test', $this->storedDomain('base.property', $id));

        $controller = new \OWA\Module\Base\Controller\PropertyEdit([
            'propertyId' => $id,
            'name'       => 'Scheme property edited',
            'domain'     => 'http://localhost:8080/',
        ]);
        $controller->action();

        $this->assertSame('localhost:8080', $this->storedDomain('base.property', $id));
    }

    /** A domain that is only a scheme is no domain, so the screens refuse it as empty. */
    public function testASchemeAloneIsRefusedAsNoDomain(): void
    {
        foreach ([
            new \OWA\Module\Base\Controller\PropertyEdit(['name' => 'x', 'domain' => 'https://', 'propertyType' => 'web']),
            new \OWA\Module\Base\Controller\SitesAdd(['name' => 'x', 'domain' => 'http:///']),
        ] as $controller) {
            $controller->validate();
            $v = (new \ReflectionProperty(\OWA\Core\Controller::class, 'v'))->getValue($controller);
            $v->doValidations();

            $this->assertTrue($v->hasErrors, get_class($controller));
            $this->assertArrayHasKey('domain', (array) $v->getErrorMsgs(), get_class($controller));
        }
    }

    public function testTheInstallersFirstSiteStoresTheHost(): void
    {
        $siteId = 'scheme-install-' . substr(md5(uniqid('', true)), 0, 8);
        $im     = \OWA\Core\CoreAPI::supportClassFactory('base', 'installManager');

        $this->assertSame($siteId, $im->createDefaultSite('https://scheme-install.example.test/', '', '', '', $siteId));

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($siteId, 'site_id');
        $this->track($site);

        $this->assertSame('scheme-install.example.test', $this->storedDomain('base.site', $site->get('id')));
        $this->assertSame('scheme-install.example.test', $this->storedDomain('base.property', $site->get('property_id')));
    }
}
