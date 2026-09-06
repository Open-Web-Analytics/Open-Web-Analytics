<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Controller\GoalEventSave;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A numbered goal slot only means something if every half of it exists.
 *
 * `numGoals` sizes four things that have to agree:
 *
 *   - owa_session's physical goal_<N>, goal_<N>_start and goal_<N>_value
 *     columns (Entity\Session),
 *   - the goal<N>Completions / Starts / Value metric families (Base\Module),
 *   - GoalManager::saveGoal(), which refuses a number above it,
 *   - and GoalEventSave::nextFreeSlot(), which hands the numbers out.
 *
 * The fourth used to run to a hardcoded 20 while the other three used numGoals
 * (15), so slots 16-20 were issued with nothing behind them. That is not a
 * cosmetic mismatch: ConversionHandlers builds 'goal_' . <number> and sets it
 * on the session, so a completion on slot 16 named a column that does not
 * exist and was dropped in silence, and no metric could have reported it.
 *
 * Running out of slots is a HANDLED state -- nextFreeSlot() answers null, the
 * goal event is still created and still works, it just has no numbered metric.
 * A number past the ceiling is strictly worse than that null, which is why
 * these assert the ceiling rather than merely that a number came back.
 */
final class GoalSlotCeilingTest extends TestCase
{
    private array $created = [];

    private string $siteId = '';

    /*
     * Goal events are stored against the PROPERTY, and GoalEvents::listFor()
     * queries on property_id -- a fixture keyed only on site_id is invisible to
     * the code under test, and every count silently measures an empty list.
     */
    private string $propertyId = '';

    private int $numGoals = 0;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $this->numGoals = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'numGoals' );

        if ( $this->numGoals < 1 ) {
            $this->markTestSkipped( 'numGoals is not configured.' );
        }

        // A real Profile: goal events hang off the Property, so an invented id
        // resolves to nothing and every count below would measure an empty list.
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id, property_id' );

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ! empty( $row['property_id'] ) ) {

                $this->siteId     = $row['site_id'];
                $this->propertyId = $row['property_id'];
                break;
            }
        }

        if ( ! $this->siteId ) {
            $this->markTestSkipped( 'Needs a Profile with a Property.' );
        }
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $id ) {

            $e = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $e->delete( $id );
        }

        $this->created = [];
    }

    /** Occupy $number's slot with a real row, so nextFreeSlot() has to skip it. */
    private function occupySlot( int $number ): void
    {
        $e = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $id = $e->generateId( 'goal_slot_test:' . $this->siteId . ':' . uniqid( '', true ) );

        $e->set( 'id', $id );
        $e->set( 'site_id', $this->siteId );
        $e->set( 'property_id', $this->propertyId );
        $e->set( 'name', 'slot ceiling fixture ' . $number );
        $e->set( 'goal_number', $number );
        $e->set( 'is_active', 1 );
        $e->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $e->create();

        $this->created[] = $id;
    }

    /**
     * The ceiling is numGoals, not a literal.
     *
     * Fill every slot and the answer must be null -- never numGoals + 1, which
     * is the exact value the hardcoded 20 used to hand back on a 15-goal
     * install.
     */
    public function testSlotsRunOutAtNumGoalsRatherThanAHardcodedCeiling(): void
    {
        for ( $i = 1; $i <= $this->numGoals; $i++ ) {
            $this->occupySlot( $i );
        }

        $next = GoalEventSave::nextFreeSlot( $this->siteId );

        $this->assertNull(
            $next,
            'every numbered slot is taken, so there is no slot to give out; '
            . 'returning ' . var_export( $next, true ) . ' would name a goal_<N> '
            . 'column and a goal<N>Completions metric that do not exist.'
        );
    }

    /**
     * The other half: while slots remain, the lowest free one is handed out and
     * it is always inside the range the columns and metrics cover.
     */
    public function testAnIssuedSlotIsAlwaysWithinTheSupportedRange(): void
    {
        // Take everything except the last, so the only answer is that last one.
        for ( $i = 1; $i < $this->numGoals; $i++ ) {
            $this->occupySlot( $i );
        }

        $next = GoalEventSave::nextFreeSlot( $this->siteId );

        $this->assertSame( $this->numGoals, $next );
        $this->assertLessThanOrEqual( $this->numGoals, $next );
    }

    /**
     * The invariant the fix actually restores, asserted against the schema
     * rather than against the loop: the highest issuable slot has session
     * columns, and one past it does not.
     *
     * This is what makes the two above meaningful -- without it they only pin
     * nextFreeSlot() to a number, not to a number that works.
     */
    public function testTheSessionCarriesColumnsForExactlyTheIssuableSlots(): void
    {
        $session = \OWA\Core\CoreAPI::entityFactory( 'base.session' );
        $columns = array_keys( (array) $session->getProperties() );

        $this->assertContains(
            'goal_' . $this->numGoals, $columns,
            'the highest issuable slot has no column to record a completion in.'
        );

        $this->assertNotContains(
            'goal_' . ( $this->numGoals + 1 ), $columns,
            'a column exists past numGoals, so the ceiling and the schema '
            . 'disagree in the other direction.'
        );
    }
}
