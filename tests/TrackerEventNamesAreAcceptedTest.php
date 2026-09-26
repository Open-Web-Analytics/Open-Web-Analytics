<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Every event name the tracker sends is accepted, and stores a row.
 *
 * THE BUG THIS EXISTS FOR. 4b93b248 made the v2 tracker emit v2 event names --
 * page_view, click, purchase -- and the server admitted them under their v1
 * spellings only. CoreAPI::trackingEventTypes() merges tracking_event_types (the
 * v1 names) with v2_event_types, and the four RENAMED events were in neither
 * half except custom_event, which happened to be listed. So logEvent() refused a
 * real page_view at the door: measured, it returned false and wrote no row, while
 * the same event dispatched as base.page_request wrote three.
 *
 * Every beacon the current tracker sent was being dropped, and nothing caught it:
 *
 *   - every PHP fixture and test fires the v1 dispatch name, so the unit suite
 *     exercised a path no browser uses any more;
 *   - the e2e specs were the only thing driving a real tracker, and they were
 *     matching v1 names on the wire too, so they timed out waiting for a beacon
 *     and reported it as an empty database.
 *
 * Two assertions, and the second is the one that matters. The gate is cheap to
 * check and would have caught this; but a name can pass the gate and still have
 * no processor, in which case EventDispatch::notify() logs "no listeners
 * registered" and returns EVENT_HANDLED -- accepted and silently discarded, which
 * the Module docblock calls the worse of the two failures. So this fires each name
 * and counts rows.
 *
 * DRIVEN FROM tests/fixtures/beacon_contracts.json, the standalone record of what
 * each beacon version emits, so a renamed or added event is covered here without
 * anyone editing a list.
 *
 * WHERE THE SETTING ACTUALLY LIVES, because mutation-checking this found it: the
 * effective value of v2_event_types comes from Classes\Settings.php's defaults
 * array, NOT from the per-module modules/Base/settings.php entry, which is inert
 * for this key -- it declares a `default` and none of the storable / autoload /
 * scopes keys the migrated settings carry. Editing only the registry file changes
 * nothing at runtime and this test stays green, which is exactly the false
 * negative it has to survive. Both files are kept in step; the one to change first
 * is Settings.php.
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
            OWA_DIR . 'tests/fixtures/beacon_contracts.json' ), true );

        $names = array();

        /*
         * A contract is keyed by the event it describes, and a variant is
         * suffixed -- page_view.campaign is a page_view -- so the base name
         * before the first dot is the vocabulary.
         */
        foreach ( array_keys( (array) ( $contracts[ self::CURRENT ] ?? array() ) ) as $shape ) {

            $names[ strtok( (string) $shape, '.' ) ] = true;
        }

        return array_map( function ( $n ) { return array( $n ); }, array_keys( $names ) );
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
        $accepted = (array) owa_coreAPI::trackingEventTypes();

        $this->assertNotEmpty( $accepted );

        $this->assertContains( $name, $accepted,
            "The tracker emits $name and logEvent() will refuse it: it is in neither "
            . 'tracking_event_types nor v2_event_types.' );
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

        $this->fireEvent( $name, array(
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
        ) );

        $db   = owa_coreAPI::dbSingleton();
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
