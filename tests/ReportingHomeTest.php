<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Reporting resumes where it left off.
 *
 * base.sites -- a flat roster of every tracked site with a thumbnail, trend
 * metrics and five management links per row -- was the landing page, the
 * "Reporting" link and the redirect after every save. Its navigation job is the
 * site control's now, and its five links are the tier nav's; it was the last
 * screen still offering the old flat route to them.
 *
 * Landing on a report is what the roster was a step in the way of.
 */
final class ReportingHomeTest extends TestCase
{
    private function redirect( array $params = array() ): array
    {
        $controller = new \OWA\Module\Base\Controller\ReportingHome( $params );
        $controller->action();

        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $data->setAccessible( true );

        return (array) $data->getValue( $controller );
    }

    public function testItLandsOnAReportNotARoster(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $data = $this->redirect();

        $this->assertSame( 'redirect', $data['view_method'] ?? null );
        $this->assertSame( 'base.report', $data['do'] ?? null );

        /*
         * base.report 400s without a reportId -- it has no default -- so the
         * landing action must supply one or the start page is an error page.
         */
        $this->assertNotEmpty(
            $data['reportId'] ?? null,
            'base.report refuses a request with no reportId, so landing there without one '
            . 'turns the start page into a 400.' );

        // A report is of a Property, so that is what the landing names.
        $this->assertNotEmpty( $data['propertyId'] ?? null );
        $this->assertArrayNotHasKey( 'siteId', $data );
    }

    /**
     * The remembered Property is checked against what the viewer may see.
     *
     * A cookie can name a Property that has been deleted, whose grant has been
     * revoked, or that belongs to whoever used this browser last. Trusting it
     * would answer a capability failure instead of a report.
     */
    public function testARememberedPropertyTheViewerCannotSeeIsIgnored(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;

        $cookies = new \ReflectionProperty( $request, 'cookies' );
        $cookies->setAccessible( true );
        $original = $cookies->getValue( $request );

        try {

            $ns = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );

            $cookies->setValue( $request, array_merge( (array) $original, array(
                $ns . \OWA\Module\Base\Controller\ReportingHome::LAST_PROPERTY_COOKIE
                    => '987654321987',
            ) ) );

            $data = $this->redirect();

            $this->assertNotSame(
                '987654321987', $data['propertyId'] ?? null,
                'A Property named only by a cookie was trusted without checking the viewer '
                . 'can open it.' );

            $this->assertNotEmpty(
                $data['propertyId'] ?? null,
                'It fell back to nothing rather than to a Property the viewer has.' );

        } finally {

            $cookies->setValue( $request, $original );
        }
    }

    /** It is written where the answer is known: when a report renders. */
    public function testThePropertyIsRememberedWhenAReportRenders(): void
    {
        $src = (string) file_get_contents( OWA_DIR . 'Core/ReportController.php' );

        $this->assertStringContainsString(
            'ReportingHome::LAST_PROPERTY_COOKIE', $src,
            'Nothing records the Property, so reporting always resumes on the first one.' );

        $this->assertStringContainsString(
            "if ( \$propertyId !== '' ) {\n\n            \\OWA\\Core\\CoreAPI::createCookie(", $src,
            'It would write an empty cookie on a report that resolved no Property.' );
    }

    /** Nothing points at the retired roster. */
    public function testTheRosterIsGone(): void
    {
        $this->assertFileDoesNotExist( OWA_DIR . 'modules/Base/Controller/Sites.php' );
        $this->assertFileDoesNotExist( OWA_DIR . 'modules/Base/templates/sites.php' );

        $this->assertSame(
            'base.reportingHome',
            \OWA\Core\CoreAPI::getSetting( 'base', 'start_page' ),
            'The start page still names the retired roster, so login lands on a 404.' );

        $header = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/header.php' );

        $this->assertStringNotContainsString( "'base.sites'", $header );
    }
}
