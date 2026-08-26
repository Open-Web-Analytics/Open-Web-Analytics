<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Which metric sets a site offers.
 *
 * A report shows one dimension measured several ways. Those ways are metric
 * sets, and they are NOT configuration: which exist depends on the site, and a
 * new one appears the moment somebody adds a goal. That is why a report cannot
 * enumerate them and why this is derived per site.
 *
 * The interface draws them as tabs today. Nothing here is named after that,
 * because it is expected to change.
 */
final class MetricSetsTest extends TestCase
{
    public function testASiteAlwaysOffersTheDefaultSet(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'reads site settings and goals' );
        }

        $sets = \OWA\Core\MetricSets::forSite( 1 );

        $this->assertArrayHasKey( \OWA\Core\MetricSets::DEFAULT_KEY, $sets,
            'every site measures usage, whatever else it has configured' );

        $default = $sets[ \OWA\Core\MetricSets::DEFAULT_KEY ];

        foreach ( array( 'label', 'metrics', 'chartMetric' ) as $key ) {
            $this->assertNotEmpty( $default[ $key ], "the default set has no $key" );
        }

        $this->assertStringContainsString( 'visits', $default['metrics'] );
    }

    /**
     * No site means no sets -- not a set with nothing in it.
     *
     * getSiteSetting() returns nothing without a site id and the goal manager
     * would be built for a site that does not exist, so answering "none" is
     * the honest result rather than a default set measuring nobody.
     */
    public function testNoSiteOffersNoSets(): void
    {
        $this->assertSame( array(), \OWA\Core\MetricSets::forSite( '' ) );
        $this->assertSame( array(), \OWA\Core\MetricSets::forSite( 0 ) );
    }

    /**
     * Every set is the same shape, whatever it came from.
     *
     * The renderer reads these keys off each one without asking where it came
     * from, so a goal-group set missing a chartMetric would chart nothing with
     * no error.
     */
    public function testEverySetHasTheSameShape(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'reads site settings and goals' );
        }

        $sets = \OWA\Core\MetricSets::forSite( 1 );

        $this->assertNotEmpty( $sets );

        foreach ( $sets as $key => $set ) {

            $this->assertSame( array( 'label', 'metrics', 'chartMetric' ), array_keys( $set ),
                "set '$key' is not the shape the renderer expects" );

            foreach ( $set as $field => $value ) {
                $this->assertIsString( $value, "set '$key' has a non-string $field" );
                $this->assertNotSame( '', $value, "set '$key' has an empty $field" );
            }
        }
    }

    /**
     * A set carries no sort.
     *
     * The runtime sets always have, and nothing has ever read it: the one
     * template that looks does `$view->sort ?: $tab['sort']`, and all 20
     * reports with a grid declare their own sort, so the set's never applies.
     * The other 9 build no grid at all. Confirmed by blanking it in the legacy
     * shape and re-recording every report -- 55 of 55 unchanged.
     */
    public function testASetCarriesNoSort(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'reads site settings and goals' );
        }

        foreach ( \OWA\Core\MetricSets::forSite( 1 ) as $key => $set ) {

            $this->assertArrayNotHasKey( 'sort', $set,
                "set '$key' carries a sort, which nothing reads" );
        }
    }

    /**
     * The legacy shape is derived from the same source, so the two renderers
     * cannot disagree about what a site offers while both exist.
     */
    public function testTheLegacyShapeIsDerivedNotDuplicated(): void
    {
        $sets = array(
            'site_usage' => array( 'label' => 'Site Usage', 'metrics' => 'visits', 'chartMetric' => 'visits' ),
            'ecommerce'  => array( 'label' => 'e-commerce', 'metrics' => 'transactions', 'chartMetric' => 'transactions' ),
        );

        $tabs = \OWA\Core\MetricSets::toLegacyTabs( $sets );

        $this->assertSame( array_keys( $sets ), array_keys( $tabs ),
            'the same sets, in the same order' );

        $this->assertSame( 'Site Usage', $tabs['site_usage']['tab_label'] );
        $this->assertSame( 'visits', $tabs['site_usage']['metrics'] );
        $this->assertSame( 'visits', $tabs['site_usage']['trendchartmetric'] );

        // Present but empty: the template indexes it, and a missing key is a
        // warning on every render. Nothing reads the value.
        $this->assertArrayHasKey( 'sort', $tabs['site_usage'] );
        $this->assertSame( '', $tabs['site_usage']['sort'] );
    }

    /**
     * A report is handed the sets, so a widget renderer can read them without
     * going back to the site.
     */
    public function testAReportIsGivenItsMetricSets(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'rendering a report loads the site list' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $data = (array) ( new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => 'pages', 'siteId' => '1', 'period' => 'last_thirty_days' ) ) )->doAction();

        $this->assertArrayHasKey( 'metricSets', $data );
        $this->assertArrayHasKey( \OWA\Core\MetricSets::DEFAULT_KEY, $data['metricSets'] );

        // ...and the legacy array the older templates read is still there,
        // built from the same source.
        $this->assertArrayHasKey( 'tabs', $data );
        $this->assertSame( array_keys( $data['metricSets'] ), array_keys( $data['tabs'] ) );
    }

    /**
     * A goal group's metrics: visits, one per active goal, then the total.
     *
     * This is the only part of deriving a set that has any logic in it, and it
     * was previously unreachable without a site that has goals configured --
     * deleting the whole goal-group loop changed nothing observable and no test
     * noticed. Pure now, so the assembly can be checked directly.
     */
    public function testAGoalGroupMeasuresEachActiveGoal(): void
    {
        $set = \OWA\Core\MetricSets::goalGroupSet( 'Signups', array( 1, 4, 7 ) );

        $this->assertSame( 'Signups', $set['label'] );
        $this->assertSame( 'visits,goal1Completions,goal4Completions,goal7Completions,goalValueAll',
            $set['metrics'] );
        $this->assertSame( 'visits', $set['chartMetric'] );
    }

    /**
     * The total is always last and always present.
     *
     * Grid columns follow the order of this list, so appending per-goal
     * metrics after the total would move the total column depending on how
     * many goals a group happens to have.
     */
    public function testTheGoalTotalIsAlwaysLast(): void
    {
        foreach ( array( array(), array( 2 ), array( 1, 2, 3, 4, 5 ) ) as $goals ) {

            $metrics = explode( ',', \OWA\Core\MetricSets::goalGroupSet( 'G', $goals )['metrics'] );

            $this->assertSame( 'visits', reset( $metrics ), 'visits leads' );
            $this->assertSame( 'goalValueAll', end( $metrics ), 'the total is last' );
            $this->assertCount( count( $goals ) + 2, $metrics );
        }
    }

    /**
     * The flat per-goal list the `goals` report draws its boxes from.
     *
     * Deliberately NOT a metric set: sets become tabs, and this is a panel
     * inside one report. Registering it would grow a tab on every tabbed
     * report in the install, which is the kind of change that shows up
     * somewhere nobody was looking.
     */
    public function testActiveGoalCompletionsIsNotOfferedAsASet(): void
    {
        foreach ( \OWA\Core\MetricSets::forSite( md5( 'metric-sets-probe.example' ) ) as $key => $set ) {

            $this->assertNotSame( 'activeGoalCompletions', $key );
            $this->assertStringNotContainsString( 'activeGoalCompletions', (string) $set['metrics'] );
        }
    }

    /**
     * A site with no active goals yields no metrics -- not a list with a hole
     * in it, and not the string "goalCompletions".
     */
    public function testASiteWithNoActiveGoalsMeasuresNoGoals(): void
    {
        $this->assertSame( '',
            \OWA\Core\MetricSets::activeGoalCompletions( md5( 'metric-sets-probe.example' ) ) );
    }

    /** A group with no active goals still measures visits and the total. */
    public function testAnEmptyGoalGroupIsStillUsable(): void
    {
        $this->assertSame( 'visits,goalValueAll',
            \OWA\Core\MetricSets::goalGroupSet( 'Empty', array() )['metrics'] );
    }

    /** Goal groups are keyed so a set name cannot collide with another kind. */
    public function testGoalGroupsAreKeyedDistinctly(): void
    {
        $this->assertSame( 'goal_group_3', \OWA\Core\MetricSets::goalGroupKey( 3 ) );

        $this->assertNotSame( \OWA\Core\MetricSets::DEFAULT_KEY,
            \OWA\Core\MetricSets::goalGroupKey( 1 ) );
    }

    /** A goal group set is the same shape as every other set. */
    public function testAGoalGroupSetHasTheStandardShape(): void
    {
        $this->assertSame( array( 'label', 'metrics', 'chartMetric' ),
            array_keys( \OWA\Core\MetricSets::goalGroupSet( 'G', array( 1 ) ) ) );
    }
}
