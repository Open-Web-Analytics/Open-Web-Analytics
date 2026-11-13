<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A site lookup the database could not answer is not "no such site".
 *
 * The edge refused every beacon as an unknown site during an outage, because a
 * failed lookup reads as no row. Now the lookup says it could not answer
 * (null), the beacon goes to the retry queue, and the drain checks the site
 * again when it ingests it.
 *
 * The outage is the connection closed and its reconnect latch set, which is
 * the state connect() leaves after a failed attempt.
 */
final class SiteLookupOutageTest extends TestCase
{
    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    protected function tearDown(): void
    {
        $this->databaseUp();
        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    private function databaseDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->close();

        $latch = new ReflectionProperty( $db, 'connect_failed' );
        $latch->setAccessible( true );
        $latch->setValue( $db, true );
    }

    private function databaseUp(): void
    {
        // close() clears the latch, so the next query connects again.
        \OWA\Core\CoreAPI::dbSingleton()->close();
    }

    private function unknownSiteId(): string
    {
        return 'outage-' . bin2hex( random_bytes( 6 ) );
    }

    public function testALookupTheDatabaseCannotAnswerIsNeitherYesNorNo(): void
    {
        $site_id = $this->unknownSiteId();

        $this->databaseDown();

        $this->assertNull( \OWA\Core\CoreAPI::siteRegistration( $site_id ) );
        $this->assertFalse( \OWA\Core\CoreAPI::isSiteRegistered( $site_id ),
            'isSiteRegistered() answers yes only for a site it found' );
    }

    public function testTheUnansweredLookupIsNotRemembered(): void
    {
        $site_id = $this->unknownSiteId();

        $this->databaseDown();
        $this->assertNull( \OWA\Core\CoreAPI::siteRegistration( $site_id ) );

        $this->databaseUp();
        $this->assertFalse( \OWA\Core\CoreAPI::siteRegistration( $site_id ),
            'once the database answers, an unknown site is unknown' );
    }

    public function testWhenTheDatabaseAnswersAnUnknownSiteIsStillNo(): void
    {
        $this->assertFalse( \OWA\Core\CoreAPI::siteRegistration( $this->unknownSiteId() ) );
    }

    public function testTheDrainRetriesAnEventItCannotCheck(): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setEventType( 'page_view' );
        $event->set( 'site_id', $this->unknownSiteId() );

        $this->databaseDown();

        $this->assertFalse( \OWA\Module\Base\Classes\TrackerIngest::ingest( $event ),
            'an event whose site could not be checked is released to retry, not ingested' );
    }

    public function testTheDrainDropsAnEventForAnUnknownSite(): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setEventType( 'page_view' );
        $event->set( 'site_id', $this->unknownSiteId() );

        $this->assertTrue( \OWA\Module\Base\Classes\TrackerIngest::ingest( $event ),
            'done, so the message is acknowledged rather than retried' );
        $this->assertNotSame( \OWA\Module\Base\Classes\Event::failed, $event->getStatus() );
    }
}
