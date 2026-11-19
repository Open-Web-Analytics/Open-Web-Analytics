<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A request's constraints reach the controller with their comparisons.
 *
 * Request values have markup stripped. A < that starts no tag is not markup,
 * so pageViews<5 and pageViews<=5 arrive as sent, from a report URL and over
 * REST alike; a tag in the same value is still removed.
 */
final class RequestKeepsComparisonsTest extends TestCase
{
    /** @var array */
    private $get;
    /** @var array */
    private $server;
    /** @var mixed */
    private $trackingMode;

    protected function setUp(): void
    {
        $this->get    = $_GET;
        $this->server = $_SERVER;

        // The test bootstrap runs in tracking mode, which skips the strip a
        // report or REST request goes through.
        $this->trackingMode = \OWA\Core\CoreAPI::getSetting( 'base', 'tracking_mode' );
        \OWA\Core\CoreAPI::setSetting( 'base', 'tracking_mode', false );

        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'owa.example.test';
        $_SERVER['SERVER_PORT']    = 80;
        $_SERVER['REQUEST_URI']    = '/owa/index.php';
    }

    protected function tearDown(): void
    {
        $_GET    = $this->get;
        $_SERVER = $this->server;
        \OWA\Core\CoreAPI::setSetting( 'base', 'tracking_mode', $this->trackingMode );
    }

    public function testLessThanConstraintsArriveAsSent(): void
    {
        $_GET = [ 'do' => 'base.report', 'constraints' => 'pageViews<5,bounces<=1' ];

        $r = new \OWA\Core\RequestContainer();

        $this->assertSame( 'pageViews<5,bounces<=1', $r->getParam( 'constraints' ) );
    }

    public function testATagInAConstraintIsStillStripped(): void
    {
        $_GET = [ 'do' => 'base.report', 'constraints' => 'pageTitle==a<script>b' ];

        $r = new \OWA\Core\RequestContainer();

        $this->assertSame( 'pageTitle==ab', $r->getParam( 'constraints' ) );
    }
}
