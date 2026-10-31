<?php

use PHPUnit\Framework\TestCase;

/**
 * The overlay launcher: a page path to the url its heatmap opens on.
 *
 * It opens the most clicked url for the path that is on the site's own domain
 * -- a click is a beacon anyone can send, naming any page -- and it has to work
 * when the site is not in the object cache. It looked the site's domain up in
 * the middle of building its click query, and a lookup that went to the
 * database cleared the half-built select: getAllRows() then died in
 * generateSelectQuerySql(). A web request had the site cached already, so only
 * a cold cache showed it.
 */
final class OverlayLauncherTest extends TestCase
{
    private const DOMAIN = 'https://launcher-test.example.com';

    private static $siteId     = '';
    private static $siteRowId  = '';
    private static $propertyId = '';

    private $role = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        if (!owa_test_db_available()) {
            return;
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite(self::DOMAIN, 'Overlay launcher test');

        self::$siteId     = (string) $site->get('site_id');
        self::$siteRowId  = (string) $site->get('id');
        self::$propertyId = (string) $site->get('property_id');

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        // The page on the site's domain, and more clicks forged onto another.
        self::clicks(self::DOMAIN . '/pricing', 2);
        self::clicks('https://evil.example.org/pricing', 3);
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();

        $db->query('DELETE FROM owa_click WHERE site_id = ?', array(self::$siteId));

        if (self::$siteRowId) {
            $db->query('DELETE FROM owa_site WHERE id = ?', array(self::$siteRowId));
        }

        if (self::$propertyId) {
            $db->query('DELETE FROM owa_property WHERE id = ?', array(self::$propertyId));
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the launcher reads clicks out of the database');
        }

        $user       = \OWA\Core\CoreAPI::getCurrentUser();
        $this->role = (string) $user->getRole();

        $user->setRole('admin');
        $user->setAuthStatus(true);
    }

    protected function tearDown(): void
    {
        if ($this->role !== '') {
            \OWA\Core\CoreAPI::getCurrentUser()->setRole($this->role);
        }
    }

    public function testItOpensThePageOnTheSitesDomainWithTheSiteNotCached(): void
    {
        \OWA\Core\CoreAPI::cacheSingleton()->remove('owa_site', 'site_id' . self::$siteId);

        $this->assertSame(self::DOMAIN . '/pricing#owa_overlay.params', $this->launch());
    }

    public function testItOpensTheSamePageWithTheSiteCached(): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->getByColumn('site_id', self::$siteId);

        $this->assertSame(self::DOMAIN . '/pricing#owa_overlay.params', $this->launch());
    }

    /** The url the launcher redirects to, or '' when it found none. */
    private function launch(): string
    {
        $launcher = new \OWA\Module\Base\Controller\OverlayLauncher(array(
            'siteId'         => self::$siteId,
            'pagePath'       => '/pricing',
            'overlay_params' => 'params',
        ));

        $launcher->action();

        return (string) ($launcher->data['url'] ?? '');
    }

    /** Clicks on a page, fired as real events so the document is minted from the url. */
    private static function clicks(string $url, int $n): void
    {
        $rc  = owa_coreAPI::requestContainerSingleton();
        $day = time() - (3 * 86400);
        $day = $day - ($day % 86400) + 43200;

        for ($i = 0; $i < $n; $i++) {

            $rc->timestamp = $day + $i * 60;

            $event = owa_coreAPI::supportClassFactory('base', 'event');
            $event->setEventType('dom.click');
            $event->setProperties(array(
                'site_id'         => self::$siteId,
                'session_id'      => self::guid(),
                'visitor_id'      => self::guid(),
                'guid'            => self::guid(),
                'page_url'        => $url,
                'page_title'      => 'Pricing',
                'target_url'      => $url . '#buy',
                'click_x'         => 10,
                'click_y'         => 20,
                'page_width'      => 1280,
                'page_height'     => 2000,
                'dom_element_id'  => 'buy',
                'dom_element_tag' => 'a',
                'HTTP_USER_AGENT' => 'owa-overlay-launcher-test',
                'ip_address'      => '203.0.113.78',
            ));

            owa_coreAPI::logEvent('dom.click', $event);
        }
    }

    private static function guid(): string
    {
        return ((string) time())
            . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }
}
