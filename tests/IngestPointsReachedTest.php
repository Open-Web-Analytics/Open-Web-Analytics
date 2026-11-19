<?php

require_once __DIR__ . '/IngestionTestCase.php';

use OWA\Module\Base\Classes\Ingest;

/**
 * One beacon through logEvent() meets every point after the request stage,
 * in order (IngestHookPointsTest covers log.php's). A point constant nothing
 * calls would enumerate cleanly, take a listener, and never run it.
 */
final class IngestPointsReachedTest extends IngestionTestCase
{
    /** @var list<string>|null points heard; null while no test listens */
    public static $heard = null;

    /** @var bool */
    private static $attached = false;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * There is no detach API: the probes stay for the process, and pass
         * every value through unchanged, so another test's ingest is not
         * affected by them.
         */
        if ( ! self::$attached ) {

            foreach ( Ingest::points() as $point ) {

                \OWA\Core\CoreAPI::registerFilter( $point, new IngestPointProbe( $point ) );
            }

            self::$attached = true;
        }

        self::$heard = [];
    }

    protected function tearDown(): void
    {
        self::$heard = null;

        parent::tearDown();
    }

    public function testAPageViewMeetsEveryPointAfterTheRequestStage(): void
    {
        $site = md5( 'owa-test-site' );

        $this->fireEvent( 'base.page_request', [
            'site_id'                => $site,
            'visitor_id'             => $this->uniqueGuid(),
            'session_id'             => $this->uniqueSessionId(),
            'page_url'               => 'https://owa-test-site/points',
            'page_location'          => 'https://owa-test-site/points',
            'page_title'             => 'Points',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
            'num_prior_sessions'     => 0,
        ] );

        $this->assertSame( [
            Ingest::EDGE_PRE, Ingest::EDGE_POST,
            Ingest::PROPERTY_PRE, Ingest::PROPERTY_POST,
            Ingest::TRACKING_EVENTS_PRE_SAVE,
        ], self::$heard );
    }
}

/** Records the point it is attached to, and passes the value through. */
final class IngestPointProbe
{
    /** @var string */
    private $point;

    public function __construct( string $point )
    {
        $this->point = $point;
    }

    /**
     * @param  mixed $value
     * @return mixed
     */
    public function __invoke( $value )
    {
        if ( IngestPointsReachedTest::$heard !== null ) {

            IngestPointsReachedTest::$heard[] = $this->point;
        }

        return $value;
    }

    /** EventDispatch::filter() logs each listener by name. */
    public function __toString(): string
    {
        return self::class . ':' . $this->point;
    }
}
