<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Entity\GoalEvent;

/**
 * Goal declarations move to the v2 vocabulary, or their goal is switched off.
 *
 * Every goal event in existence said what it watches in words v2 does not use.
 * The trigger was 'base.page_request' -- a v1 event name, which was read by
 * nothing until marking started gating on it -- and the conditions named v1
 * PROPERTIES. Measured on both installs here: every condition said page_uri or
 * medium, and neither is a column of the stored row, so every goal counted
 * nothing.
 *
 * The gate and this migration have to ship together: gating on a v1 trigger name
 * would refuse every row, so the goals would go from counting nothing quietly to
 * counting nothing differently.
 */
final class Update049Test extends TestCase
{
    /** @var \OWA\Module\Base\Update\Update049 */
    private $update;

    private array $created = [];

    private string $propertyId = '';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the migration rewrites stored rows' );
        }

        $this->update = new \OWA\Module\Base\Update\Update049();
        $this->update->module_name = 'base';

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'property_id' );

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ! empty( $row['property_id'] ) ) {
                $this->propertyId = $row['property_id'];
                break;
            }
        }

        if ( ! $this->propertyId ) {
            $this->markTestSkipped( 'needs a Profile with a Property' );
        }
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $id ) {
            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )->delete( $id );
        }

        $this->created = [];

        \OWA\Module\Base\Classes\GoalMarking::forget();
    }

    /** The v1 trigger name becomes the v2 event name. */
    public function testTheTriggerBecomesAV2EventName(): void
    {
        $id = $this->makeGoal( GoalEvent::TRIGGER_PAGE_VIEW, array(
            array( 'page_uri', 'exact', '/thanks' ) ) );

        $this->assertTrue( $this->update->up() );

        $this->assertSame( 'page_view', $this->reload( $id )->get( 'trigger_event_type' ),
            'A v1 trigger name gates every row out, so the goal counts nothing at all.' );
    }

    /** The v1 property names become raw column names. */
    public function testTheConditionPropertiesBecomeColumns(): void
    {
        $id = $this->makeGoal( GoalEvent::TRIGGER_PAGE_VIEW, array(
            array( 'page_uri', 'begins', '/thanks' ),
            array( 'medium',   'exact', 'organic-search' ) ) );

        $this->assertTrue( $this->update->up() );

        $this->assertSame( array( 'page_path', 'tagged_medium' ),
            $this->propertiesOf( $id ) );
    }

    /**
     * IDEMPOTENT. columnFor() answers a column with itself and V2Event::name()
     * leaves a bare v2 name alone, so a second pass finds nothing to do -- which
     * matters because the upgrade cycle applies every migration more than once in
     * one process.
     */
    public function testASecondRunChangesNothing(): void
    {
        $id = $this->makeGoal( GoalEvent::TRIGGER_PAGE_VIEW, array(
            array( 'page_uri', 'exact', '/twice' ) ) );

        $this->assertTrue( $this->update->up() );
        $this->assertTrue( $this->update->up() );

        $this->assertSame( array( 'page_path' ), $this->propertiesOf( $id ) );
        $this->assertSame( 'page_view', $this->reload( $id )->get( 'trigger_event_type' ) );
        $this->assertSame( 1, (int) $this->reload( $id )->get( 'is_active' ),
            'the second run switched off a goal it had just migrated' );
    }

    /**
     * A CONDITION THAT CANNOT BE EXPRESSED SWITCHES ITS GOAL OFF.
     *
     * The old builder offered every property name there was, including ones with
     * no column at all -- page_type here. Left active, such a goal says "active"
     * on the screen and counts zero for ever, which is the state this whole
     * change exists to remove. Switched off it is visible, and one click turns it
     * back on once the condition is rewritten.
     */
    public function testAnUntranslatableConditionSwitchesItsGoalOff(): void
    {
        $id = $this->makeGoal( GoalEvent::TRIGGER_PAGE_VIEW, array(
            array( 'page_type', 'exact', 'attachment' ) ) );

        $this->assertTrue( $this->update->up() );

        $this->assertSame( 0, (int) $this->reload( $id )->get( 'is_active' ) );

        $this->assertSame( array( 'page_type' ), $this->propertiesOf( $id ),
            'The condition is left as it was: rewriting it to something that happens '
            . 'to be a column would change what the author asked for.' );
    }

    /** A goal whose conditions all translate stays on. */
    public function testAMigratedGoalStaysActive(): void
    {
        $id = $this->makeGoal( GoalEvent::TRIGGER_PAGE_VIEW, array(
            array( 'page_uri', 'exact', '/still-on' ) ) );

        $this->assertTrue( $this->update->up() );

        $this->assertSame( 1, (int) $this->reload( $id )->get( 'is_active' ) );
    }

    /**
     * AND THE MIGRATED GOAL ACTUALLY MARKS A ROW.
     *
     * The two halves of this change meet here: a declaration written in 1.x's
     * words, migrated, gating on the trigger, matched against a stored row. Each
     * piece is tested on its own, and this is the one that would have caught the
     * whole thing being pointless.
     */
    public function testAMigratedGoalMarksTheRowItWasAlwaysMeantTo(): void
    {
        $siteId = $this->siteIdForProperty();

        if ( $siteId === '' ) {
            $this->markTestSkipped( 'needs a Profile under the Property' );
        }

        $id = $this->makeGoal( GoalEvent::TRIGGER_PAGE_VIEW, array(
            array( 'page_uri', 'begins', '/checkout/done' ) ) );

        $this->assertTrue( $this->update->up() );

        \OWA\Module\Base\Classes\GoalMarking::forget();

        $row = \OWA\Module\Base\Classes\Ingest::at(
            \OWA\Module\Base\Classes\Ingest::STORE_POST,
            array(
                'event_type' => 'page_view',
                'site_id'    => $siteId,
                'page_path'  => '/checkout/done',
            ) );

        $this->assertSame( 1, $row['is_goal_event'] );

        $click = \OWA\Module\Base\Classes\Ingest::at(
            \OWA\Module\Base\Classes\Ingest::STORE_POST,
            array(
                'event_type' => 'click',
                'site_id'    => $siteId,
                'page_path'  => '/checkout/done',
            ) );

        $this->assertSame( 0, $click['is_goal_event'],
            'the migrated trigger must still gate: a click on that page is not the goal' );
    }

    /* ---------------- helpers ---------------- */

    private function siteIdForProperty(): string
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id' );
        $db->where( 'property_id', $this->propertyId );

        foreach ( (array) $db->getAllRows() as $row ) {
            return (string) $row['site_id'];
        }

        return '';
    }

    private function reload( $id )
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $entity->load( $id );

        return $entity;
    }

    /** @return string[] in stored order */
    private function propertiesOf( $id ): array
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory(
            'base.goal_event_condition' )->getTableName() );
        $db->selectColumn( 'condition_property, sort_order' );
        $db->where( 'goal_event_id', $id );
        $db->orderBy( 'sort_order', OWA_SQL_ASCENDING );

        $out = array();

        foreach ( (array) $db->getAllRows() as $row ) {
            $out[] = (string) $row['condition_property'];
        }

        return $out;
    }

    private function makeGoal( $trigger, array $conditions ): string
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $id     = $entity->generateId( 'goal_event:049-probe:' . uniqid( '', true ) );

        $this->created[] = $id;

        $entity->set( 'id', $id );
        $entity->set( 'property_id', $this->propertyId );
        $entity->set( 'name', 'Migration probe' );
        $entity->set( 'trigger_event_type', $trigger );
        $entity->set( 'is_active', 1 );
        $entity->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $entity->create();

        $n = 0;

        foreach ( $conditions as $c ) {

            $n++;

            $row = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
            $row->set( 'id', $row->generateId( 'goal_event_condition:' . $id . ':' . $n ) );
            $row->set( 'goal_event_id', $id );
            $row->set( 'sort_order', $n );
            $row->set( 'condition_property', $c[0] );
            $row->set( 'condition_operator', $c[1] );
            $row->set( 'condition_value', $c[2] );
            $row->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $row->create();
        }

        return $id;
    }
}
