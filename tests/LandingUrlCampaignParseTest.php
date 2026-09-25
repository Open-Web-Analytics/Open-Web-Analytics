<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * The server resolves campaign tags by parsing the landing URL.
 *
 * The tracker used to parse `campaignKeys` itself and send six tagged_*
 * parameters on every beacon of the session. It now carries the URL and the
 * parse happens here, which is what makes the answer re-derivable: a fix to the
 * parser, or a site changing `ns`, applies on reprocess instead of being frozen
 * in whatever a browser decided months ago.
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

    /** @return object an event carrying exactly these properties */
    private function event(array $properties)
    {
        $event = owa_coreAPI::supportClassFactory('base', 'event');
        $event->setEventType('base.page_request');
        $event->setProperties($properties);

        return $event;
    }

    /** A landing URL's tags are found, and found under the configured ns. */
    public function testTagsAreParsedOutOfTheLandingUrl(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'landing_url' => 'https://example.test/welcome?' . http_build_query([
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
            'landing_url' => 'https://example.test/welcome?'
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
     * THE COMPATIBILITY ONE. What an older tracker sent wins over the parse.
     *
     * Trackers are cached in browsers and installs upgrade at their own pace,
     * so beacons carrying tagged_* keep arriving long after the new tracker
     * ships. Preferring them is what makes this change invisible to those
     * installs -- and it is the right precedence anyway, since a value that was
     * actually transmitted beats one re-derived from evidence.
     *
     * The two deliberately disagree here, so the assertion cannot pass by both
     * paths returning the same thing.
     */
    public function testWhatTheTrackerSentWinsOverTheParse(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'tagged_source'   => 'from-the-tracker',
            'tagged_campaign' => 'old-tracker-campaign',
            'landing_url'     => 'https://example.test/x?'
                . $ns . 'source=from-the-url&' . $ns . 'campaign=url-campaign&'
                . $ns . 'medium=only-in-the-url',
        ]);

        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $this->assertSame('from-the-tracker',     $H::taggedValue($event, 'tagged_source'));
        $this->assertSame('old-tracker-campaign', $H::taggedValue($event, 'tagged_campaign'));

        // ...and a key the old tracker did NOT send still falls through.
        $this->assertSame('only-in-the-url', $H::taggedValue($event, 'tagged_medium'));
    }

    /** An empty parameter is not a value. */
    public function testAnEmptyParameterIsNotACampaign(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'landing_url' => 'https://example.test/x?' . $ns . 'campaign=&' . $ns . 'source=%20',
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
            'landing_url' => 'https://example.test/p?utm_source=newsletter&utm_medium=email'
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

        $first = $this->event([ 'site_id' => 'memo-site-one', 'landing_url' => $url ]);

        $this->assertSame('owa_answer', $H::taggedValue($first, 'tagged_source'),
            'the default map reads the ns-prefixed parameter');

        owa_coreAPI::configSingleton()->set('base', 'campaignKeys', [ 'source' => 'utm_source' ]);

        $second = $this->event([ 'site_id' => 'memo-site-two', 'landing_url' => $url ]);

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
            'landing_url' => 'https://example.test/p?utm_source=newsletter&' . $ns . 'medium=email',
        ]);

        $this->assertSame('newsletter', $H::taggedValue($event, 'tagged_source'),
            'the named role uses the named parameter');
        $this->assertSame('email', $H::taggedValue($event, 'tagged_medium'),
            'an unnamed role keeps its ns-prefixed name; a partial override must not '
          . 'silently disable the roles it does not mention');
    }
}
