<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Beacon\Compat;
use OWA\Module\Base\Classes\Migration\ActionMigrator;
use OWA\Module\Base\Classes\TrackingEventHelpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A track.action beacon becomes the custom event the upgrade makes from the
 * same 1.x action.
 *
 * 1.x had one event type for every action. A cached 1.x tag, the PHP SDK and
 * the tracker's deprecated trackAction() still send it; the beacon compat layer
 * (conf/beacon_compat.php, event_reshapes) reshapes it with
 * ActionMigrator::customEvent(), the function the migration uses, before the
 * name gate.
 *
 * The beacons here go through log.php's own steps: the request container,
 * the second decode, admission, then Compat::apply() as logEvent() runs it.
 */
final class BeaconCompatActionTest extends TestCase
{
    /** @var array<string, array> */
    private $saved;

    protected function setUp(): void
    {
        $this->saved = [ 'get' => $_GET, 'post' => $_POST, 'server' => $_SERVER, 'cookie' => $_COOKIE ];

        $_GET    = [];
        $_POST   = [];
        $_COOKIE = [];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'owa.example.test';
        $_SERVER['SERVER_PORT']    = 80;
        $_SERVER['REQUEST_URI']    = '/owa/log.php';
        unset( $_SERVER['HTTP_COOKIE'] );
    }

    protected function tearDown(): void
    {
        $_GET    = $this->saved['get'];
        $_POST   = $this->saved['post'];
        $_SERVER = $this->saved['server'];
        $_COOKIE = $this->saved['cookie'];
    }

    /**
     * The event log.php hands logEvent() for a query string, after
     * Compat::apply().
     *
     * @param array<string, string> $query
     */
    private function beacon( array $query ): \OWA\Module\Base\Classes\Event
    {
        $_GET = $query;

        $request = new \OWA\Core\RequestContainer();
        $request->decodeRequestParams();

        $event = new \OWA\Module\Base\Classes\Event();
        $type  = $request->getParam( 'e_t' );
        $event->setEventType( $type !== false && $type !== '' ? $type : $request->getParam( 'event_type' ) );

        $params = \OWA\Module\Base\Classes\Ingest::at( \OWA\Module\Base\Classes\Ingest::REQUEST_PRE,
            $request->getAllOwaParams() );

        $event->setProperties( TrackingEventHelpers::admitRequestParams( $params ) );

        Compat::apply( $event );

        return $event;
    }

    /** @return array<string, mixed> the fixture both the tracker's Jest test and this one read */
    private static function fixture(): array
    {
        return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/track_action_beacon.json' ), true );
    }

    /** What the 2.0 tracker's trackAction() sends (Tracker.test.js holds it to the same fixture). */
    public function testTheTrackersTrackActionBeaconIsStoredAsTheMigratedCustomEvent(): void
    {
        $fixture = self::fixture();
        $query   = array_map( 'strval', $fixture['wire'] );

        $event = $this->beacon( $query );

        $this->assertSame( $fixture['stored']['event_type'], $event->getEventType() );

        foreach ( $fixture['stored'] as $name => $value ) {

            if ( $name !== 'event_type' ) {

                $this->assertSame( $value, $event->get( $name ), $name );
            }
        }

        foreach ( ActionMigrator::V1_PROPERTIES as $v1 ) {

            $this->assertFalse( $event->get( $v1 ), "$v1 is replaced, not kept" );
        }

        $this->assertTrue( \OWA\Core\CoreAPI::isTrackingEventType( $event->getEventType() ),
            'the reshaped event passes the name gate' );
    }

    /** What a cached 1.x tag sends: the owa_ prefix on every name, event_type rather than e_t. */
    public function testACachedOneXTagsBeaconIsReshapedToo(): void
    {
        $event = $this->beacon( [
            'owa_event_type'    => 'track.action',
            'owa_action_group'  => 'video',
            'owa_action_name'   => 'Play Intro',
            'owa_action_label'  => 'homepage',
            'owa_numeric_value' => '42',
        ] );

        $this->assertSame( 'play_intro', $event->getEventType() );
        $this->assertSame( 'video', $event->get( 'eps_group' ) );
        $this->assertSame( 'homepage', $event->get( 'eps_label' ) );
        $this->assertSame( 42, $event->get( 'epn_value' ) );
        $this->assertSame( 'Play Intro', $event->get( 'eps_action_name' ) );
    }

    /** @return array<string, array{0: string, 1: string, 2: string|false}> 1.x name, event name, eps_action_name */
    public static function names(): array
    {
        return [
            'a legal name'           => [ 'recipe_print', 'recipe_print', false ],
            'an illegal name'        => [ 'Recipe Print!', 'recipe_print', 'Recipe Print!' ],
            'a reserved name'        => [ 'click', 'action_click', 'click' ],
            'a first-class name'     => [ 'Page View', 'action_page_view', 'Page View' ],
            'a leading digit'        => [ '404 Page', 'action_404_page', '404 Page' ],
            'the reserved prefix'    => [ 'owa_thing', 'action_owa_thing', 'owa_thing' ],
        ];
    }

    /**
     * Named as the migration names the same 1.x action.
     *
     * @dataProvider names
     */
    public function testTheNameIsTheMigratedOne( string $action, string $event_type, $original ): void
    {
        $event = $this->beacon( [ 'e_t' => 'track.action', 'action_name' => $action ] );

        $this->assertSame( $event_type, $event->getEventType() );
        $this->assertSame( $original, $event->get( 'eps_action_name' ) );
        $this->assertSame( $event_type, ActionMigrator::customEvent( [ 'action_name' => $action ] )['event_type'],
            'the migration names it the same way' );
        $this->assertTrue( \OWA\Core\CoreAPI::isTrackingEventType( $event_type ) );
    }

    /** A name with no letters or digits leaves nothing to name, so the event is refused. */
    public function testANameThatLeavesNothingIsRefused(): void
    {
        $event = $this->beacon( [ 'e_t' => 'track.action', 'action_name' => '!!!', 'action_group' => 'g' ] );

        $this->assertSame( 'track.action', $event->getEventType() );
        $this->assertFalse( \OWA\Core\CoreAPI::isTrackingEventType( $event->getEventType() ) );
    }

    /** A value that is not a number is left out, as the migration leaves it out. */
    public function testANonNumericValueIsLeftOut(): void
    {
        $event = $this->beacon( [ 'e_t' => 'track.action', 'action_name' => 'signup', 'numeric_value' => 'lots' ] );

        $this->assertSame( 'signup', $event->getEventType() );
        $this->assertFalse( $event->get( 'epn_value' ) );
    }

    /** A track.action queued before an upgrade is reshaped on the drain, with its dispatch key. */
    public function testAQueuedTrackActionIsReshapedWithItsDispatchKey(): void
    {
        $queued = new \OWA\Module\Base\Classes\Event();
        $queued->setEventType( 'track.action' );
        $queued->set( 'action_name', 'Recipe Print' );
        $queued->setDispatchName( \OWA\Core\CoreAPI::trackingDispatchName( 'track.action' ) );

        Compat::applyToQueued( $queued );

        $this->assertSame( 'recipe_print', $queued->getEventType() );
        $this->assertSame( \OWA\Core\CoreAPI::trackingDispatchName( 'recipe_print' ), $queued->getDispatchName() );
    }
}
