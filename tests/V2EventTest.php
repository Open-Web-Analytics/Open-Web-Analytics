<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The v2 event vocabulary and the derived event id.
 *
 * Pure functions, so these run without a database. They are the highest-value
 * tests in the v2 work: the id is CONTENT-DERIVED, so ingest, the migrator and
 * any future writer have to agree on the formula exactly. A change here that
 * nobody notices does not corrupt a row -- it silently stops a redelivered
 * beacon from collapsing, and the event is counted twice.
 */
final class V2EventTest extends TestCase
{
    private const SITE    = 'site-abc';
    private const VISITOR = '1758000000123456789';
    private const SESSION = '1758000000987654321';
    private const TS      = '1758000000123456';

    private function id(array $override = []): int
    {
        $a = $override + [
            'site' => self::SITE, 'visitor' => self::VISITOR,
            'session' => self::SESSION, 'ts' => self::TS, 'name' => 'page_view',
        ];

        return \OWA\Module\Base\Classes\V2Event::id(
            $a['site'], $a['visitor'], $a['session'], $a['ts'], $a['name']);
    }

    public function testTheSameInputsAlwaysDeriveTheSameId(): void
    {
        $this->assertSame($this->id(), $this->id(),
            'A redelivered beacon must derive the same id or it cannot collapse on the primary key.');
    }

    /**
     * Each input has to MOVE the id. A formula that ignored one of them would
     * pass every same-in-same-out test and still collide two different events.
     */
    public function testEveryInputChangesTheId(): void
    {
        $base = $this->id();

        foreach ([
            'site'    => 'site-xyz',
            'visitor' => '1758000000000000001',
            'session' => '1758000000000000002',
            'ts'      => '1758000000123457',
            'name'    => 'session_start',
        ] as $field => $value) {

            $this->assertNotSame($base, $this->id([$field => $value]),
                sprintf('Changing %s must change the derived id.', $field));
        }
    }

    /**
     * The three events one beacon expands into share every input but the name,
     * so the name alone has to separate them -- which is what lets the
     * expansion run with no counter and in no particular order.
     */
    public function testOneBeaconsThreeEventsGetThreeIds(): void
    {
        $ids = [
            $this->id(['name' => 'page_view']),
            $this->id(['name' => 'session_start']),
            $this->id(['name' => 'first_visit']),
        ];

        $this->assertCount(3, array_unique($ids));
    }

    /**
     * 63 bits and always positive: the column is a signed BIGINT, and a
     * negative id would be stored but would not match on read-back under any
     * comparison a caller would think to write.
     */
    public function testIdFitsA63BitSignedColumn(): void
    {
        for ($i = 0; $i < 200; $i++) {

            $id = $this->id(['ts' => (string) (1758000000000000 + $i)]);

            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $id);
            $this->assertLessThanOrEqual(PHP_INT_MAX, $id);
            $this->assertLessThan(1 << 62, $id >> 1, 'id must fit in 63 bits.');
        }
    }

    public function testV1TypesMapToTheV2Vocabulary(): void
    {
        $map = [
            'base.page_request'       => 'page_view',
            'dom.click'               => 'click',
            'ecommerce.transaction'   => 'purchase',
            'track.action'            => 'custom_event',
        ];

        foreach ($map as $v1 => $v2) {
            $this->assertSame($v2, \OWA\Module\Base\Classes\V2Event::name($v1));
        }
    }

    /**
     * A name already in the v2 vocabulary passes through untouched -- which is
     * what lets the tracker send `scroll` or `file_download` without a line in
     * the map for each.
     */
    public function testAV2NameIsNotRewritten(): void
    {
        foreach (['scroll', 'file_download', 'form_submit', 'user_engagement'] as $name) {
            $this->assertSame($name, \OWA\Module\Base\Classes\V2Event::name($name));
        }
    }

    /**
     * An unmapped v1 name must not reach the column carrying a v1 namespace,
     * or the dot would have to be special-cased everywhere downstream.
     */
    public function testAnUnmappedNamespacedNameIsFlattened(): void
    {
        $this->assertSame('dom_keypress', \OWA\Module\Base\Classes\V2Event::name('dom.keypress'));
    }

    public function testEventTypeFitsItsColumn(): void
    {
        foreach (\OWA\Module\Base\Classes\V2Event::TYPE_MAP as $name) {
            $this->assertLessThanOrEqual(24, strlen($name),
                'event_type is VARCHAR(24); a longer name would be trimmed and stop matching.');
        }

        foreach (['session_start', 'first_visit', 'user_engagement', 'view_search_results'] as $name) {
            $this->assertLessThanOrEqual(24, strlen($name));
        }
    }

    public function testDomstreamAndFeedRequestAreNotEvents(): void
    {
        $this->assertFalse(\OWA\Module\Base\Classes\V2Event::isStorable('dom.stream'),
            'A recording chunk is an attachment to a page view, not an event.');
        $this->assertFalse(\OWA\Module\Base\Classes\V2Event::isStorable('base.feed_request'));
        $this->assertTrue(\OWA\Module\Base\Classes\V2Event::isStorable('base.page_request'));
    }

    public function testUrlSplitsIntoHostPathAndQuery(): void
    {
        $parsed = \OWA\Module\Base\Classes\V2Event::parseUrl(
            'https://Example.COM/a/b?x=1&y=2#frag');

        $this->assertSame('example.com', $parsed['host'], 'host is lowercased so it groups.');
        $this->assertSame('/a/b', $parsed['path']);
        $this->assertSame('x=1&y=2', $parsed['query'], 'no leading ? and no fragment.');
    }

    /**
     * The site root IS a path. Grouping it under NULL would hide the home page
     * from the landing-page report, which is usually its top row.
     */
    public function testARootUrlHasAPathOfSlash(): void
    {
        $this->assertSame('/', \OWA\Module\Base\Classes\V2Event::parseUrl('https://example.com')['path']);
    }

    public function testAbsenceIsNullNotEmptyString(): void
    {
        $parsed = \OWA\Module\Base\Classes\V2Event::parseUrl('https://example.com/a');
        $this->assertNull($parsed['query'], 'A URL with no query has no query, and absence is NULL.');

        foreach (\OWA\Module\Base\Classes\V2Event::parseUrl('') as $part) {
            $this->assertNull($part);
        }
    }
}
