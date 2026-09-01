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
 * The tracker now reports tagged_*: what the landing URL claimed. The server
 * resolves the answer into the bare name. Because the split follows the scope
 * a property is registered in, the existing wire filter enforces it with no
 * special-casing -- server-scope names are simply not settable from a request.
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
        $kept = Helpers::rejectServerOwnedParams( array(
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

    public function testATaggedSourceWinsOverTheReferer(): void
    {
        $event = $this->event( array(
            'tagged_source'   => 'Newsletter',
            'session_referer' => 'https://www.google.com/search?q=widgets',
        ) );

        $this->assertSame( 'newsletter', Helpers::resolveSource( null, $event ),
            'An explicit tag must beat a referer classification.' );
    }

    public function testAnUntaggedVisitIsClassifiedFromTheReferer(): void
    {
        $event = $this->event( array(
            'session_referer' => 'https://www.example-blog.com/post',
        ) );

        $this->assertSame( 'example-blog.com', Helpers::resolveSource( null, $event ) );
        $this->assertSame( 'referral', Helpers::resolveMedium( null, $event ) );
    }

    public function testASearchRefererIsOrganicSearchAndYieldsItsTerm(): void
    {
        $event = $this->event( array(
            'session_referer' => 'https://www.google.com/search?q=blue+widgets',
        ) );

        $this->assertSame( 'organic-search', Helpers::resolveMedium( null, $event ) );
        $this->assertSame( 'blue widgets', Helpers::resolveSearchTerms( null, $event ) );
    }

    /**
     * A known engine that sent no term withheld it -- https referrers usually
     * do -- which is a different fact from never having searched.
     */
    public function testAKnownEngineWithNoTermIsNotProvided(): void
    {
        $event = $this->event( array(
            'session_referer' => 'https://www.google.com/search?hl=en',
        ) );

        $this->assertSame( '(not provided)', Helpers::resolveSearchTerms( null, $event ) );
    }

    public function testATaggedTermWinsOverTheReferer(): void
    {
        $event = $this->event( array(
            'tagged_terms'    => 'Paid Widgets',
            'session_referer' => 'https://www.google.com/search?q=organic+widgets',
        ) );

        $this->assertSame( 'paid widgets', Helpers::resolveSearchTerms( null, $event ) );
    }

    /**
     * With no tag and no referer the declared defaults stand -- and 'direct' is
     * the medium default, not something a callback returns.
     */
    public function testADirectVisitFallsThroughToTheDeclaredDefault(): void
    {
        $event = $this->event( array() );

        $this->assertSame( 'direct', Helpers::resolveMedium( 'direct', $event ) );
        $this->assertSame( '(not set)', Helpers::resolveSource( '(not set)', $event ) );
    }

    /**
     * campaign, ad and ad_type have nothing to derive them from -- they exist
     * only because a URL was tagged. They are registered anyway: they reached
     * CampaignHandlers and AdHandlers on the wire for years while appearing in
     * no property map, so nothing declared their type and the wire filter had
     * no opinion about them.
     */
    public function testTheTagOnlyPropertiesAreCarriedFromTheirClaim(): void
    {
        $event = $this->event( array(
            'tagged_campaign' => ' summer ',
            'tagged_ad'       => ' creative-a ',
            'tagged_ad_type'  => ' cpc ',
        ) );

        $this->assertSame( 'summer',     Helpers::resolveCampaign( null, $event ) );
        $this->assertSame( 'creative-a', Helpers::resolveAd( null, $event ) );
        $this->assertSame( 'cpc',        Helpers::resolveAdType( null, $event ) );
    }

    public function testTheAnswersAreResolvedBeforeTheDimensionIdsThatReadThem(): void
    {
        /*
         * campaign_id, ad_id, source_id and referring_search_term_id pick their
         * value up by alternative_key. If the answers were registered after
         * them the ids would hash whatever was on the event first -- nothing,
         * on an untagged visit -- and the dimension would be silently wrong
         * rather than absent.
         */
        $order = array_flip( array_keys( Helpers::serverProperties() ) );

        foreach ( array( 'campaign_id'  => 'campaign',
                         'ad_id'        => 'ad',
                         'source_id'    => 'source',
                         'referring_search_term_id' => 'search_terms' ) as $id => $answer ) {

            $this->assertLessThan(
                $order[ $id ], $order[ $answer ],
                "$answer must be resolved before $id hashes it." );
        }
    }
}
