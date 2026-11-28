<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\PropertyArchive;
use OWA\Module\Base\Classes\SiteManager;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/PropertyFixtures.php';

/**
 * One live Property per host: the Property screen refuses a domain a live
 * Property has, the UNIQUE index on live_host refuses what gets past it, a
 * new Profile for the host joins the Property that has it, and archived
 * Properties may share one. A restore is refused once the host is taken.
 */
final class PropertyDomainUniqueTest extends TestCase
{
    const FIRST  = 7710200000000001;
    const SECOND = 7710200000000002;
    const THIRD  = 7710200000000003;

    /** The host every Property here is given, spelled differently each time. */
    private string $host = '';

    /** @var list<string> Properties a screen made, removed afterwards */
    private array $made = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes Properties');
        }

        $this->host = 'unique-' . substr(md5(uniqid('', true)), 0, 8) . '.example.test';

        $this->removeAll();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->removeAll();
        }
    }

    private function removeAll(): void
    {
        foreach (array_merge([self::FIRST, self::SECOND, self::THIRD], $this->made) as $id) {
            owa_test_remove_property($id);
        }

        $this->made = [];
    }

    /** Create a Property through the entity, as the screens and fixtures do; whether it was. */
    private function create(int $id, string $domain, int $archived = 0): bool
    {
        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => $id,
            'name'          => 'Unique ' . $id,
            'domain'        => $domain,
            'property_type' => 'web',
            'creation_date' => time(),
            'archived_date' => $archived,
        ]);

        return (bool) $property->create();
    }

    /** @return array<string, mixed>|null the row as stored */
    private function row(int $id): ?array
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf('SELECT * FROM %s WHERE id = ?',
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName()), [(string) $id]);

        return $row ? (array) $row : null;
    }

    /** @return array<string, string> the screen's validation errors */
    private function screenErrors(array $params): array
    {
        $controller = new \OWA\Module\Base\Controller\PropertyEdit($params);
        $controller->validate();

        $v = (new \ReflectionProperty(\OWA\Core\Controller::class, 'v'))->getValue($controller);
        $v->doValidations();

        return (array) $v->getErrorMsgs();
    }

    public function testTheIndexRefusesASecondLivePropertyWithTheHost(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host));
        $this->assertSame($this->host, $this->row(self::FIRST)['live_host']);

        $this->assertFalse($this->create(self::SECOND, 'https://WWW.' . strtoupper($this->host) . ':8443/'),
            'a second live Property with the host was stored');
        $this->assertNull($this->row(self::SECOND));
    }

    public function testArchivedPropertiesMayShareTheHost(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host, 1764000000));
        $this->assertTrue($this->create(self::SECOND, 'www.' . $this->host, 1764000001));
        $this->assertTrue($this->create(self::THIRD, $this->host), 'one live Property beside the archived ones');

        $this->assertNull($this->row(self::FIRST)['live_host']);
        $this->assertNull($this->row(self::SECOND)['live_host']);
        $this->assertSame($this->host, $this->row(self::THIRD)['live_host']);
    }

    public function testThePropertyScreenRefusesANewPropertyWithATakenHost(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host));

        $errors = $this->screenErrors(['name' => 'Taken', 'propertyType' => 'web', 'domain' => 'http://www.' . $this->host . '/']);

        $this->assertSame(sprintf('The Property Unique %1$s (id %1$s) already has the domain %2$s. Only one live Property may have a domain.',
            self::FIRST, $this->host), $errors['domain'] ?? null);
    }

    public function testThePropertyScreenRefusesMovingAPropertyOntoATakenHost(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host));
        $this->assertTrue($this->create(self::SECOND, 'other-' . $this->host));

        $errors = $this->screenErrors(['propertyId' => (string) self::SECOND, 'name' => 'Moved', 'domain' => $this->host]);

        $this->assertStringContainsString('(id ' . self::FIRST . ')', $errors['domain'] ?? '');

        $this->assertArrayNotHasKey('domain', $this->screenErrors(
            ['propertyId' => (string) self::FIRST, 'name' => 'Kept', 'domain' => 'WWW.' . $this->host]),
            'a Property keeps its own host');
    }

    public function testAnArchivedHostIsFreeOnTheScreen(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host, 1764000000));

        $this->assertArrayNotHasKey('domain', $this->screenErrors(['name' => 'Free', 'propertyType' => 'web', 'domain' => $this->host]));
    }

    /** add-site, POST /v1/sites and the Add Profile screen: the Profile joins the Property of its host. */
    public function testANewProfileForATakenHostJoinsItsProperty(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host));

        $site = (new SiteManager())->createSite('https://www.' . $this->host . '/', 'Joins');

        $this->assertNotEmpty($site);
        $this->assertSame((string) self::FIRST, (string) $site->get('property_id'));
        $this->assertSame((string) self::FIRST, SiteManager::propertyIdForDomain('WWW.' . $this->host));
    }

    public function testLiveHostFollowsArchiveAndRestore(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host));

        PropertyArchive::archive(self::FIRST);
        $this->assertNull($this->row(self::FIRST)['live_host']);

        $this->assertTrue(PropertyArchive::restore(self::FIRST)['property']);
        $this->assertSame($this->host, $this->row(self::FIRST)['live_host']);
    }

    public function testARestoreIsRefusedOnceTheHostIsTaken(): void
    {
        $this->assertTrue($this->create(self::FIRST, $this->host));
        PropertyArchive::archive(self::FIRST);

        $this->assertTrue($this->create(self::SECOND, 'www.' . $this->host), 'the host is free once the first is archived');

        $done = PropertyArchive::restore(self::FIRST);

        $this->assertFalse($done['property']);
        $this->assertStringContainsString('(id ' . self::SECOND . ')', $done['refused']);
        $this->assertNotEmpty($this->row(self::FIRST)['archived_date'], 'the Property stays archived');
    }
}
