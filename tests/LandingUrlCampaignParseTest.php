<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * The server resolves campaign tags by parsing the landing beacon's own URL.
 *
 * The tracker used to parse `campaignKeys` itself and send six tagged_*
 * parameters on every beacon of the session. Then it sent landing_url instead and
 * the parse moved here -- which is what makes the answer re-derivable: a fix to
 * the parser, or a site changing `ns`, applies on reprocess instead of being
 * frozen in whatever a browser decided months ago.
 *
 * NOW IT SENDS NEITHER. On the session-starting beacon page_location IS the
 * landing URL -- the tracker set landing_url from getCurrentUrl() and
 * page_location comes from the same call -- and that beacon is the only one this
 * parse ever ran on, because taggedValue() gates on is_new_session_start. So it
 * reads the URL the event already carries, and a session-scoped copy stops riding
 * every beacon for the life of every session. GA carries neither: no GA cookie
 * holds a URL, and session source is fixed by the session's first event.
 *
 * page_location rather than page_query, deliberately: the query has the
 * Profile's dropped parameters removed, so a site filtering owa_source out of its
 * query strings would lose the very tag this reads.
 */
final class LandingUrlCampaignParseTest extends IngestionTestCase
{
    private function ns(): string
    {
        $ns = (string) owa_coreAPI::getSetting('base', 'ns');

        $this->assertNotSame('', $ns,
            'the campaign parameter names are ns + suffix, so an empty ns would make '
            . 'every assertion here pass against bare "source"/"medium" names');

        return $ns;
    }

    /**
     * @return object an event carrying exactly these properties, on the LANDING
     *                beacon -- the only beacon the parse runs on, so every case
     *                here has to be one
     */
    private function event(array $properties)
    {
        $event = owa_coreAPI::supportClassFactory('base', 'event');
        $event->setEventType('base.page_request');
        $event->setProperties($properties + ['is_new_session_start' => true]);

        return $event;
    }

    /** A landing URL's tags are found, and found under the configured ns. */
    public function testTagsAreParsedOutOfTheLandingUrl(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'page_location' => 'https://example.test/welcome?' . http_build_query([
                $ns . 'source'       => 'news',
                $ns . 'medium'       => 'email',
                $ns . 'campaign'     => 'summer',
                $ns . 'search_terms' => 'blue widgets',
                $ns . 'ad'           => 'banner-1',
                $ns . 'ad_type'      => 'display',
            ]),
        ]);

        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $this->assertSame('news',        $H::taggedValue($event, 'tagged_source'));
        $this->assertSame('email',       $H::taggedValue($event, 'tagged_medium'));
        $this->assertSame('summer',      $H::taggedValue($event, 'tagged_campaign'));
        $this->assertSame('blue widgets', $H::taggedValue($event, 'tagged_terms'));
        $this->assertSame('banner-1',    $H::taggedValue($event, 'tagged_ad'));
        $this->assertSame('display',     $H::taggedValue($event, 'tagged_ad_type'));
    }

    /**
     * And the resolvers use it, which is the part that reaches a fact row.
     *
     * taggedValue() returning the right string proves nothing on its own if the
     * resolvers still read the event directly.
     */
    public function testTheParseReadsThroughToTheLandingUrl(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'page_location' => 'https://example.test/welcome?'
                . $ns . 'source=news&' . $ns . 'medium=email&' . $ns . 'campaign=summer',
        ]);

        /*
         * Through taggedValue(), not the resolvers. resolveSource() and friends
         * are gone: they classified at ingest and reached no column, and the
         * classification is the cube pass's now. What survives -- and what this
         * file is about -- is the PARSE: the tags coming out of the landing URL
         * as tagged_* claims.
         */
        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $this->assertSame('news',   $H::taggedValue($event, 'tagged_source'));
        $this->assertSame('email',  $H::taggedValue($event, 'tagged_medium'));
        $this->assertSame('summer', $H::taggedValue($event, 'tagged_campaign'));
    }

    /**
     * WHAT THE BEACON CLAIMS IS IGNORED, and this asserted the opposite.
     *
     * A tagged_* the beacon sent used to win over the parse, on the reasoning
     * that trackers are cached in browsers and a transmitted value beats one
     * re-derived from evidence. But no recorded tracker has ever sent one --
     * neither wire contract carries a tagged_* field -- and with no wire key in
     * the registry the gate refuses the name outright, so the branch was
     * unreachable as well as unused.
     *
     * What it would have meant, had anything sent one: a beacon asserting its own
     * attribution. The URL is the evidence; the tags are the server's reading.
     *
     * The two deliberately disagree here, so the assertion cannot pass by both
     * paths returning the same thing.
     */
    public function testWhatTheBeaconClaimsIsIgnored(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'tagged_source'   => 'from-the-beacon',
            'tagged_campaign' => 'beacon-campaign',
            'page_location'   => 'https://example.test/x?'
                . $ns . 'source=from-the-url&' . $ns . 'campaign=url-campaign&'
                . $ns . 'medium=only-in-the-url',
        ]);

        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $this->assertSame('from-the-url',    $H::taggedValue($event, 'tagged_source'));
        $this->assertSame('url-campaign',    $H::taggedValue($event, 'tagged_campaign'));
        $this->assertSame('only-in-the-url', $H::taggedValue($event, 'tagged_medium'));
    }

    /** An empty parameter is not a value. */
    public function testAnEmptyParameterIsNotACampaign(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'page_location' => 'https://example.test/x?' . $ns . 'campaign=&' . $ns . 'source=%20',
        ]);

        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $this->assertNull($H::taggedValue($event, 'tagged_campaign'),
            'owa_campaign= must not read as a campaign named ""');
        $this->assertNull($H::taggedValue($event, 'tagged_source'),
            'a whitespace-only value is the same claim');
    }

    /*
     * testWithoutALandingUrlTheRefererStillDecides WAS HERE. With no tags, the
     * referer decides source and medium -- bing.com and organic-search -- and
     * that is no longer decided at ingest. MediumStep and SourceStep classify
     * the referer host in the cube pass, and CubeBuildTest asserts
     * organic-search, referral and direct on built rows.
     */

    /**
     * A Property can name the parameters its links actually use.
     *
     * The tracker used to own this: setCampaignSourceKey('utm_source') remapped
     * the key it parsed. The parse moved server-side and the server built its
     * own ns-prefixed list, so that setter renamed a key nothing read and a site
     * using utm_* silently got no attribution at all.
     *
     * It is a setting now, resolved at profile scope so the chain walks Profile
     * -> Property -> Install: one convention by default, and a Property that
     * arrived from a GA setup overrides it.
     */
    public function testAPropertyCanUseGoogleSCampaignKeys(): void
    {
        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        owa_coreAPI::configSingleton()->set('base', 'campaignKeys', [
            'source'       => 'utm_source',
            'medium'       => 'utm_medium',
            'campaign'     => 'utm_campaign',
            'search_terms' => 'utm_term',
            'ad'           => 'utm_content',
        ]);

        $event = $this->event([
            'site_id'     => 'ga-keys-site',
            'page_location' => 'https://example.test/p?utm_source=newsletter&utm_medium=email'
                . '&utm_campaign=spring&utm_term=shoes&utm_content=banner1'
                . '&' . $this->ns() . 'source=ignored',
        ]);

        $this->assertSame('newsletter', $H::taggedValue($event, 'tagged_source'));
        $this->assertSame('email',      $H::taggedValue($event, 'tagged_medium'));
        $this->assertSame('spring',     $H::taggedValue($event, 'tagged_campaign'));
        $this->assertSame('shoes',      $H::taggedValue($event, 'tagged_terms'));
        $this->assertSame('banner1',    $H::taggedValue($event, 'tagged_ad'));

        /*
         * And the ns-prefixed name stops being read. Without this the case
         * would pass on a parser that read BOTH conventions, which is not the
         * same guarantee -- a site with a stray owa_source on a link would get
         * two different answers depending on parameter order.
         */
        $this->assertNotSame('ignored', $H::taggedValue($event, 'tagged_source'));
    }

    /**
     * THE SAME LANDING URL PARSES DIFFERENTLY FOR TWO PROPERTIES.
     *
     * The parse is memoised, and the key map is per-Property now, so the memo
     * has to be keyed on the site as well as the URL. Keyed on the URL alone --
     * which is how it was written, because the map used to be installation-wide
     * -- the first Property's answer is handed to the second, and a site reading
     * utm_source silently inherits whatever the site before it resolved.
     *
     * Two Properties cannot be given different settings from here, so the
     * setting is changed BETWEEN the two parses, which exercises exactly the
     * same path: a second site asking about a URL the memo already holds.
     */
    public function testTheMemoDoesNotHandOnePropertysAnswerToAnother(): void
    {
        $H   = '\OWA\Module\Base\Classes\TrackingEventHelpers';
        $ns  = $this->ns();
        $url = 'https://example.test/shared?' . $ns . 'source=owa_answer&utm_source=utm_answer';

        owa_coreAPI::configSingleton()->set('base', 'campaignKeys', []);

        $first = $this->event([ 'site_id' => 'memo-site-one', 'page_location' => $url ]);

        $this->assertSame('owa_answer', $H::taggedValue($first, 'tagged_source'),
            'the default map reads the ns-prefixed parameter');

        owa_coreAPI::configSingleton()->set('base', 'campaignKeys', [ 'source' => 'utm_source' ]);

        $second = $this->event([ 'site_id' => 'memo-site-two', 'page_location' => $url ]);

        $this->assertSame('utm_answer', $H::taggedValue($second, 'tagged_source'),
            'the second site got the first site\'s cached answer, so the parse memo is '
          . 'keyed on the URL alone');
    }

    /** A role the setting omits keeps its ns-prefixed name rather than vanishing. */
    public function testAPartialOverrideIsPartialRatherThanDestructive(): void
    {
        $H  = '\OWA\Module\Base\Classes\TrackingEventHelpers';
        $ns = $this->ns();

        owa_coreAPI::configSingleton()->set('base', 'campaignKeys', [
            'source' => 'utm_source',
        ]);

        $event = $this->event([
            'site_id'     => 'partial-keys-site',
            'page_location' => 'https://example.test/p?utm_source=newsletter&' . $ns . 'medium=email',
        ]);

        $this->assertSame('newsletter', $H::taggedValue($event, 'tagged_source'),
            'the named role uses the named parameter');
        $this->assertSame('email', $H::taggedValue($event, 'tagged_medium'),
            'an unnamed role keeps its ns-prefixed name; a partial override must not '
          . 'silently disable the roles it does not mention');
    }
}
