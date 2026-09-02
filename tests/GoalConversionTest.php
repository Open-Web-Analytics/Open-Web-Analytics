<?php

use PHPUnit\Framework\TestCase;

/**
 * What checkForConversion() reports when a site has several goals.
 *
 * Two defects live in its loop, both caused by variables that outlive the
 * iteration that set them:
 *
 *  1. `$goal_value` is never reset. When a goal carries no `goal_value` of its
 *     own, neither assignment branch runs and the variable still holds the
 *     PREVIOUS goal's value -- so a conversion can be recorded with a value
 *     belonging to an entirely different goal.
 *
 *  2. `$goal_info['value']` is assigned on every iteration whether or not that
 *     goal converted, so the reported value is simply whatever the LAST goal
 *     in the list contributed.
 *
 * `$match` has the same shape but is benign today, because a goal that fails
 * to match returns '' and the only other writer is a goal that did match. It
 * is reset anyway: the two dead goal types are being removed in this change,
 * and a legacy row carrying one of those types now falls through the switch
 * with no case -- exactly the condition under which a stale `$match` would
 * hand it its neighbour's conversion.
 */
final class GoalConversionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** An event carrying just enough for the url_destination checker. */
    private function event(string $pageUri)
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->set('page_uri', $pageUri);
        $event->set('siteId', 'test-site');

        return $event;
    }

    private function goal(int $number, string $url, array $extra = []): array
    {
        return array_merge([
            'goal_number'  => $number,
            'goal_status'  => 'active',
            'goal_type'    => 'url_destination',
            'details'      => ['match_type' => 'exact', 'goal_url' => $url],
        ], $extra);
    }

    private function handlerWithGoals(array $goals): \OWA\Module\Base\Handler\ConversionHandlers
    {
        return new class($goals) extends \OWA\Module\Base\Handler\ConversionHandlers {
            private $stubGoals;

            public function __construct($goals)
            {
                $this->stubGoals = $goals;
            }

            protected function getActiveGoals($siteId)
            {
                return $this->stubGoals;
            }

            /*
             * Matching moved OUT of this class.
             *
             * A goal event's conditions are rows now, and there can be several
             * combined with all or any -- so the handler asks whether goal N
             * matched rather than deciding it from a url_destination triple.
             * That decision has its own tests (GoalEventStorageTest); what is
             * under test HERE is the orchestration around it: which goal owns
             * the value, that an inactive one contributes nothing, and that one
             * goal's result does not leak into the next.
             *
             * Stubbed from the same goal array the rest of the harness uses, so
             * these tests still describe the scenarios they always did.
             */
            protected function checkGoalEventConditions($event, $siteId, $number)
            {
                $goal = $this->stubGoals[$number] ?? null;

                if (!is_array($goal)) {
                    return '';
                }

                $url = $goal['details']['goal_url'] ?? null;

                if ($url === null) {
                    return '';
                }

                /*
                 * The REAL comparison, not a re-implementation of it. One
                 * definition of what "begins" means, exercised from both the
                 * handler's level and the entity's own tests.
                 */
                return \OWA\Module\Base\Entity\GoalEvent::compare(
                    $event->get('page_uri'),
                    $goal['details']['match_type'] ?? 'exact',
                    $url
                ) ? $number : '';
            }
        };
    }

    /**
     * The defect: goal 1 converts and carries value 10, goal 2 does not convert
     * but carries value 99. The conversion belongs to goal 1, so the value must
     * be 10 -- not the last goal's 99.
     */
    public function testValueBelongsToTheGoalThatConverted(): void
    {
        $handler = $this->handlerWithGoals([
            1 => $this->goal(1, '/thanks', ['goal_value' => 10]),
            2 => $this->goal(2, '/never-visited', ['goal_value' => 99]),
        ]);

        $info = $handler->checkForConversion($this->event('/thanks'));

        $this->assertSame(1, (int) $info['conversion'], 'goal 1 should be the conversion');
        $this->assertSame(10, (int) $info['value'], 'value must come from the converted goal, not the last one');
    }

    /**
     * The same defect in its other form: goal 2 converts but carries no value of
     * its own, so nothing may be inherited from goal 1.
     */
    public function testAGoalWithNoValueDoesNotInheritOne(): void
    {
        $handler = $this->handlerWithGoals([
            1 => $this->goal(1, '/never-visited', ['goal_value' => 77]),
            2 => $this->goal(2, '/signup'),
        ]);

        $info = $handler->checkForConversion($this->event('/signup'));

        $this->assertSame(2, (int) $info['conversion']);
        $this->assertEmpty($info['value'], 'a goal with no value must not inherit the previous goal\'s');
    }

    /**
     * A goal whose type is not recognised -- which is what a legacy
     * pages_per_visit or visit_duration row becomes after this change -- is
     * ignored, and never inherits the previous goal's match.
     */
    public function testUnrecognisedGoalTypeIsIgnoredAndInheritsNothing(): void
    {
        $handler = $this->handlerWithGoals([
            1 => $this->goal(1, '/thanks', ['goal_value' => 10]),
            2 => $this->goal(2, '/x', ['goal_type' => 'visit_duration', 'goal_value' => 99]),
        ]);

        $info = $handler->checkForConversion($this->event('/thanks'));

        $this->assertSame(1, (int) $info['conversion'], 'the legacy-typed goal must not claim the conversion');
        $this->assertSame(10, (int) $info['value']);
    }

    /**
     * Inactive goals are skipped and contribute nothing.
     */
    public function testInactiveGoalsContributeNothing(): void
    {
        $handler = $this->handlerWithGoals([
            1 => $this->goal(1, '/thanks', ['goal_status' => 'inactive', 'goal_value' => 55]),
            2 => $this->goal(2, '/signup'),
        ]);

        $info = $handler->checkForConversion($this->event('/signup'));

        $this->assertSame(2, (int) $info['conversion']);
        $this->assertEmpty($info['value']);
    }

    /**
     * The surviving goal type still works: exact, begins and regex matching.
     */
    public function testUrlDestinationMatchingStillWorks(): void
    {
        $exact = $this->handlerWithGoals([1 => $this->goal(1, '/thanks')]);
        $this->assertSame(1, (int) $exact->checkForConversion($this->event('/thanks'))['conversion']);
        $this->assertEmpty($exact->checkForConversion($this->event('/thanks/more'))['conversion']);

        $begins = $this->handlerWithGoals([
            1 => $this->goal(1, '/shop', ['details' => ['match_type' => 'begins', 'goal_url' => '/shop']]),
        ]);
        $this->assertSame(1, (int) $begins->checkForConversion($this->event('/shop/cart'))['conversion']);
        $this->assertEmpty($begins->checkForConversion($this->event('/blog/shop'))['conversion']);

        $regex = $this->handlerWithGoals([
            1 => $this->goal(1, 'x', ['details' => ['match_type' => 'regex', 'goal_url' => '^/order/[0-9]+$']]),
        ]);
        $this->assertSame(1, (int) $regex->checkForConversion($this->event('/order/12345'))['conversion']);
        $this->assertEmpty($regex->checkForConversion($this->event('/order/abc'))['conversion']);
    }

    /**
     * A site with no goals returns nothing rather than raising.
     */
    public function testNoGoalsReturnsNothing(): void
    {
        $this->assertEmpty($this->handlerWithGoals([])->checkForConversion($this->event('/thanks')));
    }
}
