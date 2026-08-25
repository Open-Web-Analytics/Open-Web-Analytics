<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A funnel step must be complete, and one bad step must not hide the next.
 *
 * validate() used to `return` the moment it met a step missing a name or a url.
 * That did three wrong things at once: it ACCEPTED the half-filled step (the
 * goal saved, with a step that names nothing), it skipped every later step, and
 * it abandoned the rest of validate() with them.
 *
 * The two checks it returned past were tautological anyway -- `required` on
 * values the guard had already proven non-empty -- so funnel steps were, in
 * effect, unvalidated.
 *
 * A wholly empty step is NOT an error: the constructor removes those, which is
 * what a user adding a step row and leaving it alone produces. Anything left
 * has at least one value, so a missing one is a mistake worth reporting.
 */
final class GoalFunnelStepValidationTest extends TestCase
{
    /** @param array<int, array<string, string>> $steps */
    private function refuses( array $steps ): bool
    {
        $controller = new \OWA\Module\Base\Controller\OptionsGoalEdit( array(
            'goal' => array(
                'goal_number' => '1',
                'goal_status' => 'active',
                'goal_group'  => '1',
                'goal_type'   => 'url_destination',
                'details'     => array(
                    'match_type'   => 'begins',
                    'goal_url'     => '/thanks',
                    'funnel_steps' => $steps,
                ),
            ),
        ) );

        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );

        if ( ! $validator ) {
            return false;
        }

        $validator->doValidations();

        return (bool) $validator->hasErrors;
    }

    private function step( string $name, string $url ): array
    {
        return array( 'name' => $name, 'url' => $url );
    }

    public function testACompleteStepIsAccepted(): void
    {
        $this->assertFalse( $this->refuses( array( 1 => $this->step( 'Basket', '/basket' ) ) ) );
    }

    public function testAStepWithNoUrlIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array( 1 => $this->step( 'Basket', '' ) ) ),
            'a step that names a stage but no page is half-filled, not optional' );
    }

    public function testAStepWithNoNameIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array( 1 => $this->step( '', '/basket' ) ) ) );
    }

    /**
     * The one the early return hid: a bad FIRST step used to stop the loop, so
     * nothing after it was ever looked at.
     */
    public function testABadFirstStepDoesNotHideALaterOne(): void
    {
        $this->assertTrue( $this->refuses( array(
            1 => $this->step( 'Basket', '' ),
            2 => $this->step( 'Checkout', '/checkout' ),
        ) ), 'the first step is incomplete' );

        $this->assertTrue( $this->refuses( array(
            1 => $this->step( 'Basket', '/basket' ),
            2 => $this->step( 'Checkout', '' ),
        ) ), 'and so is the second, which the early return never reached' );
    }

    /**
     * A row the user added and left alone is not a mistake. The constructor
     * removes steps whose values are all empty, so validate() never sees one.
     */
    public function testAWhollyEmptyStepIsDroppedRatherThanRefused(): void
    {
        $this->assertFalse( $this->refuses( array(
            1 => $this->step( 'Basket', '/basket' ),
            2 => $this->step( '', '' ),
        ) ), 'an untouched step row is removed before validation, not reported' );
    }

    public function testAGoalWithNoStepsAtAllIsFine(): void
    {
        $this->assertFalse( $this->refuses( array() ) );
    }
}
