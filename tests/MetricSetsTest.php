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

        $this->assertStringContainsString( 'sessions', $default['metrics'] );
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
            'site_usage' => array( 'label' => 'Site Usage', 'metrics' => 'sessions', 'chartMetric' => 'sessions' ),
            'ecommerce'  => array( 'label' => 'e-commerce', 'metrics' => 'transactions', 'chartMetric' => 'transactions' ),
        );

        $tabs = \OWA\Core\MetricSets::toLegacyTabs( $sets );

        $this->assertSame( array_keys( $sets ), array_keys( $tabs ),
            'the same sets, in the same order' );

        $this->assertSame( 'Site Usage', $tabs['site_usage']['tab_label'] );
        $this->assertSame( 'sessions', $tabs['site_usage']['metrics'] );
        $this->assertSame( 'sessions', $tabs['site_usage']['trendchartmetric'] );

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
     * A site with an active goal event offers the Conversions set, and no other.
     *
     * 1.x's goal groups each added a set measuring goal{N}Completions and
     * goalValueAll, which v2 does not have -- a migrated goal put an unresolvable
     * "Sale" tab on every report. The set offered now measures goalConversions,
     * which v2 counts from is_goal_event.
     */
    public function testAnActiveGoalEventAddsTheConversionsSet(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id, property_id' );

        $site = array();

        foreach ( (array) $db->getAllRows() as $row ) {
            if ( ! empty( $row['property_id'] ) ) {
                $site = $row;
                break;
            }
        }

        if ( empty( $site['property_id'] ) ) {
            $this->markTestSkipped( 'Needs a Profile with a Property.' );
        }

        // Another active goal event on this Property already offers the set.
        $before = in_array( 'conversions', array_keys( \OWA\Core\MetricSets::forSite( $site['site_id'] ) ), true );

        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $id   = $goal->generateId( 'goal_event:metric-sets-probe:' . uniqid( '', true ) );

        $goal->set( 'id', $id );
        $goal->set( 'property_id', $site['property_id'] );
        $goal->set( 'name', 'Metric sets probe' );
        $goal->set( 'goal_number', 1 );
        $goal->set( 'is_active', 1 );
        $goal->set( 'trigger_event_type', 'page_view' );
        $goal->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $goal->create();

        try {
            $keys = array_keys( \OWA\Core\MetricSets::forSite( $site['site_id'] ) );
        } finally {
            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )->delete( $id );
        }

        $this->assertContains( 'conversions', $keys );
        $this->assertSame( array(), array_diff( $keys, array( 'site_usage', 'conversions', 'ecommerce' ) ),
            'a set other than site usage, conversions and e-commerce appeared' );

        $this->assertSame( $before,
            in_array( 'conversions', array_keys( \OWA\Core\MetricSets::forSite( $site['site_id'] ) ), true ),
            'with the probe goal event gone, the set is as it was' );
    }
}
