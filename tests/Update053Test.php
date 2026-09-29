<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * element_path goes off the row; is_outbound arrives on it.
 *
 * THIS FILE IS SHORT ON PURPOSE. An earlier version tested a rewrite of saved
 * custom report definitions, because `elementPath` was choosable in the report
 * builder and an unregistered dimension is refused rather than ignored. That
 * dimension only ever existed on the v2 branch, which has not shipped -- no
 * install can carry such a report, and the only v2 install has 1159 saved reports
 * and names it in none of them. The migration code went with the tests.
 *
 * What the swap itself does is asserted where it is observable: the columns in
 * EventRawEntityTest, the derivation in EventRawIngestionTest, the grouping in
 * CubeReportingTest, and the goal-builder scoping in GoalVocabularyTest.
 */
final class Update053Test extends TestCase
{
    /**
     * The update and the entity describe the same swap.
     *
     * Update053 is what makes an EXISTING install agree with the entity, so the
     * two disagreeing is an install that reports "up to date" while its cube is
     * missing a column every build names -- which fails with "Unknown column in
     * field list" having published nothing.
     *
     * required_schema_version is not asserted here: UpdateDiscoveryTest reads it
     * out of Base/Module.php and fails if it does not cover the highest update on
     * disk, which is the same fact stated once.
     */
    public function testTheUpdateAndTheEntityAgree(): void
    {
        $this->assertSame( 53, ( new \OWA\Module\Base\Update\Update053() )->schema_version );

        $columns = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns();

        $this->assertContains( 'is_outbound', $columns );
        $this->assertNotContains( 'element_path', $columns );
    }
}
