<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A funnel step that names a GOAL EVENT has no path, and must still render.
 *
 * VisualizationSave stores a step as ONE of two shapes -- {name, path} or
 * {name, goal_event_id} -- deliberately, so a step cannot carry a stale path
 * beside the goal event it actually counts. Both the result table and the
 * template then read $step['path'] unconditionally, so every render of a funnel
 * ending in a goal produced
 *
 *     PHP Warning: Undefined array key "path"
 *
 * twice per step. Found in Apache's error log on the demo site, where the
 * restored funnel ends on its goal event -- which is the shape the builder
 * encourages, since tying the last step to the goal is the point.
 *
 * The warning was invisible locally: OWA's own error handler swallows warnings
 * when a config file is present, and it reached the log only because
 * mod_proxy_fcgi escalates anything PHP writes to stderr.
 */
final class FunnelGoalEventStepTest extends TestCase
{
    private function controller(): object
    {
        return new \OWA\Module\Base\Controller\VisualizationFunnel( array() );
    }

    private function call( object $o, string $method, array $args )
    {
        $m = new ReflectionMethod( $o, $method );
        $m->setAccessible( true );

        return $m->invokeArgs( $o, $args );
    }

    /** A goal step is labelled with the goal's name, not left undefined. */
    public function testAGoalEventStepIsLabelledWithTheGoalsName(): void
    {
        if ( ! function_exists( 'owa_test_db_available' ) || ! owa_test_db_available() ) {
            $this->markTestSkipped( 'needs a database: the label is read from the goal event row' );
        }

        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goal->set( 'id', $goal->generateId( 'funnel-goal-label-test' ) );
        $goal->set( 'name', 'Completed Purchase' );
        $goal->set( 'is_active', 1 );
        $goal->create();

        try {
            $label = $this->call( $this->controller(), 'goalEventLabel',
                array( array( 'goal_event_id' => $goal->get( 'id' ) ) ) );

            $this->assertSame( 'Completed Purchase', $label );

        } finally {
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->query( sprintf( "DELETE FROM owa_goal_event WHERE id = '%s'",
                $db->prepare( (string) $goal->get( 'id' ) ) ) );
        }
    }

    /**
     * A goal that has since been deleted yields '', not a warning.
     *
     * stepPredicate() already refuses to draw a funnel whose goal is gone, so
     * this is the belt to that brace rather than the only guard.
     */
    public function testAMissingGoalYieldsAnEmptyLabel(): void
    {
        if ( ! function_exists( 'owa_test_db_available' ) || ! owa_test_db_available() ) {
            $this->markTestSkipped( 'needs a database' );
        }

        $this->assertSame( '',
            $this->call( $this->controller(), 'goalEventLabel',
                array( array( 'goal_event_id' => '9999999999999999' ) ) ) );
    }

    /** A step with neither key is not a goal step, and asks for nothing. */
    public function testAPathStepIsNotTreatedAsAGoalStep(): void
    {
        $this->assertSame( '',
            $this->call( $this->controller(), 'goalEventLabel',
                array( array( 'path' => '/ecommerce.php' ) ) ) );
    }
}
