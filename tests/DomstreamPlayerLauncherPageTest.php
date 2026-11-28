<?php

use OWA\Module\Domstream\Controller\PlayerLauncher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/DomstreamFixtures.php';

/**
 * The page Play opens (PlayerLauncher::pageOf()): the page the recording was
 * made on, without its fragment, when it is on the Profile's domain or a
 * subdomain of it, as the REST API's CORS rule allows.
 */
final class DomstreamPlayerLauncherPageTest extends TestCase
{
    private const SITE     = 'owa-player-launcher-page-site';
    private const ARCHIVED = 'owa-player-launcher-page-archived';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'reads the recording and the Profile\'s domain' );
        }

        DomstreamFixtures::ensure();
        $this->clean();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        $db->query( sprintf(
            "INSERT INTO owa_site (id, site_id, property_id, name, domain) VALUES (%s, ?, 0, 'Player launcher fixture', 'www.example.test')",
            $entity->generateId( self::SITE ) ), [ self::SITE ] );

        $db->query( sprintf(
            "INSERT INTO owa_site (id, site_id, property_id, name, domain, archived_date) VALUES (%s, ?, 0, 'Archived fixture', 'example.test', ?)",
            $entity->generateId( self::ARCHIVED ) ), [ self::ARCHIVED, time() ] );
    }

    protected function tearDown(): void
    {
        if ( owa_test_db_available() ) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach ( [ self::SITE, self::ARCHIVED ] as $site ) {

            DomstreamFixtures::deleteSite( $site );
            \OWA\Core\CoreAPI::dbSingleton()->query( 'DELETE FROM owa_site WHERE site_id = ?', [ $site ] );
        }
    }

    private function recorded( string $site, string $recording_id, string $location ): void
    {
        DomstreamFixtures::chunk( $site, [ 'recording_id' => $recording_id, 'page_location' => $location ] );
    }

    public function testThePageIsTheRecordedOneWithoutItsFragment(): void
    {
        $this->recorded( self::SITE, '9200000000000000001', 'https://shop.example.test/pricing?a=1#top' );

        $this->assertSame( 'https://shop.example.test/pricing?a=1', PlayerLauncher::pageOf( self::SITE, '9200000000000000001' ) );
    }

    public function testAPageOffTheDomainIsNotOpened(): void
    {
        $this->recorded( self::SITE, '9200000000000000002', 'https://elsewhere.test/pricing' );
        $this->recorded( self::SITE, '9200000000000000003', 'javascript:alert(1)' );

        $this->assertSame( '', PlayerLauncher::pageOf( self::SITE, '9200000000000000002' ) );
        $this->assertSame( '', PlayerLauncher::pageOf( self::SITE, '9200000000000000003' ) );
    }

    public function testARecordingOfAnotherProfileIsNotFound(): void
    {
        $this->recorded( self::SITE, '9200000000000000004', 'https://example.test/a' );

        $this->assertSame( '', PlayerLauncher::pageOf( 'another-site', '9200000000000000004' ) );
        $this->assertSame( '', PlayerLauncher::pageOf( self::SITE, '9200000000000000099' ) );
    }

    /** An archived Profile's origin is not answered over CORS, so its page is not opened. */
    public function testAnArchivedProfilesPageIsNotOpened(): void
    {
        $this->recorded( self::ARCHIVED, '9200000000000000005', 'https://example.test/a' );

        $this->assertSame( '', PlayerLauncher::pageOf( self::ARCHIVED, '9200000000000000005' ) );
    }
}
