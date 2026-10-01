<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * The Scroll Depth report answers "how far down do people read".
 *
 * Every threshold a page view crosses raises its own scroll event, so counting
 * scroll events grouped by scrollDepth reads as "how many reached at least this
 * far" -- a depth funnel. This runs the report's OWN widget queries, read from
 * reports/scroll-depth.json, against a fixture cube: a hand-copied query could
 * pass while the report itself asked for something else.
 *
 * THE FIXTURE IS A FUNNEL WITH NO TWO LEVELS ALIKE, so a count landing on the
 * wrong threshold, or a constraint that let page views through, cannot pass by
 * coincidence:
 *
 *   /a  4 views   one visitor to 90, one to 50, one to 25, one not at all
 *   /b  2 views   one visitor to 75, one not at all
 *
 *   site-wide     25 -> 4   50 -> 3   75 -> 2   90 -> 1
 */
final class ScrollDepthReportTest extends TestCase
{
    const SITE     = 'owa-scroll-depth-report-site';
    const PROPERTY = 7775000000000091;

    const VISITOR = 7775100000000091;
    const SESSION = 8885100000000091;

    /** page => depth each visitor reached (0 = viewed, never scrolled) */
    const READING = array(
        '/a' => array( 90, 50, 25, 0 ),
        '/b' => array( 75, 0 ),
    );

    const THRESHOLDS = array( 25, 50, 75, 90 );

    public static function setUpBeforeClass(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->setProperties( array(
            'id'            => self::PROPERTY,
            'name'          => 'Scroll depth report fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ) );

        if ( ! $property->create() ) {
            throw new \RuntimeException( 'seeding owa_property failed' );
        }

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->setProperties( array(
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Scroll depth report fixture profile',
            'domain'      => 'example.test',
        ) );

        if ( ! $site->create() ) {
            throw new \RuntimeException( 'seeding owa_site failed' );
        }

        if ( ! Cubes::create( self::PROPERTY ) ) {
            throw new \RuntimeException( 'creating the fixture cube failed' );
        }

        self::seedRows();
    }

    public static function tearDownAfterClass(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        self::dropFixture();
    }

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; this reads a real cube.' );
        }
    }

    /**
     * A page view per visitor, and one scroll event per threshold they crossed --
     * which is what the tracker sends: every mark passed, not only the deepest.
     */
    private static function seedRows(): void
    {
        $day  = (int) date( 'Ymd' );
        $ts   = time() * 1000000;
        $n    = 0;
        $who  = 0;

        foreach ( self::READING as $page => $visitors ) {

            foreach ( $visitors as $reached ) {

                $visitor = self::VISITOR + $who;
                $session = self::SESSION + $who;
                $who++;

                $rows = array( array( 'page_view', null ) );

                foreach ( self::THRESHOLDS as $mark ) {

                    if ( $reached >= $mark ) {
                        $rows[] = array( 'scroll', $mark );
                    }
                }

                foreach ( $rows as $row ) {

                    $event = Cubes::entityFor( self::PROPERTY );

                    $event->setProperties( array(
                        'id'             => 910000 + $n,
                        'event_type'     => $row[0],
                        'site_id'        => self::SITE,
                        'visitor_id'     => $visitor,
                        'session_id'     => $session,
                        'prior_sessions' => 0,
                        'ts'             => $ts + $n,
                        'yyyymmdd'       => $day,
                        'page_path'      => $page,
                        'scroll_depth'   => $row[1],
                    ) );

                    if ( ! $event->create() ) {
                        throw new \RuntimeException( sprintf(
                            'seeding the fixture cube failed (%s): %s', $row[0],
                            \OWA\Core\CoreAPI::dbSingleton()->lastQueryError() ) );
                    }

                    $n++;
                }
            }
        }
    }

    private static function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query( sprintf( "DELETE FROM %s WHERE site_id = '%s'",
            \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName(), $db->prepare( self::SITE ) ) );

        $db->query( sprintf( 'DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory( 'base.property' )->getTableName(), self::PROPERTY ) );

        foreach ( array( '', '_rebuild', '_computed' ) as $suffix ) {
            $db->query( sprintf( 'DROP TABLE IF EXISTS %s%s', Cubes::tableFor( self::PROPERTY ), $suffix ) );
        }
    }

    /** @return array the report definition, as shipped */
    private function report(): array
    {
        $definition = json_decode( (string) file_get_contents(
            OWA_DIR . 'modules/Base/reports/scroll-depth.json' ), true );

        $this->assertIsArray( $definition, 'reports/scroll-depth.json did not parse' );

        return $definition;
    }

    /** The named widget's query, run with the report's own constraints. */
    private function runWidget( string $widgetId ): array
    {
        $report = $this->report();
        $query  = null;

        foreach ( $report['widgets'] as $widget ) {
            if ( $widget['id'] === $widgetId ) {
                $query = $widget['query'];
            }
        }

        $this->assertNotNull( $query, "the report has no widget '$widgetId'" );

        $constraints = array();

        foreach ( (array) ( $report['settings']['constraints'] ?? array() ) as $c ) {
            if ( isset( $c['value'] ) ) {
                $constraints[] = $c['dimension'] . '==' . $c['value'];
            }
        }

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( $query['metrics'] ?? $report['metrics'] );
        $rsm->setDimensions( $rsm->dimensionsStringToArray( $query['dimensions'] ) );
        $rsm->setConstraints( $rsm->parseConstraintsString( implode( ',', $constraints ) ) );
        $rsm->setTimePeriod( 'date_range', date( 'Ymd' ), date( 'Ymd' ) );
        $rsm->setSiteId( self::SITE );
        $rsm->setLimit( 100 );

        if ( ! empty( $query['sort'] ) ) {
            $rsm->setSorts( $rsm->sortStringToArray( $query['sort'] ) );
        }

        $rs = $rsm->getResults();

        $this->assertSame( array(), (array) $rs->errors, "the '$widgetId' query did not resolve" );

        return (array) $rs->resultsRows;
    }

    /**
     * The funnel, site-wide, shallowest first.
     *
     * Four page views of /a and two of /b are in the fixture and none of them may
     * reach this: the report is constrained to scroll events, and a page view
     * counted here would inflate every level by the same six.
     */
    public function testDepthReachedIsAFunnelInThresholdOrder(): void
    {
        $got = array();

        foreach ( $this->runWidget( 'depth' ) as $row ) {
            $got[ (int) $row['scrollDepth']['value'] ] = (int) $row['eventCount']['value'];
        }

        $this->assertSame( array( 25 => 4, 50 => 3, 75 => 2, 90 => 1 ), $got,
            'each level counts the visitors who reached at least that far, shallowest first' );
    }

    /**
     * The same funnel per page -- which is where "how far do people read THIS"
     * gets answered. /b has no 90 row because nobody reached it.
     */
    public function testDepthByPageSplitsTheFunnelPerPage(): void
    {
        $got = array();

        foreach ( $this->runWidget( 'pages' ) as $row ) {
            $got[ $row['pagePath']['value'] ][ (int) $row['scrollDepth']['value'] ]
                = (int) $row['eventCount']['value'];
        }

        foreach ( $got as &$levels ) {
            ksort( $levels );
        }

        ksort( $got );

        $this->assertSame( array(
            '/a' => array( 25 => 3, 50 => 2, 75 => 1, 90 => 1 ),
            '/b' => array( 25 => 1, 50 => 1, 75 => 1 ),
        ), $got );
    }

    /** The trend counts scroll events, not every event on the site. */
    public function testTheTrendCountsScrollEventsOnly(): void
    {
        $total = 0;

        foreach ( $this->runWidget( 'trend' ) as $row ) {
            $total += (int) $row['eventCount']['value'];
        }

        $this->assertSame( 10, $total,
            'ten scroll events in the fixture; six page views that must not be counted' );
    }
}
