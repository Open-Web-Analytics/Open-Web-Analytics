<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The claim a URL carried is a different fact from the source it resolves to.
 *
 * Until this release the tracker and the server both wrote source, medium,
 * campaign, ad and search_terms. A row therefore recorded no trace of which
 * half produced its value, and every derivation had to open by respecting its
 * own current value -- the callback could not tell "the tracker sent this" from
 * "an earlier callback computed this".
 *
 * The tracker no longer reports tagged_* either. It sends landing_url, and the
 * server parses the tags out of it -- TrackingEventHelpers::taggedValue(), with
 * the parameter names coming from the per-Property `campaignKeys` setting.
 *
 * WHAT THIS FILE NO LONGER TESTS. Seven cases here drove resolveSource(),
 * resolveMedium(), resolveSearchTerms(), resolveCampaign() and resolveAd(),
 * asserting that a tag beats a referer, that a search referer is
 * organic-search, that a bare visit is direct. Those functions are gone: the
 * classification is the cube pass's now (SourceStep, MediumStep,
 * SearchTermsStep), and CubeBuildTest asserts organic-search, direct and
 * referral on BUILT ROWS -- the same behaviour, checked where it happens and
 * against the value a report actually reads.
 *
 * What remains here is the WIRE GATE: a request may state a claim and may not
 * state the answer. That is the half no other file covers.
 */
final class TaggedAttributionTest extends TestCase
{
    private function event( array $properties )
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setProperties( $properties );

        return $event;
    }

    public function testTheClaimIsSettableFromTheWire(): void
    {
        $kept = Helpers::rejectServerOwnedParams( array(
            'tagged_source'   => 'newsletter',
            'tagged_medium'   => 'email',
            'tagged_campaign' => 'summer',
            'tagged_ad'       => 'creative-a',
            'tagged_ad_type'  => 'cpc',
            'tagged_terms'    => 'blue widgets',
        ) );

        $this->assertSame(
            array( 'tagged_source', 'tagged_medium', 'tagged_campaign',
                   'tagged_ad', 'tagged_ad_type', 'tagged_terms' ),
            array_keys( $kept ),
            'The tracker must be able to report what the landing URL was tagged with.' );
    }

    /**
     * The other half of the split, and the reason it is worth doing: a request
     * can no longer assert an answer. Before the rename these names were
     * registered client-settable, so anything could post owa_source=... and
     * have it stored as the resolved source.
     */
    public function testTheAnswerIsNotSettableFromTheWire(): void
    {
        /*
         * Through admitRequestParams(), which is the gate log.php runs.
         *
         * It used to be rejectServerOwnedParams() -- a DENYLIST that refused
         * the names the server computed and passed everything else. These six
         * are not registered properties at all any more, because nothing
         * derives them at ingest: the cube pass does. So a denylist would now
         * let them straight through, while the allowlist refuses them for the
         * stronger reason that nothing declares them as settable.
         */
        $kept = Helpers::admitRequestParams( array(
            'source'       => 'forged',
            'medium'       => 'forged',
            'campaign'     => 'forged',
            'ad'           => 'forged',
            'ad_type'      => 'forged',
            'search_terms' => 'forged',
        ) );

        $this->assertSame(
            array(), $kept,
            'A request set the resolved attribution directly.' );
    }








    /**
     * No dimension id is derived in the property pipeline at all.
     *
     * This replaces an ordering assertion. campaign_id, ad_id, source_id and
     * referring_search_term_id used to be registered properties that picked
     * their value up by alternative_key, so the config had to resolve the
     * ANSWER before the id that hashed it -- register them the other way round
     * and an untagged visit hashed nothing and the dimension was silently wrong
     * rather than absent.
     *
     * Derivation now happens when a row is written, from content, by the
     * dimension that owns it. The ordering constraint is not satisfied, it is
     * gone: there is no longer anything in the pipeline whose order could be
     * wrong. Asserting the absence is what keeps it gone, since re-adding one of
     * these entries would silently reinstate the hazard.
     */
    public function testNoDimensionIdIsDerivedInThePipeline(): void
    {
        $config = json_decode(
            (string) file_get_contents( OWA_DIR . 'modules/Base/config/tracking_properties.json' ), true );

        $registered = array();
        foreach ( (array) $config as $scope => $properties ) {
            $registered = array_merge( $registered, array_keys( (array) $properties ) );
        }

        foreach ( array( 'location_id', 'source_id', 'host_id', 'ua_id', 'os_id',
                         'referer_id', 'document_id', 'ad_id', 'campaign_id',
                         'referring_search_term_id' ) as $id ) {

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
