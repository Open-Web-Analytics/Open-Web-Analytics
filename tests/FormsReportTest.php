<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * The Forms report: forms started and submitted, by the page they were on.
 *
 * Runs the report's OWN widget queries and constraint, read from
 * reports/forms.json, against a fixture cube. No two pages alike, so a count
 * landing on the wrong page or the wrong metric cannot pass by coincidence:
 *
 *   /contact   4 starts   3 submits    75%
 *   /search    1 start    2 submits   200%   sent twice from one page view
 *   /signup    5 starts   1 submit     20%
 *   /about     page views and a custom event named signup_form_submit
 *
 * /about must not appear: the constraint keeps page views out, and is anchored,
 * so an event that only CONTAINS form_submit -- the name a migrated 1.x action
 * "Signup Form Submit" gets -- is not a form submit.
 */
final class FormsReportTest extends TestCase
{
    const SITE     = 'owa-forms-report-site';
    const PROPERTY = 7775000000000094;

    const VISITOR = 7775100000000094;
    const SESSION = 8885100000000094;

    /** page => array( form starts, form submits ) */
    const FORMS = array(
        '/contact' => array( 4, 3 ),
        '/search'  => array( 1, 2 ),
        '/signup'  => array( 5, 1 ),
        '/about'   => array( 0, 0 ),
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
            'name'          => 'Forms report fixture',
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
            'name'        => 'Forms report fixture profile',
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

    /** A page view per page, then its form events; /about gets the decoy. */
    private static function seedRows(): void
    {
        $day = (int) date( 'Ymd' );
        $ts  = time() * 1000000;
        $n   = 0;
        $who = 0;

        foreach ( self::FORMS as $page => $counts ) {

            $rows = array( 'page_view' );
            $rows = array_merge( $rows,
                array_fill( 0, $counts[0], 'form_start' ),
                array_fill( 0, $counts[1], 'form_submit' ) );

            if ( $page === '/about' ) {
                $rows[] = 'page_view';
                $rows[] = 'signup_form_submit';
            }

            foreach ( $rows as $type ) {

                $event = Cubes::entityFor( self::PROPERTY );

                $event->setProperties( array(
                    'id'             => 940000 + $n,
                    'event_type'     => $type,
                    'site_id'        => self::SITE,
                    'visitor_id'     => self::VISITOR + $who,
                    'session_id'     => self::SESSION + $who,
                    'prior_sessions' => 0,
                    'ts'             => $ts + $n,
                    'yyyymmdd'       => $day,
                    'page_path'      => $page,
                ) );

                if ( ! $event->create() ) {
                    throw new \RuntimeException( sprintf(
                        'seeding the fixture cube failed (%s): %s', $type,
                        \OWA\Core\CoreAPI::dbSingleton()->lastQueryError() ) );
                }

                $n++;
            }

            $who++;
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
            OWA_DIR . 'modules/Base/reports/forms.json' ), true );

        $this->assertIsArray( $definition, 'reports/forms.json did not parse' );
        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( $definition ) );

        return $definition;
    }

    /**
     * The report's constraint string, built by ConfiguredReport itself: the
     * regular expression has to survive that string and its parse back, and a
     * hand-built copy could not show it does.
     */
    private function constraints( array $report ): string
    {
        $build = new \ReflectionMethod( \OWA\Core\ConfiguredReport::class, 'buildConstraints' );
        $build->setAccessible( true );

        return $build->invoke( null, $report['settings']['constraints'], array() );
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

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( $query['metrics'] ?? $report['metrics'] );
        $rsm->setDimensions( $rsm->dimensionsStringToArray( $query['dimensions'] ) );
        $rsm->setConstraints( $rsm->parseConstraintsString( $this->constraints( $report ) ) );
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

    public function testFormsByPageCountStartsAndSubmitsMostSubmittedFirst(): void
    {
        $got = array();

        foreach ( $this->runWidget( 'pages' ) as $row ) {
            $got[ $row['pagePath']['value'] ] = array(
                (int) $row['formStarts']['value'],
                (int) $row['formSubmits']['value'],
            );
        }

        $this->assertSame( array(
            '/contact' => array( 4, 3 ),
            '/search'  => array( 1, 2 ),
            '/signup'  => array( 5, 1 ),
        ), $got, 'one row per page with a form, most submits first; /about has none' );
    }

    /** Submits over starts, and a double submit takes a page past 100%. */
    public function testSubmitRateIsSubmitsOverStarts(): void
    {
        $got = array();

        foreach ( $this->runWidget( 'pages' ) as $row ) {
            $got[ $row['pagePath']['value'] ] = (float) $row['formSubmitRate']['value'];
        }

        $this->assertEqualsWithDelta( 0.75, $got['/contact'], 0.0001 );
        $this->assertEqualsWithDelta( 2.0, $got['/search'], 0.0001 );
        $this->assertEqualsWithDelta( 0.2, $got['/signup'], 0.0001 );
    }

    /** The trend counts form submits only: not the decoy, not page views. */
    public function testTheTrendCountsFormSubmitsOnly(): void
    {
        $total = 0;

        foreach ( $this->runWidget( 'trend' ) as $row ) {
            $total += (int) $row['formSubmits']['value'];
        }

        $this->assertSame( 6, $total );
    }

    /**
     * The constraint matches form_start and form_submit and nothing else, so
     * eventCount under it is their sum: ten starts and six submits.
     */
    public function testTheConstraintAdmitsOnlyTheTwoFormEvents(): void
    {
        $report = $this->report();

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( 'eventCount' );
        $rsm->setDimensions( $rsm->dimensionsStringToArray( 'eventName' ) );
        $rsm->setConstraints( $rsm->parseConstraintsString( $this->constraints( $report ) ) );
        $rsm->setTimePeriod( 'date_range', date( 'Ymd' ), date( 'Ymd' ) );
        $rsm->setSiteId( self::SITE );
        $rsm->setLimit( 100 );

        $rs = $rsm->getResults();

        $this->assertSame( array(), (array) $rs->errors );

        $got = array();

        foreach ( (array) $rs->resultsRows as $row ) {
            $got[ $row['eventName']['value'] ] = (int) $row['eventCount']['value'];
        }

        ksort( $got );

        $this->assertSame( array( 'form_start' => 10, 'form_submit' => 6 ), $got );
    }
}
