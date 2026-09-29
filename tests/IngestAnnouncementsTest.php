<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * v2 ingest announces what it just materialised.
 *
 * base.new_session used to be raised by v1's SessionHandlers, two hops
 * downstream of the beacon -- page_request, then page_request_logged, then the
 * v1 session write, then the announcement. Every hop was v1 machinery kept
 * alive to deliver one signal to NotifyHandlers. v2 knows all three facts at
 * ingest, because it is what decides them.
 *
 * SYNCHRONOUS AND UNQUEUED. EventDispatch::notify() calls listeners in-process
 * and logs when there are none; the queue is a RETRY queue, reached only when a
 * handler returns EVENT_FAILED. So an announcement nobody listens to costs an
 * array lookup, and none of these accumulate.
 */
final class IngestAnnouncementsTest extends IngestionTestCase
{
    /** @var array<string,int> announcement type => times seen */
    private $seen = array();

    /** @var array<string,array> announcement type => the properties it carried */
    private $carried = array();

    /** @var string */
    private $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);
    }

    private function listen(array $types): void
    {
        owa_coreAPI::getEventDispatch()->attach($types, array($this, 'record'));
    }

    /** @param object $event */
    public function record($event)
    {
        $type = $event->getEventType();

        $this->seen[$type] = ($this->seen[$type] ?? 0) + 1;
        $this->carried[$type] = $event->getProperties();

        return OWA_EHS_EVENT_HANDLED;
    }

    /** One page view through the real ingest path. */
    private function firePageView(array $override = array()): void
    {
        $this->fireEvent('base.page_request', $override + array(
            'site_id'                => $this->site,
            'visitor_id'             => $this->uniqueGuid(),
            'session_id'             => $this->uniqueSessionId(),
            'page_url'               => 'https://owa-test-site/announce',
            'page_location'          => 'https://owa-test-site/announce',
            'page_title'             => 'Announce',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
            'num_prior_sessions'     => 0,
        ));
    }

    /**
     * A first-ever page view announces all three, once each.
     *
     * The counts matter as much as the presence: a session that announced twice
     * would send two emails, which is the failure mode the request-scoped flags
     * exist to prevent.
     */
    public function testAFirstVisitAnnouncesSessionVisitorAndPageView(): void
    {
        $this->listen(array('base.new_session', 'base.new_visitor', 'base.new_page_view'));

        $this->firePageView();

        $this->assertSame(1, $this->seen['base.new_session'] ?? 0, 'one session announcement');
        $this->assertSame(1, $this->seen['base.new_visitor'] ?? 0, 'one visitor announcement');
        $this->assertSame(1, $this->seen['base.new_page_view'] ?? 0, 'one page view announcement');
    }

    /**
     * And it carries what the announcement email actually reads.
     *
     * The template reads the BEACON's properties, not the stored row's, so the
     * announcement passes the incoming event through rather than rebuilding it
     * from columns.
     */
    public function testTheAnnouncementCarriesWhatTheEmailReads(): void
    {
        $this->listen(array('base.new_session'));

        $this->firePageView();

        $carried = $this->carried['base.new_session'] ?? array();

        foreach (array('visitor_id', 'page_url', 'page_title') as $field) {
            $this->assertArrayHasKey($field, $carried,
                'new_session_email reads ' . $field . ' off the event');
        }
    }

    /**
     * A page view that starts NO session announces only the page view.
     *
     * Every event of a session used to be a candidate for the announcement,
     * which is why the flags were split in the first place. Asserted here so
     * the marker-driven version cannot regress to that.
     */
    public function testAnOrdinaryPageViewAnnouncesOnlyItself(): void
    {
        $this->listen(array('base.new_session', 'base.new_visitor', 'base.new_page_view'));

        $this->firePageView(array(
            'is_new_session_start'   => false,
            'is_new_visitor_created' => false,
        ));

        $this->assertSame(0, $this->seen['base.new_session'] ?? 0);
        $this->assertSame(0, $this->seen['base.new_visitor'] ?? 0);
        $this->assertSame(1, $this->seen['base.new_page_view'] ?? 0);
    }
}
