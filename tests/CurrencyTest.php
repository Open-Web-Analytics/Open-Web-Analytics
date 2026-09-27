<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Currency;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * Money is stored in minor units, by each currency's own decimal places, and a
 * purchase always has a currency: the one it sent, else its Property's.
 */
final class CurrencyTest extends TestCase
{
    private string $siteId = '';

    private string $propertyId = '';

    const SITE     = 'owa-currency-test-site';
    const PROPERTY = 7775000000000093;

    protected function tearDown(): void
    {
        if ( $this->propertyId !== '' ) {
            \OWA\Core\CoreAPI::clearScopedSetting( 'property', $this->propertyId, 'base', 'currencyISO3' );

            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->query( sprintf( "DELETE FROM %s WHERE site_id = '%s'",
                \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName(), self::SITE ) );
            $db->query( sprintf( 'DELETE FROM %s WHERE id = %d',
                \OWA\Core\CoreAPI::entityFactory( 'base.property' )->getTableName(), self::PROPERTY ) );
        }
    }

    public function testACodeIsThreeLettersUpperCased(): void
    {
        $this->assertSame( 'EUR', Currency::normalize( ' eur ' ) );
        $this->assertSame( '', Currency::normalize( 'EURO' ) );
        $this->assertSame( '', Currency::normalize( '€' ) );
        $this->assertSame( '', Currency::normalize( null ) );
    }

    /** @dataProvider amounts */
    public function testMinorUnitsFollowTheCurrencysDecimalPlaces( $major, string $code, ?int $minor ): void
    {
        $this->assertSame( $minor, Currency::toMinorUnits( $major, $code ) );
    }

    public static function amounts(): array
    {
        return [
            'dollars'                     => [ 12.49, 'USD', 1249 ],
            'no binary truncation'        => [ '12.50', 'USD', 1250 ],
            'yen has no minor unit'       => [ 1500, 'JPY', 1500 ],
            'won likewise'                => [ 25000, 'KRW', 25000 ],
            'the Kuwaiti dinar has three' => [ 1.234, 'KWD', 1234 ],
            'CLF has four'                => [ 1.2345, 'CLF', 12345 ],
            'an unlisted code has two'    => [ 3.5, 'XYZ', 350 ],
            'not a number is NULL'        => [ 'free', 'USD', null ],
            'nothing sent is NULL'        => [ '', 'USD', null ],
        ];
    }

    public function testMajorUnitsInvertTheConversion(): void
    {
        $this->assertSame( 1500.0, Currency::toMajorUnits( 1500, 'JPY' ) );
        $this->assertSame( 12.49, Currency::toMajorUnits( 1249, 'USD' ) );
        $this->assertSame( 1.234, Currency::toMajorUnits( 1234, 'KWD' ) );
    }

    /**
     * A report formats yen as yen. It divided every amount by 100, so ¥1500
     * read correctly only because ingest had multiplied by the same wrong 100.
     */
    public function testAReportFormatsByTheCurrencysDecimalPlaces(): void
    {
        $yen = \OWA\Core\Lib::formatCurrency( 1500, 'en_US', 'JPY' );

        $this->assertStringContainsString( '1,500', $yen );
        $this->assertStringNotContainsString( '15.00', $yen );

        $this->assertStringContainsString( '12.49', \OWA\Core\Lib::formatCurrency( 1249, 'en_US', 'USD' ) );
    }

    /**
     * A purchase that names no currency takes its Property's -- so revenue never
     * sits beside a NULL currency -- and one that names its own keeps it.
     */
    public function testAPurchaseWithNoCurrencyTakesItsPropertys(): void
    {
        $this->requireAParentedSite();

        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', 'currencyISO3', 'EUR' );

        $this->assertSame( 'EUR', Helpers::purchaseCurrency( $this->purchase( [] ) ) );
        $this->assertSame( 'JPY', Helpers::purchaseCurrency( $this->purchase( [ 'currency' => 'jpy' ] ) ) );

        // And the amounts are converted by it: EUR has two places.
        $this->assertSame( 1999, Helpers::toMinorUnits( 19.99, $this->purchase( [] ) ) );
    }

    /** Revenue is the total less tax and shipping, which have columns of their own. */
    public function testRevenueExcludesTaxAndShipping(): void
    {
        $event = $this->purchase( [ 'ct_total' => 2500, 'ct_tax' => 200, 'ct_shipping' => 300 ] );

        $this->assertSame( 2000, Helpers::deriveRevenue( null, $event ) );

        $this->assertSame( 2500, Helpers::deriveRevenue( null, $this->purchase( [ 'ct_total' => 2500 ] ) ),
            'no tax or shipping sent: revenue is the total' );

        $this->assertNull( Helpers::deriveRevenue( null, $this->purchase( [] ) ),
            'no total sent: no revenue, rather than a revenue of zero' );

        $this->assertSame( 5998, Helpers::deriveRevenue( null,
            $this->purchase( [ 'ct_value' => 5998, 'ct_total' => 9999, 'ct_tax' => 490, 'ct_shipping' => 599 ] ) ),
            'a value sent by trackPurchase() is the revenue as it stands' );
    }

    /* ---------------- helpers ---------------- */

    private function purchase( array $properties )
    {
        $event = new \OWA\Module\Base\Classes\Event;
        $event->setEventType( 'purchase' );
        $event->setProperties( $properties + [ 'site_id' => $this->siteId ?: md5( 'owa-currency-probe' ) ] );

        return $event;
    }

    /**
     * A Property and a Profile of its own. Borrowing whichever site the install
     * has made the case skip in a full run, where none was left by the time it
     * ran -- a skip that proved nothing.
     */
    private function requireAParentedSite(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $this->tearDownFixtureRows();

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->setProperties( [
            'id'            => self::PROPERTY,
            'name'          => 'Currency fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ] );
        $this->assertTrue( (bool) $property->create(), 'seeding owa_property' );

        // The id getSiteSetting() looks a site up by: derived from its site_id.
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->setProperties( [
            'id'          => $site->generateId( self::SITE ),
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Currency fixture profile',
            'domain'      => 'example.test',
        ] );
        $this->assertTrue( (bool) $site->create(), 'seeding owa_site' );

        $this->siteId     = self::SITE;
        $this->propertyId = (string) self::PROPERTY;
    }

    private function tearDownFixtureRows(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query( sprintf( "DELETE FROM %s WHERE site_id = '%s'",
            \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName(), self::SITE ) );
        $db->query( sprintf( 'DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory( 'base.property' )->getTableName(), self::PROPERTY ) );
    }
}
