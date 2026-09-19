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
    public function testTheResolversReadThroughToTheLandingUrl(): void
    {
        $ns    = $this->ns();
        $event = $this->event([
            'landing_url' => 'https://example.test/welcome?'
                . $ns . 'source=news&' . $ns . 'medium=email&' . $ns . 'campaign=summer',
        ]);

        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $this->assertSame('news',   $H::resolveSource(null, $event));
        $this->assertSame('email',  $H::resolveMedium(null, $event));
        $this->assertSame('summer', $H::resolveCampaign(null, $event));
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

    /** No landing URL, no tags: the referer still decides, as it always did. */
    public function testWithoutALandingUrlTheRefererStillDecides(): void
    {
        $event = $this->event([
            'session_referer' => 'https://www.bing.com/search?q=widgets',
        ]);

        $H = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $this->assertSame('bing.com', $H::resolveSource(null, $event));
        $this->assertSame('organic-search', $H::resolveMedium(null, $event));
    }
}
