<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * makeUrlCanonical() keeps a page's host as it arrived. A page on a host other
 * than the Profile's domain is stored on that host, not rewritten to the
 * domain.
 */
final class CanonicalUrlHostTest extends TestCase
{
    const SITE = 'owa-canonical-host-site';

    /** @var mixed the install's domain_aliases before the test */
    private $aliases;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'reads the Profile\'s domain' );
        }

        $this->aliases = \OWA\Core\CoreAPI::getSetting( 'base', 'domain_aliases' );
        $this->dropSite();

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            "INSERT INTO owa_site (id, site_id, property_id, name, domain) VALUES (%s, ?, 0, 'Canonical host fixture', 'www.example.com')",
            \OWA\Core\CoreAPI::entityFactory( 'base.site' )->generateId( self::SITE ) ), [ self::SITE ] );
    }

    protected function tearDown(): void
    {
        if ( owa_test_db_available() ) {
            \OWA\Core\CoreAPI::setSetting( 'base', 'domain_aliases', $this->aliases );
            $this->dropSite();
        }
    }

    private function dropSite(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query( 'DELETE FROM owa_site WHERE site_id = ?', [ self::SITE ] );
    }

    private function canonical( string $url ): string
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setSiteId( self::SITE );

        return (string) Helpers::makeUrlCanonical( $url, $event );
    }

    public function testAPageOnAnotherHostIsStoredOnThatHost(): void
    {
        $this->assertSame( 'https://example.org/a', $this->canonical( 'https://example.org/a' ) );
        $this->assertSame( 'https://shop.example.net/cart?id=2', $this->canonical( 'https://shop.example.net/cart?id=2' ) );
    }

    /** A value an installation still holds under the old domain_aliases name changes nothing. */
    public function testAStoredDomainAliasesValueIsNotApplied(): void
    {
        \OWA\Core\CoreAPI::setSetting( 'base', 'domain_aliases', 'example.org, example.net' );

        $this->assertSame( 'https://example.org/a', $this->canonical( 'https://example.org/a' ) );
        $this->assertSame( 'https://blog.example.net/p', $this->canonical( 'https://blog.example.net/p' ) );
    }

    public function testAPageOnTheDomainKeepsItsHost(): void
    {
        $this->assertSame( 'https://www.example.com/a', $this->canonical( 'https://www.example.com/a' ) );
        $this->assertSame( 'https://example.com/a', $this->canonical( 'https://example.com/a' ) );
    }
}
