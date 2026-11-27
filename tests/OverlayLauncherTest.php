<?php

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Cubes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The overlay launcher: a page path to the url its heatmap opens on.
 *
 * A row's link carries the report's Property; the launcher opens the page on
 * a Profile's own domain -- a click is a beacon, and anyone can send one naming
 * any page -- and only for a user who can see the Property.
 */
final class OverlayLauncherTest extends TestCase
{
    const PROPERTY = 7782000000000001;
    const SITE     = 'overlay-launcher-fixture';
    const OTHER    = 'overlay-launcher-fixture-2';
    const VISITOR  = 8894000000000001;

    private static int $seq = 0;

    private string $role = '';

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Overlay launcher fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        // Domains as people enter them: one with its scheme and www., one bare.
        foreach ([[self::SITE, 'http://www.example.test', 1], [self::OTHER, 'shop.example.org', 2]] as [$site_id, $domain, $n]) {
            $site = \OWA\Core\CoreAPI::entityFactory('base.site');
            $site->setProperties([
                'id'          => self::PROPERTY * 10 + $n,
                'site_id'     => $site_id,
                'property_id' => self::PROPERTY,
                'name'        => 'Overlay launcher fixture ' . $n,
                'domain'      => $domain,
            ]);

            if (!$site->create()) {
                throw new \RuntimeException('seeding owa_site failed');
            }
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        // Clicks on /pricing, yesterday. The most clicked url is not on either
        // Profile's domain, which is the forged case.
        $day = (int) date('Ymd', strtotime('-1 day'));

        // A new cube's partitions start on the 1st of this month, so on the
        // 1st yesterday has none and the build below writes nothing.
        \OWA\Core\CoreAPI::dbSingleton()->extendPartitionsBack(Cubes::tableFor(self::PROPERTY), (string) $day);

        self::clicks($day, self::SITE, 'https://evil.test/pricing', 5);
        self::clicks($day, self::SITE, 'https://www.example.test/pricing', 2);
        self::clicks($day, self::OTHER, 'https://shop.example.org/pricing?ref=a', 3);
        self::clicks($day, self::SITE, 'https://www.example.test/a,b%26c', 1, '/a,b%26c');
        self::clicks($day, self::OTHER, 'http://shop.example.org/plain', 1, '/plain');
        self::clicks($day, self::OTHER, 'http://blog.shop.example.org:8080/sub', 1, '/sub');
        self::clicks($day, self::OTHER, 'https://evil.test/ftp', 3, '/ftp');
        self::clicks($day, self::OTHER, 'ftp://shop.example.org/ftp', 2, '/ftp');

        $builder = new Builder(self::PROPERTY);

        foreach ($builder->partitions($day, $day) as $span) {
            if (!$builder->rebuild($span)['ok']) {
                throw new \RuntimeException('building the fixture cube failed');
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this reads a cube.');
        }

        $this->role = (string) \OWA\Core\CoreAPI::getCurrentUser()->getRole();
        $this->asRole('admin');
    }

    protected function tearDown(): void
    {
        if ($this->role !== '') {
            \OWA\Core\CoreAPI::getCurrentUser()->setRole($this->role);
        }
    }

    public function testTheRedirectToATrackedSiteIsNotTurnedBack(): void
    {
        $this->assertSame('https://www.example.test/p',
            \OWA\Core\Lib::resolveRedirectUrl('https://www.example.test/p', ['www.example.test']));

        // Vouching for one host vouches for no other.
        $this->assertNotSame('https://evil.test/p',
            \OWA\Core\Lib::resolveRedirectUrl('https://evil.test/p', ['www.example.test']));
    }

    /**
     * A path holding a comma and an escape reaches the data request whole: the
     * comma does not end the constraint, and the query counts its click.
     */
    public function testAPathWithACommaReachesTheDataRequestWhole(): void
    {
        $url = $this->launchPath('/a,b%26c');

        $this->assertStringStartsWith('https://www.example.test/a,b%26c#owa_overlay.', $url);

        $params = json_decode(base64_decode(rawurldecode(substr($url, strpos($url, '#owa_overlay.') + 13))), true);
        parse_str((string) parse_url($params['api_url'], PHP_URL_QUERY), $q);

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();
        $constraints = $rsm->parseConstraintsString($q['constraints'] ?? '');

        $this->assertSame([['name' => 'pagePath', 'value' => '/a,b%26c', 'operator' => '=='],
            ['name' => 'eventName', 'value' => 'click', 'operator' => '==']], $constraints);

        $rsm->metrics = $rsm->metricsStringToArray('eventCount');
        $rsm->setPropertyId((string) self::PROPERTY);
        $rsm->setTimePeriod('last_thirty_days');
        $rsm->setConstraints($constraints);

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors);
        $this->assertSame(1, (int) ($rs->aggregates['eventCount']['value'] ?? 0));
    }

    /**
     * A Pages row's heatmap: one exact page, path and query string.
     *
     * Opened on the Profile with the page's clicks in the period, with the
     * scheme and host its clicks recorded: https, although the domain was
     * saved with http. The most clicked url, on another host, is passed over.
     * The token the launcher mints accepts the data request it builds.
     */
    public function testAPagesRowOpensThatExactPageWithItsOwnData(): void
    {
        $url = $this->launchPage('/pricing');

        $this->assertStringStartsWith('https://www.example.test/pricing#owa_overlay.', $url);

        $params = json_decode(base64_decode(rawurldecode(substr($url, strpos($url, '#owa_overlay.') + 13))), true);
        $this->assertSame('loadHeatmap', $params['action'] ?? null);

        parse_str((string) parse_url($params['api_url'], PHP_URL_QUERY), $q);

        $this->assertSame('pagePathPlusQuery==/pricing,eventName==click', $q['constraints'] ?? null);
        $this->assertSame('elementAnchor,anchorX,anchorY,clickX,clickY,viewportWidth', $q['dimensions'] ?? null,
            'the overlay draws a click on its element, and needs the window width for one with no anchor');
        $this->assertSame((string) self::PROPERTY, $q['propertyId'] ?? null);
        $this->assertSame('last_thirty_days', $q['period'] ?? null);

        $this->assertTrue(\OWA\Core\OverlayToken::permits($q['overlayToken'] ?? '', 'reports',
            static fn($name) => (string) ($q[$name] ?? '')), 'the token does not accept its own data request');

        // The query string travels to the page and into the constraint. No
        // click recorded it, so the domain's own scheme is used.
        $withQuery = $this->launchPage('/pricing?ref=a');
        $this->assertStringStartsWith('http://www.example.test/pricing?ref=a#owa_overlay.', $withQuery);
    }

    /**
     * A row of pagePath: the path with no query string, and the clicks of
     * every query string of it -- a subscriber id on an email link is still
     * the same page.
     */
    public function testAPathRowOpensThePathWithEveryQueryStringsClicks(): void
    {
        $url = $this->launchPath('/pricing');

        $this->assertStringStartsWith('https://www.example.test/pricing#owa_overlay.', $url);

        $params = json_decode(base64_decode(rawurldecode(substr($url, strpos($url, '#owa_overlay.') + 13))), true);
        parse_str((string) parse_url($params['api_url'], PHP_URL_QUERY), $q);

        $this->assertSame('pagePath==/pricing,eventName==click', $q['constraints'] ?? null);
        $this->assertTrue(\OWA\Core\OverlayToken::permits($q['overlayToken'] ?? '', 'reports',
            static fn($name) => (string) ($q[$name] ?? '')), 'the token does not accept its own data request');

        // A path has no query string, and one that says it has is refused.
        $this->assertSame('', $this->launchPath('/pricing?ref=a'));
        $this->assertSame('', $this->launchPath('pricing'));
    }

    /** A comma does not split the constraint, so a page holding one opens; a relative path does not. */
    public function testAPageWithACommaOpensAndARelativePathDoesNot(): void
    {
        $url = $this->launchPage('/pricing?a=1,2');

        $this->assertStringStartsWith('http://www.example.test/pricing?a=1,2#owa_overlay.', $url);

        $params = json_decode(base64_decode(rawurldecode(substr($url, strpos($url, '#owa_overlay.') + 13))), true);
        parse_str((string) parse_url($params['api_url'], PHP_URL_QUERY), $q);

        $this->assertSame('/pricing?a=1,2',
            (new \OWA\Module\Base\Classes\ResultSetManager())->parseConstraintsString($q['constraints'] ?? '')[0]['value'] ?? null);
        $this->assertSame('', $this->launchPage('pricing'));
    }

    /**
     * The scheme, host and port come from the recorded page, when it is http
     * or https and on the Profile's domain or a subdomain of it. Otherwise the
     * domain, stored bare, is opened over https.
     */
    public function testThePageOpensWithTheSchemeAndHostItWasRecordedOn(): void
    {
        $this->assertStringStartsWith('http://shop.example.org/plain#owa_overlay.', $this->launchPage('/plain'));
        $this->assertStringStartsWith('http://blog.shop.example.org:8080/sub#owa_overlay.', $this->launchPage('/sub'));
        $this->assertStringStartsWith('https://shop.example.org/ftp#owa_overlay.', $this->launchPage('/ftp'),
            'an ftp url and a host off the domain are passed over');
    }

    /** An archived Profile's origin is not answered over CORS, so its pages are not opened. */
    public function testAnArchivedProfilesPageIsNotOpened(): void
    {
        // Through the entity, so the row other tests have read is not served from the cache.
        $archive = static function ($date): void {
            $site = \OWA\Module\Base\Entity\Site::forSiteId(self::OTHER);
            $site->set('archived_date', $date);
            $site->update();
        };

        $archive(time());

        try {
            $this->assertStringStartsNotWith('http://shop.example.org/', $this->launchPage('/plain'));
            $this->assertStringStartsNotWith('http://blog.shop.example.org', $this->launchPage('/sub'));
        } finally {
            $archive(0);
        }
    }

    public function testAPagesRowOpensNothingForAUserWhoCannotSeeTheProperty(): void
    {
        $this->asRole('viewer');

        $this->assertSame('', $this->launchPage('/pricing'));
    }

    private function launchPage(string $value): string
    {
        $launcher = new \OWA\Module\Base\Controller\OverlayLauncher([
            'propertyId'        => (string) self::PROPERTY,
            'period'            => 'last_thirty_days',
            'pagePathPlusQuery' => $value,
        ]);

        $launcher->action();

        return (string) ($launcher->data['url'] ?? '');
    }

    private function launchPath(string $path): string
    {
        $launcher = new \OWA\Module\Base\Controller\OverlayLauncher([
            'propertyId' => (string) self::PROPERTY,
            'period'     => 'last_thirty_days',
            'pagePath'   => $path,
        ]);

        $launcher->action();

        return (string) ($launcher->data['url'] ?? '');
    }

    private function asRole(string $role): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole($role);
        $user->setAuthStatus(true);
    }

    private static function clicks(int $yyyymmdd, string $site_id, string $url, int $n, string $path = '/pricing'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $seq     = ++self::$seq;
            $session = 8894000000000000 + $seq;
            $ts      = strtotime((string) $yyyymmdd . ' 12:00:00') * 1000000 + $seq;

            $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
            $entity->setProperties([
                'id'            => \OWA\Module\Base\Classes\V2Event::id($site_id, self::VISITOR, $session, $ts, 'click'),
                'event_type'    => 'click',
                'site_id'       => $site_id,
                'visitor_id'    => self::VISITOR,
                'session_id'    => $session,
                'ts'            => $ts,
                'yyyymmdd'      => $yyyymmdd,
                'page_location' => $url,
                'page_path'     => $path,
                'is_goal_event' => 0,
            ]);

            if (!$entity->create()) {
                throw new \RuntimeException('seeding owa_event_raw failed');
            }
        }
    }

    private static function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::SITE, self::OTHER] as $site_id) {
            foreach (['base.event_raw', 'base.site'] as $entity) {
                $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                    \OWA\Core\CoreAPI::entityFactory($entity)->getTableName(), $site_id));
            }
        }

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }
}
