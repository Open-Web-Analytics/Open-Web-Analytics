<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\FunnelStep;

/**
 * A funnel's steps as the save takes them (VisualizationSave), and one bad
 * step must not hide the next.
 *
 * validate() used to `return` the moment it met an incomplete step. That
 * ACCEPTED the half-filled step, skipped every later step, and abandoned the
 * rest of validate() with them.
 *
 * A step is an event and conditions on it (Classes\FunnelStep). A page is a
 * page_view with a page_path condition, which is what these mostly post. A row
 * left as it was added -- the default event and no condition with a value --
 * is not a step and is dropped rather than refused.
 */
final class FunnelStepValidationTest extends TestCase
{
    /**
     * The params a builder posts for these steps.
     *
     * @param array $steps  each { name?, event?, custom?, match?, conditions: [[property, operator, value]] }
     *                      or { name?, path } for a page, or raw old-kind fields
     */
    private function params( array $steps, string $constraints = '' ): array
    {
        /*
         * On a Property, as the builder always posts one: a new funnel is
         * stored on it. Not digits, so nothing reads custom dimensions for it
         * from a database the no-database job does not have.
         */
        $p = array( 'name' => 'Probe', 'propertyId' => 'funnel-step-validation', 'constraints' => $constraints,
            'stepName' => array(), 'stepEvent' => array(),
            'stepCustomEvent' => array(), 'stepMatch' => array(), 'stepConditionProperty' => array(),
            'stepConditionOperator' => array(), 'stepConditionValue' => array() );

        foreach ( array_values( $steps ) as $i => $step ) {

            if ( array_key_exists( 'path', $step ) && ! isset( $step['kind'] ) ) {
                $step['conditions'] = array( array( 'page_path', 'exact', $step['path'] ) );
                unset( $step['path'] );
            }

            foreach ( array( 'kind' => 'stepKind', 'path' => 'stepPath',
                             'goal_event_definition_id' => 'stepGoalEventDefinitionId' ) as $key => $field ) {
                if ( isset( $step[ $key ] ) ) {
                    $p[ $field ][ $i ] = $step[ $key ];
                }
            }

            $p['stepName'][ $i ]        = $step['name'] ?? '';
            $p['stepEvent'][ $i ]       = ! empty( $step['custom'] )
                ? \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM : ( $step['event'] ?? 'page_view' );
            $p['stepCustomEvent'][ $i ] = ! empty( $step['custom'] ) ? ( $step['event'] ?? '' ) : '';
            $p['stepMatch'][ $i ]       = $step['match'] ?? 'all';

            foreach ( (array) ( $step['conditions'] ?? array() ) as $c ) {
                $p['stepConditionProperty'][ $i ][] = $c[0];
                $p['stepConditionOperator'][ $i ][] = $c[1];
                $p['stepConditionValue'][ $i ][]    = $c[2];
            }
        }

        return $p;
    }

    /** The validation messages for these steps, field => message. */
    private function errors( array $steps, string $constraints = '' ): array
    {
        $controller = new \OWA\Module\Base\Controller\VisualizationSave( $this->params( $steps, $constraints ) );

        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );

        if ( ! $validator ) {
            return array();
        }

        $validator->doValidations();

        return $validator->hasErrors ? (array) $validator->getErrorMsgs() : array();
    }

    private function refuses( array $steps, string $constraints = '' ): bool
    {
        return (bool) $this->errors( $steps, $constraints );
    }

    private function step( string $name, string $path ): array
    {
        return array( 'name' => $name, 'path' => $path );
    }

    public function testACompleteStepIsAccepted(): void
    {
        $this->assertFalse( $this->refuses( array( 1 => $this->step( 'Basket', '/basket' ) ) ) );
    }

    /** A funnel is stored on a Property, so a save that names none is refused. */
    public function testANewFunnelNeedsAProperty(): void
    {
        $params = $this->params( array( 1 => $this->step( 'Basket', '/basket' ) ) );
        unset( $params['propertyId'] );

        $controller = new \OWA\Module\Base\Controller\VisualizationSave( $params );
        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );
        $validator->doValidations();

        $this->assertSame( array( 'propertyId' => 'No Property was named to save this to.' ),
            (array) $validator->getErrorMsgs() );
    }

    /** A row with a stored name and no condition value is not a step, so a funnel of only that has none. */
    public function testANameWithNoPageIsNotAStep(): void
    {
        $this->assertTrue( $this->refuses( array( 1 => $this->step( 'Basket', '' ) ) ),
            'the funnel has no steps' );

        $this->assertFalse( $this->refuses( array(
            1 => $this->step( 'Basket', '' ),
            2 => $this->step( '', '/checkout' ),
        ) ), 'a row with no page is dropped, not refused' );
    }

    /** The builder does not ask for a step's name; it is named for its page, or its event. */
    public function testAStepNeedsNoName(): void
    {
        $this->assertFalse( $this->refuses( array( 1 => $this->step( '', '/basket' ) ) ) );

        $save = new \OWA\Module\Base\Controller\VisualizationSave( $this->params( array(
            $this->step( '', '/basket' ),
            $this->step( 'Checkout', '/checkout' ),
            $this->step( '', '' ),
            array( 'event' => 'click', 'conditions' => array( array( 'element_id', 'exact', 'buy' ) ) ),
        ) ) );

        $steps = \OWA\Module\Base\Controller\VisualizationSave::stepsFromForm( $save->rowsFromRequest() );

        $page = static function ( $name, $path, $n ) {
            return array( 'name' => $name, 'kind' => 'event', 'event' => 'page_view', 'match' => 'all',
                'conditions' => array( array( 'property' => 'page_path', 'operator' => 'exact', 'value' => $path ) ),
                'step_number' => $n );
        };

        $this->assertSame( array(
            $page( '/basket', '/basket', 1 ),
            $page( 'Checkout', '/checkout', 2 ),
            array( 'name' => 'click', 'kind' => 'event', 'event' => 'click', 'match' => 'all',
                'conditions' => array( array( 'property' => 'element_id', 'operator' => 'exact', 'value' => 'buy' ) ),
                'step_number' => 3 ),
        ), $steps );
    }

    /** Event steps are accepted: OWA's own events, a site's own, all or any, and conditions or none. */
    public function testEventStepsAreAccepted(): void
    {
        $this->assertSame( array(), $this->errors( array(
            array( 'event' => 'click', 'match' => 'any', 'conditions' => array(
                array( 'element_id', 'exact', 'buy' ), array( 'element_id', 'regex', '^buy-' ) ) ),
            array( 'event' => 'newsletter_signup', 'custom' => true ),
            array( 'event' => 'purchase', 'conditions' => array( array( 'revenue', 'gt', '5000' ) ) ),
            array( 'event' => 'scroll' ),
        ) ) );
    }

    /** A funnel's filter is checked the way a widget's is. */
    public function testAFilterThatNamesNothingIsRefused(): void
    {
        $basket = array( 1 => $this->step( '', '/basket' ) );

        $this->assertFalse( $this->refuses( $basket, 'pagePath=@/blog/' ) );
        $this->assertTrue( $this->refuses( $basket, 'notADimension==x' ) );
        $this->assertTrue( $this->refuses( $basket, 'pagePath==' ) );
    }

    /**
     * A full web address is refused as a page path: page_path holds no scheme
     * or host, so the stage would count nobody, silently.
     */
    public function testAFullWebAddressIsRefused(): void
    {
        $this->assertSame( array( 'stepCondition1_1' => 'Step 1: enter a path such as /basket, not a full URL.' ),
            $this->errors( array( $this->step( 'Basket', 'https://example.com/basket' ) ) ) );

        $this->assertTrue( $this->refuses( array( $this->step( 'Basket', 'http://example.com/basket' ) ) ),
            'the scheme is what makes it unmatchable, not the host' );
    }

    /** A query string typed into a page path is refused: page_path is cut at the ?. */
    public function testAQueryStringInAPagePathIsRefused(): void
    {
        $this->assertSame( array( 'stepCondition1_1' =>
            'Step 1: a page path has no query string. Test the part after the ? as the page query.' ),
            $this->errors( array( $this->step( '', '/checkout?step=2' ) ) ) );

        $this->assertFalse( $this->refuses( array( array( 'event' => 'page_view', 'conditions' => array(
            array( 'page_path', 'exact', '/checkout' ), array( 'page_query', 'exact', 'step=2' ) ) ) ) ) );
    }

    public function testAPathIsAccepted(): void
    {
        $this->assertFalse( $this->refuses( array( $this->step( 'Basket', '/basket' ) ) ) );
    }

    /** A path that merely CONTAINS a colon is not a web address. */
    public function testAPathWithAColonIsStillAPath(): void
    {
        $this->assertFalse( $this->refuses( array( $this->step( 'Basket', '/products/a:b' ) ) ) );
    }

    /** A bad FIRST step used to stop the loop, so nothing after it was looked at. */
    public function testABadFirstStepDoesNotHideALaterOne(): void
    {
        $errors = $this->errors( array(
            $this->step( 'Basket', 'https://example.com/basket' ),
            $this->step( 'Checkout', 'https://example.com/checkout' ),
        ) );

        $this->assertArrayHasKey( 'stepCondition1_1', $errors );
        $this->assertArrayHasKey( 'stepCondition2_1', $errors, 'the early return never reached the second' );
    }

    /** A row the user added and left alone is not a mistake. */
    public function testAWhollyEmptyStepIsDroppedRatherThanRefused(): void
    {
        $this->assertFalse( $this->refuses( array(
            $this->step( 'Basket', '/basket' ),
            $this->step( '', '' ),
        ) ), 'an untouched step row is removed before validation, not reported' );
    }

    /** A funnel is nothing but its steps; one with none describes no path. */
    public function testAFunnelWithNoStepsAtAllIsRefused(): void
    {
        $this->assertTrue( $this->refuses( array() ),
            'A funnel with no steps can be saved, and then describes nothing.' );
    }

    public function testAFunnelOfBlankRowsIsRefused(): void
    {
        $this->assertSame( array( 'steps' => 'A funnel needs at least one step.' ),
            $this->errors( array( $this->step( '', '' ), $this->step( '', '' ) ) ) );
    }

    /**
     * The step kinds before Update079 -- a path, a goal event definition -- are
     * refused by name, from a post of the old builder's fields.
     */
    public function testPathAndGoalEventStepsAreRefused(): void
    {
        $message = 'Step 1: a funnel step is an event. Page and goal event definition steps are no longer saved.';

        $this->assertSame( array( 'stepEvent1' => $message ),
            $this->errors( array( array( 'kind' => 'goal_event', 'goal_event_definition_id' => '9' ) ) ) );

        $this->assertSame( array( 'stepEvent1' => $message ),
            $this->errors( array( array( 'kind' => 'path', 'path' => '/basket' ) ) ) );

        $this->assertSame( array( 'stepEvent1' => $message ),
            $this->errors( array( array( 'kind' => 'sideways', 'conditions' => array( array( 'page_path', 'exact', '/a' ) ) ) ) ) );

        $this->assertSame( array(), \OWA\Module\Base\Controller\VisualizationSave::stepsFromForm( array(
            array( 'kind' => 'goal_event', 'goal_event_definition_id' => '9' ),
            array( 'kind' => 'path', 'path' => '/basket' ),
        ) ), 'and never stored' );
    }

    /** Each refusal of an event step, with its message, on the row and condition it is about. */
    public function testEachEventStepRefusal(): void
    {
        $cases = array(
            'no event' => array( array( 'event' => '', 'conditions' => array( array( 'page_path', 'exact', '/a' ) ) ),
                array( 'stepEvent1' => 'Step 1: choose an event.' ) ),
            'custom with no name' => array( array( 'event' => '', 'custom' => true ),
                array( 'stepEvent1' => 'Step 1: enter the name of the custom event.' ) ),
            'an OWA event a step cannot match' => array( array( 'event' => 'exception' ),
                array( 'stepEvent1' => 'Step 1: a funnel step cannot match exception events.' ) ),
            'not an event name' => array( array( 'event' => 'two words', 'custom' => true ),
                array( 'stepEvent1' => 'Step 1: An event name starts with a letter and has up to 40 letters, digits and underscores.' ) ),
            'unknown property' => array( array( 'event' => 'page_view', 'conditions' => array( array( 'page_type', 'exact', 'x' ) ) ),
                array( 'stepCondition1_1' => 'Step 1: page_type is not a property a funnel step can test.' ) ),
            'params' => array( array( 'event' => 'page_view', 'conditions' => array( array( 'params', 'contains', 'x' ) ) ),
                array( 'stepCondition1_1' => 'Step 1: params is not a property a funnel step can test.' ) ),
            'not carried' => array( array( 'event' => 'page_view', 'conditions' => array( array( 'element_id', 'exact', 'x' ) ) ),
                array( 'stepCondition1_1' => 'Step 1: a page_view event does not carry element_id.' ) ),
            'unknown operator' => array( array( 'event' => 'page_view', 'conditions' => array( array( 'page_path', 'sideways', '/a' ) ) ),
                array( 'stepCondition1_1' => 'Step 1: sideways is not a comparison a condition can make.' ) ),
            'bad regex' => array( array( 'event' => 'page_view', 'conditions' => array( array( 'page_path', 'regex', '([' ) ) ),
                array( 'stepCondition1_1' => 'Step 1: the regular expression is not valid.' ) ),
            'a custom dimension of no Property' => array( array( 'event' => 'page_view', 'conditions' => array( array( 'cd_plan', 'exact', 'gold' ) ) ),
                array( 'stepCondition1_1' => 'Step 1: cd_plan is not a property a funnel step can test.' ) ),
        );

        foreach ( $cases as $label => list( $step, $expected ) ) {

            $this->assertSame( $expected, $this->errors( array( $step ) ), $label );
        }

        // On the second condition of the second step, so the builder shows it there.
        $this->assertSame( array( 'stepCondition2_2' => 'Step 2: the regular expression is not valid.' ),
            $this->errors( array( $this->step( '', '/a' ), array( 'event' => 'page_view', 'conditions' => array(
                array( 'page_path', 'exact', '/b' ), array( 'page_title', 'regex', '([' ) ) ) ) ) );
    }

    /**
     * A custom dimension is a condition a step can test when it is applied to
     * the funnel's Property, on any event, and refused otherwise.
     */
    public function testACustomDimensionIsCheckedAgainstTheProperty(): void
    {
        $plan = array( 'event' => 'click', 'conditions' => array( array( 'cd_plan', 'exact', 'gold' ) ) );

        $this->assertSame( array(), FunnelStep::problems( array( 'event' => 'click', 'conditions' => array(
            array( 'property' => 'cd_plan', 'operator' => 'exact', 'value' => 'gold' ) ) ), 1, array( 'cd_plan' => 'Plan' ) ) );

        $this->assertSame( array( 'stepCondition1_1' => 'Step 1: cd_plan is not a property a funnel step can test.' ),
            FunnelStep::problems( array( 'event' => 'click', 'conditions' => array(
                array( 'property' => 'cd_plan', 'operator' => 'exact', 'value' => 'gold' ) ) ), 1, array( 'cd_tier' => 'Tier' ) ) );

        if ( ! owa_test_db_available() ) {
            return;
        }

        // Through the save, against the registrations: applied to this Property, pending, or another's.
        $mine   = '7779200000000001';
        $theirs = '7779200000000002';
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $table  = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' )->getTableName();

        $insert = function ( $id, $property, $key, $state ) use ( $db, $table ) {
            $db->query( sprintf( 'INSERT INTO %s (id, property_id, scope, dimension_key, column_name, data_type, max_length,'
                . ' label, creation_date, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $table ),
                array( $id, $property, 'event', $key, 'cd_' . $key, 'string', 64, ucfirst( $key ), time(), $state ) );
        };

        $db->query( sprintf( 'DELETE FROM %s WHERE property_id IN (?, ?)', $table ), array( $mine, $theirs ) );

        try {
            $insert( '7779200000000101', $mine, 'plan', \OWA\Module\Base\Entity\CustomDimension::STATE_APPLIED );
            $insert( '7779200000000102', $mine, 'tier', \OWA\Module\Base\Entity\CustomDimension::STATE_PENDING );
            $insert( '7779200000000103', $theirs, 'seat', \OWA\Module\Base\Entity\CustomDimension::STATE_APPLIED );
            // Written under the registry, so it reads them afresh.
            \OWA\Module\Base\Classes\DimensionRegistry::forget();

            $save = function ( array $step, string $constraints = '' ) use ( $mine ) {
                $controller = new \OWA\Module\Base\Controller\VisualizationSave(
                    array( 'propertyId' => $mine ) + $this->params( array( $step ), $constraints ) );
                $controller->validate();
                $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
                $v->setAccessible( true );
                $validator = $v->getValue( $controller );
                $validator->doValidations();
                return $validator->hasErrors ? (array) $validator->getErrorMsgs() : array();
            };

            $this->assertSame( array(), $save( $plan ), 'applied to this Property' );
            $this->assertNotSame( array(), $save( array( 'event' => 'click', 'conditions' => array( array( 'cd_tier', 'exact', 'x' ) ) ) ),
                'pending' );
            $this->assertNotSame( array(), $save( array( 'event' => 'click', 'conditions' => array( array( 'cd_seat', 'exact', 'x' ) ) ) ),
                "another Property's" );

            // The funnel's filter may name the Property's too, and only its.
            $this->assertSame( array(), $save( $plan, 'cd_plan==gold' ), 'a filter on one applied to this Property' );
            $this->assertNotSame( array(), $save( $plan, 'cd_seat==x' ), "a filter on another Property's" );

        } finally {
            $db->query( sprintf( 'DELETE FROM %s WHERE property_id IN (?, ?)', $table ), array( $mine, $theirs ) );
            \OWA\Module\Base\Classes\DimensionRegistry::forget();
        }
    }
}
