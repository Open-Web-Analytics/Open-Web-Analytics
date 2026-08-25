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
        $controller = new \OWA\Module\Base\Controller\OptionsGoalEdit( array(
            'goal' => array(
                'goal_number' => '1',
                'goal_status' => 'active',
                'goal_group'  => '1',
                'goal_type'   => 'pages_per_visit',
            ),
            'new_goal_group_name' => $newName,
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
    public function testAGoalWithNoFunnelStepsValidatesCleanly(): void
    {
        $this->assertFalse( $this->refuses( 'Signups' ),
            'an ordinary goal has no steps, and that is not an error' );
    }

    public function testAFunnelGoalWithCompleteStepsValidatesCleanly(): void
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
                    'funnel_steps' => array(
                        1 => array( 'name' => 'Step one', 'path' => '/a' ),
                    ),
                ),
            ),
        ) );

        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );
        $validator->doValidations();

        $this->assertFalse( (bool) $validator->hasErrors );
    }

    public function testAnOrdinaryNameIsAccepted(): void
    {
        $this->assertFalse( $this->refuses( 'Signups' ) );
        $this->assertFalse( $this->refuses( '  Signups  ' ),
            'padding is trimmed on save, not refused' );
    }
}
