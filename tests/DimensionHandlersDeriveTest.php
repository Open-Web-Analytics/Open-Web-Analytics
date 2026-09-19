<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Every dimension handler derives its own id from content.
 *
 * The handlers used to be passive consumers: the tracking-property pipeline
 * hashed an id onto the event before dispatch and each handler read it back.
 * Several then guarded with an EARLY RETURN on that id --
 *
 *     if ( ! $event->get( 'host_id' ) ) { return OWA_EHS_EVENT_HANDLED; }
 *
 * -- so a handler left unconverted when the pipeline stops deriving does not
 * error. It quietly stops writing its dimension rows, and the only symptom is a
 * report with fewer rows than it should have. That is how the geolocation
 * defect survived for years, so it gets a test rather than a comment.
 *
 * Each case feeds an event carrying ONLY content -- no *_id property anywhere --
 * and asserts the row appears at the id the dimension derives.
 */
final class DimensionHandlersDeriveTest extends TestCase
{
    private array $created = array();

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'no database' );
        }
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $row ) {

            list( $entity_name, $id ) = $row;
            $e = \OWA\Core\CoreAPI::entityFactory( $entity_name );
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $e->getTableName() ), array( $id ) );
        }
        $this->created = array();
    }

    public static function handlers(): array
    {
        $unique = substr( (string) microtime( true ), -8 );

        return array(
            'host' => array(
                'OWA\Module\Base\Handler\HostHandlers',
                'base.host',
                'OWA\Module\Base\Entity\Host',
                array( 'host' => 'derive' . $unique . '.test' ) ),
            'referer' => array(
                'OWA\Module\Base\Handler\RefererHandlers',
                'base.referer',
                'OWA\Module\Base\Entity\Referer',
                array( 'session_referer' => 'https://derive' . $unique . '.test/p' ) ),
            'document' => array(
                'OWA\Module\Base\Handler\DocumentHandlers',
                'base.document',
                'OWA\Module\Base\Entity\Document',
                array( 'page_url' => 'https://derive' . $unique . '.test/page' ) ),
            'ua' => array(
                'OWA\Module\Base\Handler\UserAgentHandlers',
                'base.ua',
                'OWA\Module\Base\Entity\Ua',
                array( 'HTTP_USER_AGENT' => 'DeriveProbe/' . $unique ) ),
            'os' => array(
                'OWA\Module\Base\Handler\OsHandlers',
                'base.os',
                'OWA\Module\Base\Entity\Os',
                array( 'os' => 'deriveos' . $unique ) ),
            'source' => array(
                'OWA\Module\Base\Handler\SourceHandlers',
                'base.source_dim',
                'OWA\Module\Base\Entity\SourceDim',
                array( 'source' => 'derive' . $unique . '.test' ) ),
            'campaign' => array(
                'OWA\Module\Base\Handler\CampaignHandlers',
                'base.campaign_dim',
                'OWA\Module\Base\Entity\CampaignDim',
                array( 'campaign' => 'derivecamp' . $unique ) ),
            'ad' => array(
                'OWA\Module\Base\Handler\AdHandlers',
                'base.ad_dim',
                'OWA\Module\Base\Entity\AdDim',
                array( 'ad' => 'derivead' . $unique, 'ad_type' => 'banner' ) ),
            'search terms' => array(
                'OWA\Module\Base\Handler\SearchTermHandlers',
                'base.search_term_dim',
                'OWA\Module\Base\Entity\SearchTermDim',
                array( 'search_terms' => 'derive terms ' . $unique ) ),
            'location' => array(
                'OWA\Module\Base\Handler\LocationHandlers',
                'base.location_dim',
                'OWA\Module\Base\Entity\LocationDim',
                array( 'country' => 'Deriveland' . $unique, 'state' => 'DS', 'city' => 'Derive City' ) ),
        );
    }

    /** @dataProvider handlers */
    public function testTheHandlerWritesItsRowFromContentAlone(
        string $handlerClass, string $entityName, string $dimensionClass, array $content ): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        foreach ( $content as $k => $v ) { $event->set( $k, $v ); }

        // Deliberately NOT set: any *_id property. The pipeline would have put
        // one here, and a handler still reading it would return early.
        foreach ( $event->getProperties() as $name => $ignored ) {
            $this->assertStringEndsNotWith( '_id', $name,
                'the fixture must carry no pre-derived id' );
        }

        $expected = $dimensionClass::deriveId( $content );
        $this->assertNotNull( $expected );

        $handler = new $handlerClass();
        $handler->notify( $event );

        $this->created[] = array( $entityName, $expected );

        $row = \OWA\Core\CoreAPI::entityFactory( $entityName );
        $row->getByPk( 'id', $expected );

        $this->assertSame( (string) $expected, (string) $row->get( 'id' ),
            $handlerClass . ' wrote no row for content it should have derived an id from' );
    }
}
