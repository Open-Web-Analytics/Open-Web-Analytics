<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * The Site Search report: what people searched for, as searches and as the
 * sessions that searched.
 *
 * Runs the report's OWN widget queries, read from reports/site-search.json,
 * against a fixture cube. Every session also has a page view, which the
 * report's constraint must keep out of every count.
 *
 *   session 1   boots, boots, Red Shoes
 *   session 2   boots
 *   session 3   (a page view, no search)
 *
 *   boots       3 searches, 2 sessions
 *   Red Shoes   1 search,   1 session
 */
final class SiteSearchReportTest extends TestCase
{
    const SITE     = 'owa-site-search-report-site';
    const PROPERTY = 7775000000000092;

    const VISITOR = 7775100000000092;
    const SESSION = 8885100000000092;

    /** session => the terms it searched, in order */
    const SEARCHES = array(
        array( 'boots', 'boots', 'Red Shoes' ),
        array( 'boots' ),
        array(),
    );

    public static function setUpBeforeClass(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->setProperties( array(
            'id'            => self::PROPERTY,
            'name'          => 'Site search report fixture',
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
            'name'        => 'Site search report fixture profile',
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

    /** A page view per session, then its searches. */
    private static function seedRows(): void
    {
        $day = (int) date( 'Ymd' );
        $ts  = time() * 1000000;
        $n   = 0;

        foreach ( self::SEARCHES as $i => $terms ) {

            $rows = array( array( 'page_view', null ) );

            foreach ( $terms as $term ) {
                $rows[] = array( 'view_search_results', $term );
            }

            foreach ( $rows as $row ) {

                $event = Cubes::entityFor( self::PROPERTY );

                $event->setProperties( array(
                    'id'             => 920000 + $n,
                    'event_type'     => $row[0],
                    'site_id'        => self::SITE,
                    'visitor_id'     => self::VISITOR + $i,
                    'session_id'     => self::SESSION + $i,
                    'prior_sessions' => 0,
                    'ts'             => $ts + $n,
                    'yyyymmdd'       => $day,
                    'page_path'      => '/search',
                    'search_term'    => $row[1],
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
            OWA_DIR . 'modules/Base/reports/site-search.json' ), true );

        $this->assertIsArray( $definition, 'reports/site-search.json did not parse' );

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

    /** Each term, most searched first, with the sessions that searched it. */
    public function testSearchTermsCountSearchesAndSessions(): void
    {
        $got = array();

        foreach ( $this->runWidget( 'terms' ) as $row ) {
            $got[] = array( $row['searchTerm']['value'],
                (int) $row['eventCount']['value'], (int) $row['sessions']['value'] );
        }

        $this->assertSame( array(
            array( 'boots', 3, 2 ),
            array( 'Red Shoes', 1, 1 ),
        ), $got );
    }

    /** The trend counts searches, not every event on the site. */
    public function testTheTrendCountsSearchesOnly(): void
    {
        $total = 0;

        foreach ( $this->runWidget( 'trend' ) as $row ) {
            $total += (int) $row['eventCount']['value'];
        }

        $this->assertSame( 4, $total,
            'four searches in the fixture; three page views that must not be counted' );
    }
}
