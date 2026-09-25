<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;
use OWA\Module\Base\Handler\EventRawHandlers;

/**
 * How a site's own values cross the wire, and what the endpoint admits.
 *
 * THREE THINGS MEET HERE. Scope is in the name (`ep_` / `up_`), type is in the
 * name (`epn_` / `upn_`), and a cap bounds how many ride each beacon. Together
 * they are what lets the endpoint run an ALLOWLIST: a namespace can be
 * admitted without knowing one of a site's keys, which is the same property
 * GA relies on for `ep.` and `up.`.
 *
 * Before this, log.php ran a denylist -- it refused the names the server
 * computes and passed everything else, so the gate was open for exactly the
 * inputs nobody had thought about.
 */
final class CustomPropertyWireTest extends TestCase
{
    // ---- the endpoint gate ------------------------------------------------

    public function testAServerComputedPropertyIsNotAdmitted(): void
    {
        $kept = Helpers::admitRequestParams([
            'source' => 'forged', 'medium' => 'forged',
            'campaign' => 'forged', 'ad' => 'forged', 'search_terms' => 'forged',
        ]);

        $this->assertSame([], $kept,
            'A request set the resolved attribution directly. These are the '
          . 'cube pass\'s to decide.');
    }

    /**
     * THE HALF THE DENYLIST LEFT OPEN. An unregistered name is refused now,
     * where before it was sanitised and set on the event.
     */
    public function testAnUnregisteredNameIsRefused(): void
    {
        $kept = Helpers::admitRequestParams([
            'totally_made_up' => 'x',
            'schema_version'  => '99',
            'is_goal_event'   => '1',
        ]);

        $this->assertSame([], $kept);
    }

    /** A site's own values are admitted, without the gate knowing its keys. */
    public function testCustomValuesAreAdmittedByPrefix(): void
    {
        $params = ['ep_plan' => 'pro', 'epn_seats' => '12',
                   'up_tier' => 'gold', 'upn_ltv' => '400'];

        $this->assertSame($params, Helpers::admitRequestParams($params));
    }

    public static function illegalNameProvider(): array
    {
        return [
            'starts with a digit' => ['ep_9bad'],
            'empty name'          => ['ep_'],
            'numeric, empty'      => ['epn_'],
            'a dot'               => ['ep_a.b'],
            'a dash'              => ['up_a-b'],
            'over forty'          => ['ep_' . str_repeat('a', 41)],
            'prefix alone'        => ['upn_'],
        ];
    }

    /** @dataProvider illegalNameProvider */
    public function testAnIllegalCustomNameIsRefused(string $name): void
    {
        $this->assertSame([], Helpers::admitRequestParams([$name => 'x']),
            $name . ' was admitted; the name has to survive becoming a JSON key');
    }

    /** A registered, client-settable property still gets through. */
    public function testARegisteredClientPropertyIsAdmitted(): void
    {
        $kept = Helpers::admitRequestParams(['page_url' => 'http://example.test/', 'tagged_source' => 'news']);

        $this->assertArrayHasKey('page_url', $kept);
        $this->assertArrayHasKey('tagged_source', $kept,
            'a campaign tag is the site\'s to send; only the RESOLVED source is not');
    }

    // ---- the type, and the cap -------------------------------------------

    /** The prefixes agree across the tracker and ingest, or the wire is broken. */
    public function testTheTrackerAndIngestAgreeOnThePrefixes(): void
    {
        $tracker = file_get_contents(OWA_DIR . 'modules/Base/src/tracker/Tracker.js');

        foreach ([EventRawHandlers::EVENT_PROPERTY_PREFIX,
                  EventRawHandlers::EVENT_PROPERTY_NUMBER_PREFIX,
                  EventRawHandlers::USER_PROPERTY_PREFIX,
                  EventRawHandlers::USER_PROPERTY_NUMBER_PREFIX] as $prefix) {

            $this->assertStringContainsString("'" . $prefix . "'", $tracker,
                'ingest reads ' . $prefix . ' and the tracker never writes it');
        }

        /*
         * And the tracker must NOT carry a cap of its own. It was implemented
         * in both places first, which is worse than either: two numbers that
         * can drift, and a client-side one that reads like a guarantee while
         * guaranteeing nothing -- the tracker is not the only thing that can
         * post to the endpoint.
         */
        $this->assertStringNotContainsString('MAX_CUSTOM_PROPERTIES', $tracker,
            'the cap belongs at ingest; a copy here is advisory and can drift');
    }

    /**
     * The longest prefix wins.
     *
     * `ep_` is not a prefix of `epn_`, but a shorter-first test reads
     * `epn_plan` as an event property named `n_plan` -- a real value under a
     * name nobody set, and no error anywhere.
     */
    public function testTheNumericPrefixIsNotReadAsTheTextOne(): void
    {
        $params = $this->paramsFor(['epn_seats' => '12']);

        $this->assertArrayHasKey('seats', $params);
        $this->assertArrayNotHasKey('n_seats', $params);
        $this->assertSame(12, $params['seats']);
    }

    public function testADeclaredNumberIsStoredAsANumber(): void
    {
        $params = $this->paramsFor(['epn_seats' => '12', 'epn_ratio' => '1.5', 'ep_plan' => 'pro']);

        $this->assertSame(12, $params['seats']);
        $this->assertSame(1.5, $params['ratio']);
        $this->assertSame('pro', $params['plan'], 'a text property must stay text');
    }

    /**
     * A declared number that is not one is DROPPED, not stored as text.
     *
     * The prefix is a claim about the type. Storing a string under it would
     * make the claim unreliable for every reader that trusted it, which is
     * worse than the value being absent.
     */
    public function testADeclaredNumberThatIsNotOneIsDropped(): void
    {
        $params = $this->paramsFor(['epn_seats' => 'lots', 'ep_plan' => 'pro']);

        $this->assertArrayNotHasKey('seats', $params);
        $this->assertSame('pro', $params['plan'], 'the good one still lands');
    }

    /**
     * The cap is enforced HERE, and only here.
     *
     * Not in the tracker: a limit only the tracker honours is a limit only
     * well-behaved callers meet, and anything can post to the endpoint.
     */
    public function testTheCapIsEnforcedAtIngest(): void
    {
        $set = [];

        for ($i = 0; $i < EventRawHandlers::MAX_CUSTOM_PROPERTIES + 10; $i++) {
            $set['ep_k' . $i] = 'v' . $i;
        }

        $params = $this->paramsFor($set);

        $this->assertCount(EventRawHandlers::MAX_CUSTOM_PROPERTIES, $params,
            'ingest is the only gate, so it has to hold on its own');
    }

    // ---- the compat contribution ------------------------------------------

    /**
     * An older generation's properties reach the maps THROUGH THE FILTER.
     *
     * The cv{n} slots are today's case. They used to be generated inside
     * TrackingEventHelpers, which put a compat shim in the middle of the
     * current vocabulary -- and made a measurement of what v2 reads report a
     * live compat path as dead, because the slot names are built at runtime
     * and appear nowhere as literals.
     *
     * Asserted through CoreAPI::filter() rather than by calling the compat
     * class, because the thing that can break is the REGISTRATION: drop that
     * line and the slots quietly stop being defined, a v1 beacon's custom
     * variables stop arriving, and nothing else fails.
     */
    public function testTheCompatLayerContributesTheV1SlotsThroughTheFilter(): void
    {
        owa_coreAPI::serviceSingleton()->initializeFramework();

        $service = owa_coreAPI::serviceSingleton();

        $regular = (array) $service->getMap( 'tracking_properties_regular' );
        $derived = (array) $service->getMap( 'tracking_properties_derived' );

        $max = (int) owa_coreAPI::getSetting( 'base', 'maxCustomVars' );

        $this->assertGreaterThan( 0, $max );

        for ( $slot = 1; $slot <= $max; $slot++ ) {

            $this->assertArrayHasKey( "cv{$slot}", $regular,
                'the compat filter is not registered on the regular map, so log.php\'s '
              . 'allowlist would refuse a v1 beacon\'s slot' );

            foreach ( [ 'name', 'value' ] as $half ) {
                $this->assertArrayHasKey( "cv{$slot}_{$half}", $derived,
                    'the compat filter is not registered, so a v1 beacon loses its custom variables' );
            }
        }
    }

    /** And the slot is still ADMITTED from the wire, which is the point of it. */
    public function testAnOlderBeaconsSlotIsStillAdmitted(): void
    {
        owa_coreAPI::serviceSingleton()->initializeFramework();

        $this->assertSame( [ 'cv1' => 'plan|pro' ],
            Helpers::admitRequestParams( [ 'cv1' => 'plan|pro' ] ),
            'the allowlist refused a v1 slot, so those beacons lose their custom variables' );
    }

    /** And they are carried by NO event in the current vocabulary. */
    public function testAnOlderGenerationsSlotsAreOfferedForNoEvent(): void
    {
        $offered = Helpers::propertiesForEvent( 'page_view' );

        $this->assertNotContains( 'cv1_name', $offered,
            'a v1 slot must not appear in the current vocabulary; nothing a v2 tracker sends produces one' );
        $this->assertNotContains( 'cv1_value', $offered );
    }

    /** Run params() over an event carrying these properties. */
    private function paramsFor(array $properties): array
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('base.page_request');

        foreach ($properties as $k => $v) {
            $event->set($k, $v);
        }

        $handlers = new ReflectionClass(EventRawHandlers::class);
        $method   = $handlers->getMethod('params');
        $method->setAccessible(true);

        $json = $method->invoke($handlers->newInstanceWithoutConstructor(), $event);

        return $json === null ? [] : (array) json_decode($json, true);
    }
}
