<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A renamed goal group must be given an actual name.
 *
 * The field is optional -- leaving it empty keeps the group's default label --
 * but a name of nothing but spaces is not "no rename", it is a blank label. And
 * a blank label is not a local problem: every goal group holding an active goal
 * becomes a metric-set tab, and metric sets are global, so one blank name is an
 * unlabelled tab on every tabbed report in the install.
 *
 * The group KEY was already safe -- validate() has required goal_group for as
 * long as it has existed. It was the name that had no validation at all.
 */
final class GoalGroupNameTest extends TestCase
{
    /** Run the controller's own validate() and report whether it objected. */
    private function refuses( $newName ): bool
    {
        /*
         * Goals became goal events, so the rule moved with the screen that
         * enforced it -- GoalEventSave rather than OptionsGoalEdit.
         *
         * The other required fields are filled in so this isolates the group
         * name. Without them validate() objects to every case and the test
         * would pass while proving nothing.
         */
        $controller = new \OWA\Module\Base\Controller\GoalEventSave( array(
            'name'             => 'Probe',
            'conditionValue'   => '/thanks',
            'goalGroup'        => '1',
            'newGoalGroupName' => $newName,
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

    public function testANameOfNothingButSpacesIsRefused(): void
    {
        $this->assertTrue( $this->refuses( '   ' ),
            'whitespace is not a name; saving it would leave an unlabelled tab '
            . 'on every tabbed report' );
    }

    public function testAnEmptyNameIsNotARename(): void
    {
        $this->assertFalse( $this->refuses( '' ),
            'the field is optional -- empty means keep the default label' );
    }

    /**
     * "0" is falsy in PHP, and the save path used to test the name for
     * truthiness. That silently discarded this rename: the form reported
     * success and the label did not change.
     */
    public function testANameOfZeroIsAName(): void
    {
        $this->assertFalse( $this->refuses( '0' ) );
    }

    /**
     * A goal with no funnel steps validates cleanly.
     *
     * Only a funnel goal has steps, so this is the ordinary case. It used to
     * warn three times -- undefined "details", an offset on null, then foreach
     * over null -- because validate()'s guard was INVERTED: it returned when
     * the steps were present and fell through into the loop when they were not.
     *
     * Invisible on a configured install, whose error handler swallows warnings;
     * a hard failure in CI, which runs configless under failOnWarning.
     *
     * Un-inverting it also makes the two step validations below reachable for
     * the first time. That changes no outcome: they assert `required` on values
     * the loop has already required to be non-empty, so they cannot fail. The
     * funnel steps are effectively unvalidated either way -- worth its own fix,
     * not this one.
     */
    /**
     * A goal event with no funnel validates cleanly.
     *
     * Having no funnel is the normal case -- most goal events are a single
     * condition -- so it must not be mistaken for an incomplete one.
     */
    public function testAGoalWithNoFunnelStepsValidatesCleanly(): void
    {
        $this->assertFalse( $this->objects( array(
            'name'           => 'Probe',
            'conditionValue' => '/thanks',
            'goalGroup'      => '1',
        ) ) );
    }

    /** And one whose steps are all complete validates cleanly too. */
    public function testAFunnelGoalWithCompleteStepsValidatesCleanly(): void
    {
        $this->assertFalse( $this->objects( array(
            'name'           => 'Probe',
            'conditionValue' => '/thanks',
            'goalGroup'      => '1',
            'stepName'       => array( 'Step one' ),
            'stepPath'       => array( '/a' ),
        ) ) );
    }

    /** Run validate() over a whole submission and report whether it objected. */
    private function objects( array $params ): bool
    {
        $controller = new \OWA\Module\Base\Controller\GoalEventSave( $params );

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


    public function testAnOrdinaryNameIsAccepted(): void
    {
        $this->assertFalse( $this->refuses( 'Signups' ) );
        $this->assertFalse( $this->refuses( '  Signups  ' ),
            'padding is trimmed on save, not refused' );
    }
}
