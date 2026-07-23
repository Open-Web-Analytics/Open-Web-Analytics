<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Association test: assert the fact row is actually LINKED to the dimension
 * entities the fan-out created — i.e. each owa_request FK column
 * (document_id, ua_id, referer_id, source_id, os_id, location_id, host_id,
 * visitor_id) points at the matching dimension row.
 *
 * DimensionIngestionTest proves the dimension ROWS exist (looked up by content)
 * and the fact ROW exists; this test closes the loop by proving the fact points
 * AT those rows. A refactor that created the dimensions but wired a FK to the
 * wrong id (or left it null) would pass DimensionIngestionTest and fail here.
 *
 * WHY resetDerivationFilters() FIRST:
 * The fact's derived dimension FKs are only correct for the FIRST event logged
 * in a PHP process. owa_trackingEventHelpers::setTrackerProperties() re-attaches
 * the generateDimensionId derivation filter on EVERY logEvent(), and
 * eventDispatch::filter() chains each listener's output into the next, so on the
 * Nth event a FK is hashed N times (setStringGuid(setStringGuid(...))) and no
 * longer equals the id the handler wrote the dimension row at. (That is why
 * DimensionIngestionTest anchors on content, not FK.) resetDerivationFilters()
 * restores the dispatcher's pristine, pre-first-event filter table so this fire
 * behaves exactly as the single event a production process logs — making the
 * FK columns trustworthy no matter how many events earlier tests fired.
 *
 * The assertions FOLLOW each FK to the row it references and check that row's
 * content, rather than looking a row up by content and comparing ids: some
 * shared content values (host/location '(not set)') have duplicate rows in this
 * schema, so only "resolve the row the fact points at" is unambiguous.
 */
final class DimensionAssociationTest extends IngestionTestCase
{
    public function testFactRowIsLinkedToItsDimensionEntities(): void
    {
        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();

        // Realistic referred pageview with a real UA, so every dimension a
        // pageview can populate is exercised in one shot: document, ua, referer,
        // source (derived from the referrer), os (browscap from the UA), plus
        // the shared host/location defaults.
        $page_url   = 'https://example.com/assoc/' . $guid;
        $referer    = 'https://ref' . $guid . '.example.net/landing';
        $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OWAassoc/' . $guid;

        $this->setServerUserAgent($user_agent);

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.referer', $referer, 'url');
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        // Make this fire behave as the first event in the process, so the fact's
        // derived FK columns are not over-hashed (see class docblock).
        $this->resetDerivationFilters();

        $result = $this->fireEvent('base.page_request', [
            'guid'            => $guid,
            'site_id'         => $site_id,
            'session_id'      => $session_id,
            'page_url'        => $page_url,
            'HTTP_USER_AGENT' => $user_agent,
            'is_new_session'  => true,
            'is_new_visitor'  => true,
            'visitor_id'      => $visitor_id,
            'HTTP_REFERER'    => $referer,
            'session_referer' => $referer,
        ]);
        $this->assertNotFalse($result, 'association page_request was dropped before persistence.');

        $derived_source = (string) $this->lastEvent()->get('source');
        $this->assertNotEmpty($derived_source, 'server did not derive a source from the referrer.');
        $this->trackForCleanup('base.source_dim', $derived_source, 'source_domain');

        $fact = $this->assertRowPersisted('base.request', $guid, 'id');

        // Follow each fact FK to the dimension row it references and assert that
        // row carries the content this beacon supplied — proving the LINK.
        $this->assertFactLinkedTo($fact, 'document_id', 'base.document',   'url',           $page_url);
        $this->assertFactLinkedTo($fact, 'ua_id',       'base.ua',         'ua',            $user_agent);
        $this->assertFactLinkedTo($fact, 'referer_id',  'base.referer',    'url',           $referer);
        $this->assertFactLinkedTo($fact, 'source_id',   'base.source_dim', 'source_domain', $derived_source);
        $this->assertFactLinkedTo($fact, 'os_id',       'base.os',         'name',          'Windows');

        // visitor_id is passed through verbatim (not a content-hashed dimension).
        $this->assertEquals(
            $visitor_id,
            (string) $fact->get('visitor_id'),
            'fact row visitor_id does not match the beacon visitor_id.'
        );

        // Shared defaults: the fact must still be linked to a real host/location
        // row (the '(not set)' rows in this environment), not left null.
        $this->assertFactLinkedTo($fact, 'host_id',     'base.host',         'host',    '(not set)');
        $this->assertFactLinkedTo($fact, 'location_id', 'base.location_dim', 'country', '(not set)');
    }

    /**
     * Follow the fact row's $fkCol to the $entity row it references and assert
     * that row's $col holds $value. Proves the fact is linked to the RIGHT
     * dimension, resolving the row by the FK id (not by content) so duplicate
     * content rows can't make a correct link look wrong.
     */
    private function assertFactLinkedTo(
        $fact,
        string $fkCol,
        string $entity,
        string $col,
        string $value
    ): void {
        $factFk = (string) $fact->get($fkCol);
        $this->assertNotSame('', $factFk, "fact row {$fkCol} is empty — not linked to {$entity}.");

        $dim = owa_coreAPI::entityFactory($entity);
        $dim->load($factFk, 'id');
        $this->assertTrue(
            $dim->wasPersisted(),
            "fact row {$fkCol}={$factFk} points at a {$entity} row that does not exist "
            . "— dangling association."
        );
        $this->assertSame(
            $value,
            (string) $dim->get($col),
            "fact row {$fkCol} points at a {$entity} row whose {$col} is "
            . "'{$dim->get($col)}', expected '{$value}'. The fact is linked to the wrong dimension."
        );
    }
}
