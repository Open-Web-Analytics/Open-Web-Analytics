<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the base.page_request (page view) pipeline.
 *
 * event_type base.page_request -> owa_requestHandlers -> base.request
 * (table owa_request), loaded back by the guid PK (column id). This is the
 * core pageview event emitted by the tracker's trackPageView().
 */
final class PageRequestIngestionTest extends IngestionTestCase
{
    public function testPageRequestPersistsRequestRow(): void
    {
        $guid     = $this->uniqueGuid();
        $site_id  = md5('owa-test-site');
        $page_url = 'https://example.com/ingestion-pageview';
        $this->trackForCleanup('base.request', $guid, 'id');

        $result = $this->fireEvent('base.page_request', [
            'guid'       => $guid,
            'site_id'    => $site_id,
            'page_url'   => $page_url,
            'page_title' => 'Ingestion Test Page',
            'prior_page' => 'https://example.com/prev',
        ]);
        $this->assertNotFalse(
            $result,
            'logEvent returned false — the page request was dropped before persistence.'
        );

        $row = $this->assertRowPersisted('base.request', $guid, 'id');

        $this->assertSame($site_id, $row->get('site_id'));
        // The handler derives prior_document_id from prior_page via setStringGuid.
        // (loose compare: the hash is an int, the DB returns it as a string)
        $this->assertEquals(
            owa_lib::setStringGuid('https://example.com/prev'),
            $row->get('prior_document_id')
        );
    }
}
