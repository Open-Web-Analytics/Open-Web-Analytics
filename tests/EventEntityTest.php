<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The shape of owa_event.
 *
 * The first assertion is the load-bearing one: EXCHANGE PARTITION refuses two
 * tables that differ anywhere, and the staging table is built from owa_event,
 * so owa_event drifting from owa_event_raw is what stops the pass publishing
 * anything. Inheritance makes that hard to do by accident; this makes it
 * impossible to do silently.
 */
final class EventEntityTest extends TestCase
{
    private function event()
    {
        return owa_coreAPI::entityFactory('base.event');
    }

    private function raw()
    {
        return owa_coreAPI::entityFactory('base.event_raw');
    }

    public function testItLeadsWithRawsColumnsInRawsOrder(): void
    {
        $raw   = $this->raw()->getColumns();
        $event = $this->event()->getColumns();

        $this->assertSame($raw, array_slice($event, 0, count($raw)),
            'owa_event must open with owa_event_raw verbatim: same columns, same order. '
          . 'EXCHANGE PARTITION compares the two tables column by column.');
    }

    public function testTheSixteenDerivedColumns(): void
    {
        $derived = array_slice($this->event()->getColumns(), count($this->raw()->getColumns()));

        $this->assertSame([
            'source', 'medium', 'campaign', 'ad', 'search_terms',
            'landing_page_location', 'landing_page_path', 'landing_page_query',
            'landing_page_title', 'is_exit',
            'acq_source', 'acq_medium', 'acq_campaign', 'acq_ad', 'acq_search_terms',
            'built_at',
        ], $derived);
    }

    public function testItIsPartitionedOnTheSameColumnAsRaw(): void
    {
        // The swap is per partition, so the two tables have to cut on the same
        // column or a partition of one does not correspond to a range of the
        // other.
        $this->assertSame('yyyymmdd', $this->event()->getPartitionColumn());
        $this->assertSame($this->raw()->getPartitionColumn(), $this->event()->getPartitionColumn());
    }

    public function testItIsNotAFactTable(): void
    {
        $this->assertNotInstanceOf(\OWA\Core\Entity\FactTable::class, $this->event());
        $this->assertEmpty($this->event()->getAllForeignKeys(),
            'No joins in the reporting path is the point of the single table.');
    }

    public function testResolutionsAreNotNullAndCopiesAreNullable(): void
    {
        $entity = $this->event();

        // The pass always produces one of these, so a NULL arriving in one is a
        // bug to fail on rather than to store.
        foreach (['source', 'medium', 'acq_source', 'acq_medium', 'acq_campaign', 'acq_ad'] as $name) {
            $this->assertNotEmpty($entity->getColumn($name)->is_not_null,
                "$name is resolved by the pass and always has a value");
            $this->assertEmpty($entity->getColumn($name)->nullable, "$name must not be nullable");
        }

        // Raw declares every one of these sources nullable, so the pass copies
        // NULLs. Under STRICT_ALL_TABLES a NULL into a NOT NULL column aborts
        // the statement: one page with no title, and the partition rebuild
        // fails.
        foreach ([
            'campaign', 'ad', 'search_terms', 'landing_page_location',
            'landing_page_path', 'landing_page_query', 'landing_page_title',
            'acq_search_terms',
        ] as $name) {
            $this->assertNotEmpty($entity->getColumn($name)->nullable,
                "$name is copied from a nullable column and must be nullable");
        }
    }

    public function testEveryCopiedColumnIsAtLeastAsWideAsItsSource(): void
    {
        // The pass copies these straight across, so they need no clamp -- which
        // holds only while the destination is as wide as the source. Widening
        // page_title alone would make a long title abort the rebuild under
        // STRICT_ALL_TABLES.
        $entity = $this->event();

        foreach ([
            'campaign'              => 'tagged_campaign',
            'ad'                    => 'tagged_ad',
            'search_terms'          => 'tagged_search_terms',
            'landing_page_location' => 'page_location',
            'landing_page_path'     => 'page_path',
            'landing_page_query'    => 'page_query',
            'landing_page_title'    => 'page_title',
        ] as $destination => $source) {

            $this->assertGreaterThanOrEqual(
                $entity->getColumn($source)->maxLength(),
                $entity->getColumn($destination)->maxLength(),
                "$destination is copied from $source and must be at least as wide");
        }
    }

    public function testSourceHoldsAnyLegalDomainName(): void
    {
        // referer_host is parsed and length-checked at ingest, so anything that
        // reaches source is a domain name or NULL. The column has to hold the
        // longest one there can be.
        foreach (['source', 'acq_source', 'referer_host'] as $name) {
            $this->assertGreaterThanOrEqual(
                \OWA\Module\Base\Classes\V2Event::MAX_HOSTNAME,
                $this->event()->getColumn($name)->maxLength(), $name);
        }
    }

    public function testIsExitIsATwoValuedBoolean(): void
    {
        $column = $this->event()->getColumn('is_exit');

        $this->assertNotEmpty($column->is_not_null);
        $this->assertEmpty($column->nullable);
        $this->assertSame(0, $column->default_value,
            'A nullable boolean holds three values and GROUP BY gives each a bucket.');
    }

    public function testBuiltAtIsAlwaysWritten(): void
    {
        $this->assertNotEmpty($this->event()->getColumn('built_at')->is_not_null,
            'The pass is the only writer and always knows when it ran.');
    }

    public function testTheUnresolvedSentinelCannotBeTyped(): void
    {
        $sentinel = \OWA\Module\Base\Classes\V2Event::UNRESOLVED;

        $this->assertSame("\x1A", $sentinel);

        // The half that makes it mean anything: an observed value cannot
        // contain it, so a forged tag cannot claim the pipeline failed.
        $this->assertSame('', \OWA\Module\Base\Classes\V2Event::strip($sentinel));
        $this->assertSame('google', \OWA\Module\Base\Classes\V2Event::strip("goo\x1Agle"));
    }

    public function testStripKeepsRealText(): void
    {
        // Whitespace is whitespace by intent; removing a tab joins two words.
        $this->assertSame("a\tb\nc", \OWA\Module\Base\Classes\V2Event::strip("a\tb\nc"));

        // Matched as bytes, so malformed UTF-8 is trimmed rather than erasing
        // the value, and multibyte text survives whole.
        $this->assertSame('日本語', \OWA\Module\Base\Classes\V2Event::strip('日本語'));
        $this->assertSame('👍', \OWA\Module\Base\Classes\V2Event::strip('👍'));
        $this->assertSame("\xC3", \OWA\Module\Base\Classes\V2Event::strip("\xC3"));

        $this->assertNull(\OWA\Module\Base\Classes\V2Event::strip(null));
    }
}
