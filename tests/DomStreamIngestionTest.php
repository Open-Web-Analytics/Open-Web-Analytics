<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the dom.stream pipeline.
 *
 * event_type dom.stream -> owa_domstreamHandlers -> base.domstream
 * (table owa_domstream), loaded back by the guid PK (column id). The tracker
 * batches dom.movement/dom.scroll/dom.keypress into the stream_events property
 * of a single dom.stream beacon; the handler stores that under `events` and
 * derives document_id from page_url.
 *
 * The dom.stream handler is registered by the domstream module at boot, and only
 * when that module is active. This test used to SKIP when it was not -- which is
 * the state of a fresh install, so it never ran on CI and reported green without
 * executing.
 *
 * It now attaches the handler itself when the module has not. That is the same
 * registration the module performs, and it makes the test say what it is
 * actually about: the dom.stream event reaching owa_domstream, not whether this
 * particular installation happens to have the feature switched on.
 */
final class DomStreamIngestionTest extends IngestionTestCase
{
    /** The dispatch is a singleton, so the handler is attached at most once. */
    private static $attached = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (owa_coreAPI::getSetting('domstream', 'is_active')) {

            // The module registered its own handler at boot; attaching a second
            // would run it twice for one event.
            return;
        }

        if (self::$attached) {
            return;
        }

        \OWA\Core\CoreAPI::getEventDispatch()->attach(
            'dom.stream',
            array(new \OWA\Module\Domstream\Handler\DomstreamHandlers, 'notify')
        );

        self::$attached = true;
    }

    public function testDomStreamPersistsRow(): void
    {
        $guid     = $this->uniqueGuid();
        $site_id  = md5('owa-test-site');
        $page_url = 'https://example.com/ingestion-domstream';
        $stream   = json_encode([['type' => 'dom.scroll', 'y' => 100]]);
        $this->trackForCleanup('base.domstream', $guid, 'id');

        $result = $this->fireEvent('dom.stream', [
            'guid'          => $guid,
            'site_id'       => $site_id,
            'page_url'      => $page_url,
            'stream_events' => $stream,
            'duration'      => 4200,
            'page_width'    => 1280,
            'page_height'   => 3000,
        ]);
        $this->assertNotFalse(
            $result,
            'logEvent returned false — the dom.stream was dropped before persistence.'
        );

        $row = $this->assertRowPersisted('base.domstream', $guid, 'id');

        $this->assertSame($site_id, $row->get('site_id'));
        // The handler copies stream_events into `events`; OWA HTML-encodes stored
        // string values on write, so decode before comparing.
        $this->assertSame($stream, html_entity_decode($row->get('events'), ENT_QUOTES));
        // document_id is content-hashed (loose compare: int vs DB string).
        $this->assertEquals(owa_lib::setStringGuid($page_url), $row->get('document_id'));
    }
}
