<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_feed_request is a fact table, and is selected as one.
 *
 * It is an event filed under a yyyymmdd that references dimension rows, but its
 * entity extended Entity rather than FactTable -- and three separate things
 * pick fact tables out by class:
 *
 *   - PartitionsCli::factTables(), so it was never partitioned, which means a
 *     retention window never reached it
 *   - RederiveDimensionIdsCli, so its document_id/ua_id/host_id/os_id were
 *     never repointed when dimension ids were re-derived to 63 bits
 *   - Update014, so its visitor_id was never indexed
 *
 * None of those named the table, so none of them could be seen to be missing
 * it. This pins the property they all read instead.
 */
final class FeedRequestIsAFactTableTest extends TestCase
{
    private function entity()
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.feed_request' );
    }

    public function testItIsAFactTable(): void
    {
        $this->assertInstanceOf(
            \OWA\Core\Entity\FactTable::class,
            $this->entity(),
            'feed_request must be a FactTable: partitioning, the dimension-id conversion '
          . 'and the visitor_id index all select on exactly this.' );
    }

    public function testItDeclaresYyyymmddAsItsPartitionColumn(): void
    {
        $this->assertSame(
            'yyyymmdd',
            $this->entity()->getPartitionColumn(),
            'Without a partition column the table cannot be partitioned, and '
          . 'partitionTable() would have nothing to widen the primary key with.' );
    }

    /**
     * The parent's columns have to actually arrive.
     *
     * Entity::create() writes EVERY declared column, so a class that says it is
     * a FactTable while its table lacks the parent's columns does not degrade
     * -- every insert fails on an unknown column. Update028 adds them; this is
     * the half that says the entity asks for them.
     */
    public function testItDeclaresTheInheritedFactColumns(): void
    {
        $declared = array_keys( $this->entity()->properties );

        foreach ( array( 'referer_id', 'source_id', 'campaign_id', 'ad_id',
                         'location_id', 'medium', 'cv1_name', 'cv5_value' ) as $column ) {

            $this->assertContains( $column, $declared,
                sprintf( '%s comes from FactTable and must be declared.', $column ) );
        }
    }

    /**
     * And the table's own columns must survive absorbing the parent's.
     *
     * The parent columns are taken first precisely so these still win -- ua_id
     * and os_id disagree with FactTable on type, and this change was not meant
     * to alter them.
     */
    public function testItKeepsItsOwnColumns(): void
    {
        $e        = $this->entity();
        $declared = array_keys( $e->properties );

        foreach ( array( 'subscription_id', 'feed_reader_guid', 'feed_format', 'document_id' ) as $column ) {

            $this->assertContains( $column, $declared,
                sprintf( '%s is feed-specific and must not be lost.', $column ) );
        }

        $this->assertTrue( $e->properties['ua_id']->isForeignKey(),
            'ua_id must still resolve to the ua dimension.' );
    }

    /**
     * The predicate every caller uses, exercised the way they use it.
     */
    public function testItIsAmongTheModulesFactTables(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $found   = array();

        foreach ( $service->modules['base']->getEntities() as $name ) {

            if ( \OWA\Core\CoreAPI::entityFactory( 'base.' . $name )
                    instanceof \OWA\Core\Entity\FactTable ) {

                $found[] = $name;
            }
        }

        $this->assertContains( 'feed_request', $found );

        // The ones that were always selected must still be.
        foreach ( array( 'request', 'session', 'click', 'domstream', 'action_fact',
                         'commerce_transaction_fact', 'commerce_line_item_fact' ) as $name ) {

            $this->assertContains( $name, $found, sprintf( '%s must remain a fact table.', $name ) );
        }
    }
}
