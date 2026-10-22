<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Reports are of a Property, and every Profile in it.
 *
 * The cube was always per Property; what scoped a report to one Profile was
 * the siteId every report request carried. This covers what replaced it:
 * access granted at the Property, report requests that name a Property, and
 * the roster a Profile is reached from now that the site control stops at the
 * Property.
 */
final class PropertyScopedReportingTest extends TestCase
{
    /** High and fixed, like the funnel fixtures'. */
    const PROPERTY = 92000080;

    const OTHER_PROPERTY = 92000081;

    const ONE      = 'psr-one';
    const TWO      = 'psr-two';
    const ARCHIVED = 'psr-archived';
    const OTHER    = 'psr-other';
    const DOOMED   = 'psr-doomed';

    /** @var string[] user_id of each user made, removed afterwards */
    private array $users = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs Profiles, Properties and grants');
        }

        $this->dropFixture();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::PROPERTY, self::OTHER_PROPERTY] as $property) {
            $db->query(sprintf("INSERT INTO owa_property (id, name, domain) VALUES (%d, 'PSR fixture', 'psr.test')", $property));
        }

        // Named out of id order, so the roster's ordering is by name.
        // Ids as a Profile's are minted, which is how SitesDelete finds one.
        $entity = \OWA\Core\CoreAPI::entityFactory('base.site');

        foreach ([
            [self::ONE,      self::PROPERTY,       'B profile', null],
            [self::TWO,      self::PROPERTY,       'A profile', null],
            [self::ARCHIVED, self::PROPERTY,       'C profile', time()],
            [self::OTHER,    self::OTHER_PROPERTY, 'Other',     null],
            // Archived until the delete test needs it live.
            [self::DOOMED,   self::PROPERTY,       'D profile', time()],
        ] as [$site, $property, $name, $archived]) {
            $db->query(sprintf("INSERT INTO owa_site (id, site_id, property_id, name, domain, archived_date) VALUES (%s, ?, %d, ?, 'psr.test', ?)",
                $entity->generateId($site), $property), [$site, $name, $archived]);
        }

        \OWA\Module\Base\Entity\Site::forgetAssignedUsers();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->dropFixture();
        }

        \OWA\Module\Base\Entity\Site::forgetAssignedUsers();
    }

    private function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->users as $user_id) {
            $user = \OWA\Core\CoreAPI::entityFactory('base.user');
            $user->load($user_id, 'user_id');

            if ($user->wasPersisted()) {
                $db->query('DELETE FROM owa_site_user WHERE user_id = ?', [$user->get('id')]);
                $user->delete($user_id, 'user_id');
            }
        }

        $this->users = [];

        $entity = \OWA\Core\CoreAPI::entityFactory('base.site');

        foreach ([self::ONE, self::TWO, self::ARCHIVED, self::OTHER, self::DOOMED] as $site) {
            $db->query(sprintf('DELETE FROM owa_site_user WHERE site_id = %s', $entity->generateId($site)));
            $db->query('DELETE FROM owa_site WHERE site_id = ?', [$site]);
        }
        $db->query(sprintf('DELETE FROM owa_property WHERE id IN (%d, %d)', self::PROPERTY, self::OTHER_PROPERTY));
    }

    /** A viewer granted the given Profiles, as the database holds them. @return array [entity, row id] */
    private function viewer(array $sites): array
    {
        $user_id = 'psr-' . substr(md5(uniqid('', true)), 0, 8);

        $um = \OWA\Core\CoreAPI::supportClassFactory('base', 'userManager');
        $this->assertNotFalse($um->createNewUser([
            'user_id' => $user_id, 'role' => 'viewer', 'password' => 'psr-' . uniqid('', true),
            'email_address' => $user_id . '@example.test', 'real_name' => 'PSR probe',
        ]), 'could not create the probe user');

        $this->users[] = $user_id;

        $user = \OWA\Core\CoreAPI::entityFactory('base.user');
        $user->load($user_id, 'user_id');

        foreach ($sites as $site) {
            $grant = \OWA\Core\CoreAPI::entityFactory('base.site_user');
            $grant->set('user_id', $user->get('id'));
            $grant->set('site_id', $this->rowId($site));
            $grant->create();
        }

        return [$user, (int) $user->get('id')];
    }

    private function serviceUser(\OWA\Module\Base\Entity\User $user): \OWA\Module\Base\Classes\ServiceUser
    {
        $su = new \OWA\Module\Base\Classes\ServiceUser();
        $su->loadNewUserByObject($user);

        return $su;
    }

    private function rowId(string $site): int
    {
        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row('SELECT id FROM owa_site WHERE site_id = ?', [$site]);

        return (int) $row['id'];
    }

    private function grantedSites(int $user): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
                'SELECT s.site_id FROM owa_site_user g JOIN owa_site s ON s.id = g.site_id WHERE g.user_id = ?', [$user]) as $row) {
            $out[] = ((array) $row)['site_id'];
        }

        sort($out);

        return $out;
    }

    private function data($controller): array
    {
        $p = new ReflectionProperty(\OWA\Core\Controller::class, 'data');
        $p->setAccessible(true);

        return (array) $p->getValue($controller);
    }

    // ---------------------------------------------------------------- access

    public function testAGrantOnOneProfileIsAGrantOnItsProperty(): void
    {
        [$user] = $this->viewer([self::ONE]);
        $su = $this->serviceUser($user);

        $sites = array_keys($su->getAssignedSites());
        sort($sites);

        $this->assertSame([self::ONE, self::TWO], $sites,
            'the sibling is missing, or an archived Profile or another Property came with it');

        $this->assertTrue($su->isCapable('view_reports', self::TWO), 'a sibling of a granted Profile');
        $this->assertFalse($su->isCapable('view_reports', self::OTHER), 'a Profile of another Property');
    }

    public function testAPropertysCapabilitiesAreCheckedAgainstAProfileTheUserMaySee(): void
    {
        [$user] = $this->viewer([self::TWO]);
        $su = $this->serviceUser($user);

        $this->assertContains($su->siteIdForProperty((string) self::PROPERTY), [self::ONE, self::TWO]);
        $this->assertSame('', $su->siteIdForProperty((string) self::OTHER_PROPERTY));
        $this->assertSame('', $su->siteIdForProperty(''));
    }

    public function testAReportIsCheckedAgainstThePropertyItNames(): void
    {
        [$user] = $this->viewer([self::ONE]);
        $su = $this->serviceUser($user);

        $check = function (array $params) use ($su): bool {
            $controller = new \OWA\Core\ConfiguredReport($params);
            $m = new ReflectionMethod($controller, 'isCurrentUserCapable');
            $m->setAccessible(true);

            return $m->invoke($controller, $su);
        };

        $this->assertTrue($check(['propertyId' => (string) self::PROPERTY]));
        $this->assertFalse($check(['propertyId' => (string) self::OTHER_PROPERTY]));

        // A siteId the reader may see cannot carry them into a Property they may not.
        $this->assertFalse($check(['propertyId' => (string) self::OTHER_PROPERTY, 'siteId' => self::ONE]));

        // Without a propertyId the site decides, as before.
        $this->assertTrue($check(['siteId' => self::TWO]));
        $this->assertFalse($check(['siteId' => self::OTHER]));
    }

    public function testPropertyAccessShowsAGrantOnAnyProfile(): void
    {
        [, $id] = $this->viewer([self::TWO]);

        $this->assertContains($id, \OWA\Module\Base\Classes\PropertyGrants::userIds((string) self::PROPERTY));
        $this->assertNotContains($id, \OWA\Module\Base\Classes\PropertyGrants::userIds((string) self::OTHER_PROPERTY));
    }

    public function testPropertyAccessWritesEveryProfile(): void
    {
        [, $granted]   = $this->viewer([]);
        [, $untouched] = $this->viewer([self::ONE]);

        // Rendered both, ticked the first: the second is revoked, the first granted.
        \OWA\Module\Base\Classes\PropertyGrants::apply((string) self::PROPERTY, [$granted, $untouched], [$granted]);

        $this->assertSame([self::ARCHIVED, self::DOOMED, self::ONE, self::TWO], $this->grantedSites($granted),
            'every Profile, archived ones included, so a restore comes back with the Property\'s grants');
        $this->assertSame([], $this->grantedSites($untouched));

        // A user the form did not render is left alone.
        [, $unrendered] = $this->viewer([self::ONE]);
        \OWA\Module\Base\Classes\PropertyGrants::apply((string) self::PROPERTY, [$granted], []);

        $this->assertSame([self::ONE], $this->grantedSites($unrendered));
        $this->assertSame([], $this->grantedSites($granted));
    }

    public function testTheAccessSaveReturnsToTheAccessScreen(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyAccessUpdate([
            'propertyId' => (string) self::PROPERTY, 'rendered_users' => [], 'allowed_users' => [],
        ]);
        $controller->action();

        $data = $this->data($controller);

        $this->assertSame('base.propertyAccess', $data['do'] ?? null);
        $this->assertSame((string) self::PROPERTY, $data['propertyId'] ?? null);
        $this->assertSame(3217, $data['status_code'] ?? null);
    }

    // --------------------------------------------------------------- reports

    public function testAReportScreenIsOfThePropertyItsSiteIdNames(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);

        $data = (array) (new \OWA\Module\Base\Controller\Report(
            ['reportId' => 'pages', 'siteId' => self::TWO, 'period' => 'last_seven_days']))->doAction();

        $this->assertSame((string) self::PROPERTY, $data['params']['propertyId'] ?? null);
        $this->assertSame((string) self::PROPERTY, $data['currentPropertyId'] ?? null);
        $this->assertArrayNotHasKey('siteId', $data['params'],
            'the screen kept the siteId, so its links would carry one Profile');
    }

    public function testTheRestRouteKeepsASiteIdAsAFilter(): void
    {
        $p = new ReflectionProperty(\OWA\Core\ReportController::class, 'filters_by_site');
        $p->setAccessible(true);

        $this->assertTrue($p->getValue(new \OWA\Module\Base\Controller\ReportsRest([])),
            'a client asking the API for one site\'s numbers would get the whole Property\'s');
        $this->assertFalse($p->getValue(new \OWA\Core\ConfiguredReport([])));
    }

    public function testAQueryOfAPropertyIsNotNarrowedToAProfile(): void
    {
        $property = new ReflectionMethod(\OWA\Module\Base\Classes\ResultSetManager::class, 'propertyIdForRequest');
        $property->setAccessible(true);

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();
        $rsm->setPropertyId((string) self::PROPERTY);

        $this->assertSame((string) self::PROPERTY, $property->invoke($rsm));
        $this->assertEmpty($rsm->getConstraint('siteId'), 'a Property query was narrowed to a Profile');

        // A siteId narrows it; with no propertyId it also names the cube.
        $filtered = new \OWA\Module\Base\Classes\ResultSetManager();
        $filtered->setSiteId(self::TWO);

        $this->assertSame((string) self::PROPERTY, $property->invoke($filtered));
        $this->assertSame(self::TWO, $filtered->getConstraint('siteId')['value'] ?? null);
    }

    // ---------------------------------------------------------------- roster

    public function testTheRosterListsLiveProfilesByName(): void
    {
        $this->assertSame(['A profile', 'B profile'], array_column(
            \OWA\Module\Base\Controller\PropertyProfiles::rowsFor((string) self::PROPERTY), 'name'));
    }

    public function testDeletingAProfileReturnsToItsRoster(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query("UPDATE owa_site SET archived_date = NULL WHERE site_id = ?", [self::DOOMED]);

        $controller = new \OWA\Module\Base\Controller\SitesDelete(['siteId' => self::DOOMED]);
        $controller->action();

        $data = $this->data($controller);

        $this->assertSame('base.propertyProfiles', $data['do'] ?? null);
        $this->assertSame((string) self::PROPERTY, $data['propertyId'] ?? null);
    }

    public function testTheProfileGroupIsOnlyOnAProfilesOwnScreens(): void
    {
        $controller = new \OWA\Module\Base\Controller\OrganizationProfile([]);

        $nav = new ReflectionMethod(\OWA\Core\Controller::class, 'getHierarchyNav');
        $nav->setAccessible(true);

        $property = (array) $nav->invoke($controller, '', (string) self::PROPERTY);

        $this->assertArrayNotHasKey('Observation Profile', $property,
            'a Property screen offers one Profile\'s screens');

        // Access needs only the Property now.
        $access = array_column($property['Property'], 'params', 'do')['base.propertyAccess'] ?? null;
        $this->assertSame(['propertyId' => (string) self::PROPERTY], $access);

        $this->assertArrayHasKey('Observation Profile', (array) $nav->invoke($controller, self::ONE, ''));
    }
}
