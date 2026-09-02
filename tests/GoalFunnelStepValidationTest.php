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
        /*
         * Goals became goal events, so these rules moved with the screen that
         * enforced them -- FunnelSave rather than OptionsGoalEdit. Every rule
         * below was earned by a bug, so they are re-pointed rather than
         * retired.
         *
         * The submission shape changed too: steps arrive as parallel stepName[]
         * / stepPath[] arrays, because the funnel is edited as a list of rows
         * like the report builder's constraints rather than as a nested array.
         */
        $names = array();
        $paths = array();

        foreach ( $steps as $step ) {

            $names[] = $step['name'] ?? '';
            $paths[] = $step['path'] ?? '';
        }

        /*
         * A funnel is its own screen now, so its rules live with it -- they
         * moved from the goal screen to GoalEventSave and then here, with the
         * section they govern. The rules themselves are unchanged, which is
         * what these tests check.
         */
        $controller = new \OWA\Module\Base\Controller\FunnelSave( array(
            'name'     => 'Probe',
            'stepName' => $names,
            'stepPath' => $paths,
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

    private function step( string $name, string $path ): array
    {
        return array( 'name' => $name, 'path' => $path );
    }

    public function testACompleteStepIsAccepted(): void
    {
        $this->assertFalse( $this->refuses( array( 1 => $this->step( 'Basket', '/basket' ) ) ) );
    }

    public function testAStepWithNoPathIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array( 1 => $this->step( 'Basket', '' ) ) ),
            'a step that names a stage but no page is half-filled, not optional' );
    }

    public function testAStepWithNoNameIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array( 1 => $this->step( '', '/basket' ) ) ) );
    }

    /**
     * A full web address is refused, because nothing downstream can match one.
     *
     * The funnel report builds `pagePath == <this>` and checkGoalStart matches
     * it against page_uri, so https://example.com/basket matches nothing: the
     * funnel reports zero and the goal never starts, silently. The field was
     * labelled "Step URL" until 2026-08-25, which invited precisely this.
     */
    public function testAFullWebAddressIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array(
            1 => $this->step( 'Basket', 'https://example.com/basket' ) ) ) );

        $this->assertTrue( $this->refuses( array(
            1 => $this->step( 'Basket', 'http://example.com/basket' ) ) ),
            'the scheme is what makes it unmatchable, not the host' );
    }

    public function testAPathIsAccepted(): void
    {
        $this->assertFalse( $this->refuses( array(
            1 => $this->step( 'Basket', '/basket' ) ) ) );
    }

    /** A path that merely CONTAINS a colon is not a web address. */
    public function testAPathWithAColonIsStillAPath(): void
    {
        $this->assertFalse( $this->refuses( array(
            1 => $this->step( 'Basket', '/products/a:b' ) ) ) );
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

    /**
     * A FUNNEL with no steps is refused, where a GOAL with none was fine.
     *
     * The premise moved with the screen. Most goals never had a funnel, so
     * having no steps had to be the normal case. A funnel is created
     * deliberately and is nothing but its steps -- one with none describes no
     * path and would sit in the list with nothing to show.
     */
    public function testAFunnelWithNoStepsAtAllIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array() ),
            'A funnel with no steps can be saved, and then describes nothing.' );
    }

    /** And a funnel of wholly blank rows is the same thing. */
    public function testAFunnelOfBlankRowsIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array(
            1 => $this->step( '', '' ),
            2 => $this->step( '', '' ),
        ) ) );
    }
}
