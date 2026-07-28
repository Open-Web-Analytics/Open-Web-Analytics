<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the dom.click pipeline.
 *
 * event_type dom.click -> owa_clickHandlers -> base.click (table owa_click),
 * loaded back by the guid PK (column id). Asserts the handler-derived columns:
 * document_id (from page_url), ua_id (from HTTP_USER_AGENT) and position
 * (click_x concatenated with click_y).
 */
final class ClickIngestionTest extends IngestionTestCase
{
    public function testClickPersistsClickRow(): void
    {
        $guid       = $this->uniqueGuid();
        $site_id    = md5('owa-test-site');
        $page_url   = 'https://example.com/ingestion-click';
        $target_url = 'https://example.com/target';
        $ua         = $_SERVER['HTTP_USER_AGENT'];
        $this->trackForCleanup('base.click', $guid, 'id');

        $result = $this->fireEvent('dom.click', [
            'guid'            => $guid,
            'site_id'         => $site_id,
            'page_url'        => $page_url,
            'target_url'      => $target_url,
            'click_x'         => 12,
            'click_y'         => 34,
            'dom_element_tag' => 'a',
            'HTTP_USER_AGENT' => $ua,
        ]);
        $this->assertNotFalse(
            $result,
            'logEvent returned false — the click was dropped before persistence.'
        );

        $row = $this->assertRowPersisted('base.click', $guid, 'id');

        $this->assertSame($site_id, $row->get('site_id'));
        // document_id and ua_id are content-hashed by the handler.
        // (loose compare: the hash is an int, the DB returns it as a string)
        $this->assertEquals(owa_lib::setStringGuid($page_url), $row->get('document_id'));
        $this->assertEquals(owa_lib::setStringGuid($ua), $row->get('ua_id'));
        // position is click_x concatenated with click_y (stored as a group-by key).
        $this->assertEquals('1234', $row->get('position'));
    }
}
