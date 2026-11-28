<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * v2 ingest announces what it just materialised. It knows all three facts at
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
    private $seen = [];

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
        \OWA\Core\CoreAPI::getEventDispatch()->attach($types, [$this, 'record']);
    }

    /** @param object $event */
    public function record($event)
    {
        $type = $event->getEventType();

        $this->seen[$type] = ($this->seen[$type] ?? 0) + 1;

        return OWA_EHS_EVENT_HANDLED;
    }

    /** One page view through the real ingest path. */
    private function firePageView(array $override = []): void
    {
        $this->fireEvent('base.page_request', $override + [
            'site_id'                => $this->site,
            'visitor_id'             => $this->uniqueGuid(),
            'session_id'             => $this->uniqueSessionId(),
            'page_url'               => 'https://owa-test-site/announce',
            'page_location'          => 'https://owa-test-site/announce',
            'page_title'             => 'Announce',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
            'num_prior_sessions'     => 0,
        ]);
    }

    /**
     * A first-ever page view announces all three, once each.
     *
     * The counts matter as much as the presence: a listener hears a session
     * once.
     */
    public function testAFirstVisitAnnouncesSessionVisitorAndPageView(): void
    {
        $this->listen(['base.new_session', 'base.new_visitor', 'base.new_page_view']);

        $this->firePageView();

        $this->assertSame(1, $this->seen['base.new_session'] ?? 0, 'one session announcement');
        $this->assertSame(1, $this->seen['base.new_visitor'] ?? 0, 'one visitor announcement');
        $this->assertSame(1, $this->seen['base.new_page_view'] ?? 0, 'one page view announcement');
    }

    /**
     * A page view that starts NO session announces only the page view: the
     * announcement follows the marker, not every event of a session.
     */
    public function testAnOrdinaryPageViewAnnouncesOnlyItself(): void
    {
        $this->listen(['base.new_session', 'base.new_visitor', 'base.new_page_view']);

        $this->firePageView([
            'is_new_session_start'   => false,
            'is_new_visitor_created' => false,
        ]);

        $this->assertSame(0, $this->seen['base.new_session'] ?? 0);
        $this->assertSame(0, $this->seen['base.new_visitor'] ?? 0);
        $this->assertSame(1, $this->seen['base.new_page_view'] ?? 0);
    }
}
