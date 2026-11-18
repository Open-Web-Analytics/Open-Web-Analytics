<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The claim a URL carried is a different fact from the source it resolves to.
 *
 * The server parses the tags out of the landing page's URL
 * (TrackingEventHelpers::taggedValue(), with the parameter names from the
 * per-Property `campaignKeys` setting) and records them as tagged_* claims.
 * Classification is the cube pass's (SourceStep, MediumStep, SearchTermsStep),
 * and CubeBuildTest asserts organic-search, direct and referral on BUILT ROWS.
 *
 * What this file covers is the WIRE GATE: a request may state a claim and may
 * not state the answer.
 */
final class TaggedAttributionTest extends TestCase
{
    private function event( array $properties )
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setProperties( $properties );

        return $event;
    }

    /**
     * THE CLAIM IS NOT SETTABLE FROM THE WIRE EITHER, and this test asserted the
     * opposite until the registry could say why.
     *
     * A tagged_* value is `set_by: event`, derived `from: ["landing_url"]`, and
     * carries no wire key -- so the gate refuses the name. What a beacon may
     * report is the URL it landed on; the tags are read out of that URL by the
     * server, for the Property's own campaign keys.
     *
     * The distinction this file was built on -- a request may state a CLAIM and
     * not the ANSWER -- survives; it is just drawn one step earlier. A beacon
     * asserting tagged_source is asserting attribution, and no recorded tracker
     * has ever sent one: neither wire contract carries any tagged_* field.
     */
    public function testTheClaimIsNotSettableFromTheWire(): void
    {
        $admitted = Helpers::admitRequestParams( [
            'tagged_source'   => 'newsletter',
            'tagged_medium'   => 'email',
            'tagged_campaign' => 'summer',
            'tagged_ad'       => 'creative-a',
            'tagged_terms'    => 'blue widgets',
            'page_location'   => 'https://example.test/?owa_source=newsletter',
        ] );

        $this->assertSame(
            [ 'page_location' => 'https://example.test/?owa_source=newsletter' ],
            $admitted,
            'A beacon reports the URL of the page. The tags are the server\'s reading of '
            . 'that URL on the session-starting beacon, so a request asserting one is '
            . 'asserting its own attribution. landing_url was the field this used to '
            . 'admit; it is off the wire, because page_location is the same string there.' );
    }

    /**
     * The other half of the split: a request cannot assert an answer --
     * owa_source=... is not stored as the resolved source.
     */
    public function testTheAnswerIsNotSettableFromTheWire(): void
    {
        /*
         * Through admitRequestParams(), the gate log.php runs.
         *
         * These six are not registered properties at all, because nothing derives
         * them at ingest: the cube pass does. A denylist would let them straight
         * through; the allowlist refuses them because nothing declares them as
         * settable.
         */
        $kept = Helpers::admitRequestParams( [
            'source'       => 'forged',
            'medium'       => 'forged',
            'campaign'     => 'forged',
            'ad'           => 'forged',
            'ad_type'      => 'forged',
            'search_terms' => 'forged',
        ] );

        $this->assertSame(
            [], $kept,
            'A request set the resolved attribution directly.' );
    }








    /**
     * No dimension id is derived in the property pipeline at all.
     *
     * Derivation happens when a row is written, from content, by the dimension
     * that owns it, so nothing in the pipeline has an order that could be wrong.
     * Asserted as an absence: re-adding one of these entries -- campaign_id,
     * ad_id, source_id, referring_search_term_id -- would bring back a property
     * hashed before the answer it hashes is resolved.
     */
    public function testNoDimensionIdIsDerivedInThePipeline(): void
    {
        $config = json_decode(
            (string) file_get_contents( \OWA\Core\Env::root() . '/modules/Base/config/tracking_properties.json' ), true );

        $registered = [];
        foreach ( (array) $config as $scope => $properties ) {
            $registered = array_merge( $registered, array_keys( (array) $properties ) );
        }

        foreach ( [ 'location_id', 'source_id', 'host_id', 'ua_id', 'os_id',
                         'referer_id', 'document_id', 'ad_id', 'campaign_id',
                         'referring_search_term_id' ] as $id ) {

            $this->assertNotContains( $id, $registered,
                "$id is derived in the pipeline again; it belongs to its dimension" );
        }

        $this->assertFalse(
            method_exists( '\OWA\Module\Base\Classes\TrackingEventHelpers', 'generateDimensionId' ),
            'generateDimensionId() is back' );

        $this->assertFalse(
            method_exists( '\OWA\Module\Base\Classes\TrackingEventHelpers', 'generateLocationId' ),
            'generateLocationId() is back' );
    }
}
