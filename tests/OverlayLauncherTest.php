<?php

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Cubes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The overlay launcher: a page path to the url its heatmap opens on.
 *
 * Page Detail's link carries the report's Property and no Profile, so the
 * launcher resolves by Property; an older link's siteId still narrows it. It
 * opens only a url on a Profile's own domain -- a click is a beacon, and anyone
 * can send one naming any page -- and only for a user who can see the Property.
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

    public function testThePropertyOpensItsMostClickedUrlOnAProfilesDomain(): void
    {
        $this->assertSame('https://shop.example.org/pricing?ref=a#owa_overlay.params',
            $this->launch(['propertyId' => (string) self::PROPERTY]));
    }

    public function testASiteIdNarrowsItToThatProfile(): void
    {
        $this->assertSame('https://www.example.test/pricing#owa_overlay.params',
            $this->launch(['siteId' => self::SITE]));
    }

    public function testAUserWhoCannotSeeThePropertyOpensNothing(): void
    {
        $this->asRole('viewer');

        $this->assertSame('', $this->launch(['propertyId' => (string) self::PROPERTY]));
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
     * A Pages row's heatmap: one exact page, path and query string.
     *
     * Opened on the Profile's own domain -- the Profile with the page's clicks
     * in the period -- whatever host the clicks' page_location names, so a
     * forged one cannot choose where the reader goes; and the token the
     * launcher mints accepts the data request it builds.
     */
    public function testAPagesRowOpensThatExactPageWithItsOwnData(): void
    {
        $url = $this->launchPage('/pricing');

        $this->assertStringStartsWith('http://www.example.test/pricing#owa_overlay.', $url);

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

        // The query string travels to the page and into the constraint.
        $withQuery = $this->launchPage('/pricing?ref=a');
        $this->assertStringStartsWith('http://www.example.test/pricing?ref=a#owa_overlay.', $withQuery);
    }

    public function testAPageTheConstraintCannotCarryOpensNothing(): void
    {
        $this->assertSame('', $this->launchPage('/pricing?a=1,2'));
        $this->assertSame('', $this->launchPage('pricing'));
    }

    public function testAPagesRowOpensNothingForAUserWhoCannotSeeTheProperty(): void
    {
        $this->asRole('viewer');

        $this->assertSame('', $this->launchPage('/pricing'));
    }

    private function launchPage(string $value): string
    {
        $launcher = new \OWA\Module\Base\Controller\OverlayLauncher(array(
            'propertyId'        => (string) self::PROPERTY,
            'period'            => 'last_thirty_days',
            'pagePathPlusQuery' => $value,
        ));

        $launcher->action();

        return (string) ($launcher->data['url'] ?? '');
    }

    /** The url the launcher redirects to, or '' when it found none. */
    private function launch(array $params): string
    {
        $launcher = new \OWA\Module\Base\Controller\OverlayLauncher(
            $params + ['pagePath' => '/pricing', 'overlay_params' => 'params']);

        $launcher->action();

        return (string) ($launcher->data['url'] ?? '');
    }

    private function asRole(string $role): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole($role);
        $user->setAuthStatus(true);
    }

    private static function clicks(int $yyyymmdd, string $site_id, string $url, int $n): void
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
                'page_path'     => '/pricing',
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
