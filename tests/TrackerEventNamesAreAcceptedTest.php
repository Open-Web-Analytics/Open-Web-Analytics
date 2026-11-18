<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Every event name the tracker sends is accepted, and stores a row.
 *
 * Two assertions, and the second is the one that matters. A name can pass the
 * gate (CoreAPI::trackingEventTypes()) and still have no processor, in which
 * case EventDispatch::notify() logs "no listeners registered" and returns
 * EVENT_HANDLED -- accepted and silently discarded, which the Module docblock
 * calls the worse of the two failures. So this fires each name and counts rows.
 *
 * DRIVEN FROM tests/fixtures/beacon_contracts.json, the standalone record of what
 * each beacon version emits, so a renamed or added event is covered here without
 * anyone editing a list.
 */
final class TrackerEventNamesAreAcceptedTest extends IngestionTestCase
{
    /** The current beacon format version. */
    private const CURRENT = '2';

    /** @var string */
    private $site;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The shared fixture site. ensureSiteRegistered() hardcodes the DOMAIN as
         * 'owa-test-site' and createNewSite() returns early when a site for a
         * domain exists, so asking for a second id creates nothing -- the helper
         * supports one site, and rows are told apart by session_id.
         */
        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);
    }

    /** @return string[] the event names beacon contract 2 carries */
    public static function emittedEventNames(): array
    {
        $contracts = json_decode( (string) file_get_contents(
            \OWA\Core\Env::root() . '/tests/fixtures/beacon_contracts.json' ), true );

        $names = [];

        /*
         * A contract is keyed by the event it describes, and a variant is
         * suffixed -- page_view.campaign is a page_view -- so the base name
         * before the first dot is the vocabulary.
         */
        foreach ( array_keys( (array) ( $contracts[ self::CURRENT ] ?? [] ) ) as $shape ) {

            $names[ strtok( (string) $shape, '.' ) ] = true;
        }

        return array_map( function ( $n ) { return [ $n ]; }, array_keys( $names ) );
    }

    /**
     * The gate accepts it.
     *
     * Configless-safe: trackingEventTypes() reads settings, not the database.
     *
     * @dataProvider emittedEventNames
     */
    public function testTheGateAcceptsIt( string $name ): void
    {
        /*
         * THE ADMISSION RULE, not the first-class list. This asserted membership of
         * trackingEventTypes(), which is only half the gate: a site-named custom
         * event is admitted by its NAME matching the pattern, not by being
         * declared, so a contract shape for one failed a list check correctly.
         */
        $this->assertNotEmpty( (array) \OWA\Core\CoreAPI::trackingEventTypes(),
            'the first-class list is empty; this test would pass vacuously' );

        $this->assertTrue( \OWA\Core\CoreAPI::isTrackingEventType( $name ),
            "The tracker emits $name and logEvent() will refuse it: it is neither a "
            . 'first-class name the property registry declares nor a legal custom '
            . 'event name.' );
    }

    /**
     * And firing it stores a row.
     *
     * The half a settings check cannot do. A name can be admitted and have no
     * processor, and then it is accepted and discarded without an error.
     *
     * @dataProvider emittedEventNames
     */
    public function testFiringItStoresARow( string $name ): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'storing a row needs a database' );
        }

        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent( $name, [
            'site_id'       => $this->site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'guid'          => $this->uniqueGuid(),
            'page_url'      => 'https://owa-accepts.test/p',
            'page_location' => 'https://owa-accepts.test/p',
            'page_title'    => 'Accepts',
            // purchase needs a total for its own columns; harmless elsewhere.
            'ct_order_id'   => 'accepts-' . $name,
            'ct_total'      => '1.00',
        ] );

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $rows = (array) $db->get_results( sprintf(
            "SELECT id, event_type FROM owa_event_raw WHERE site_id = '%s' AND session_id = %d",
            $this->site, (int) $session ) );

        // BY id. Registering the cleanup on event_type would delete every row of
        // that type in the table, not this test's.
        foreach ( $rows as $row ) {
            $this->trackForCleanup( 'base.event_raw', (string) ( (array) $row )['id'], 'id' );
        }

        $this->assertNotEmpty( $rows,
            "Firing $name stored nothing. It is admitted by the gate, so either no "
            . 'processor is registered for it or no handler is.' );
    }
}
