<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the base.feed_request pipeline.
 *
 * event_type base.feed_request -> owa_feedRequestHandlers -> base.feed_request
 * (table owa_feed_request), loaded back by the guid PK (column id). The handler
 * derives document_id (from page_url), ua_id (from HTTP_USER_AGENT) and host_id
 * (from host).
 *
 * There is no JS emitter for this type (feed requests come from a server-side
 * tracking path), but it is on the tracking_event_types whitelist and handled,
 * so the ingestion contract is worth locking.
 */
final class FeedRequestIngestionTest extends IngestionTestCase
{
    public function testFeedRequestPersistsRow(): void
    {
        $guid     = $this->uniqueGuid();
        $site_id  = md5('owa-test-site');
        $page_url = 'https://example.com/feed.xml';
        $this->trackForCleanup('base.feed_request', $guid, 'id');

        $result = $this->fireEvent('base.feed_request', [
            'guid'        => $guid,
            'site_id'     => $site_id,
            'page_url'    => $page_url,
            'feed_format' => 'rss2',
            'host'        => 'example.com',
        ]);
        $this->assertNotFalse(
            $result,
            'logEvent returned false — the feed request was dropped before persistence.'
        );

        $row = $this->assertRowPersisted('base.feed_request', $guid, 'id');

        $this->assertSame($site_id, $row->get('site_id'));
        $this->assertSame('rss2', $row->get('feed_format'));
        // document_id and host_id are content-hashed by the handler.
        // (loose compare: the hash is an int, the DB returns it as a string)
        $this->assertEquals(owa_lib::setStringGuid($page_url), $row->get('document_id'));
        $this->assertEquals(owa_lib::setStringGuid('example.com'), $row->get('host_id'));
    }
}
