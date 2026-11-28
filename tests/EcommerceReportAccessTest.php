<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The e-commerce reports and metrics are read with view_reports, like every
 * other report. There is no e-commerce capability; a role configuration that
 * still names view_reports_ecommerce is ignored.
 */
final class EcommerceReportAccessTest extends TestCase
{
    private const SITE = 'owatest-ecommerce-site';

    private const ECOMMERCE_REPORTS = [ 'ecommerce', 'revenue', 'transactions', 'avg-order-value', 'ecommerce-conversion-rate' ];

    private function viewer(): \OWA\Module\Base\Classes\ServiceUser
    {
        $user = new \OWA\Module\Base\Classes\ServiceUser();
        $user->setRole( 'viewer' );

        return $user;
    }

    /** A viewer holds what each e-commerce report requires. */
    public function testAViewerMayOpenTheEcommerceReports(): void
    {
        $viewer = $this->viewer();

        foreach ( self::ECOMMERCE_REPORTS as $id ) {

            $definition = \OWA\Core\CoreAPI::getReportDefinition( $id );

            $this->assertNotEmpty( $definition['json'] ?? null, "report '$id' is a configured report" );

            $report = new \OWA\Core\ConfiguredReport( [] );
            $report->setDefinition( (array) json_decode( (string) file_get_contents( $definition['json'] ), true ) );

            $this->assertSame( 'view_reports', $report->getRequiredCapability(), $id );
            $this->assertTrue( $viewer->isCapable( $report->getRequiredCapability() ),
                "a viewer cannot open '$id'" );
        }
    }

    /** ...and may query an e-commerce metric over REST. */
    public function testAViewerMayQueryEcommerceMetricsOverRest(): void
    {
        $rest = new \OWA\Module\Base\Controller\ReportsRest( [
            'metrics'    => 'transactions,transactionRevenue',
            'dimensions' => 'date',
            'siteId'     => self::SITE,
        ] );

        $this->assertTrue( $this->viewer()->isCapable( $rest->getRequiredCapability() ) );
    }

    /** No role is granted it. */
    public function testTheCapabilityIsGone(): void
    {
        $fields = \OWA\Core\Module::settingsRegistry()['fields'];

        foreach ( (array) $fields['base|capabilities']['default'] as $role => $capabilities ) {

            $this->assertNotContains( 'view_reports_ecommerce', (array) $capabilities, $role );
        }

        $this->assertNotContains( 'view_reports_ecommerce', \OWA\Core\CoreAPI::getCapabilities( 'admin' ) );
    }

    /**
     * An owa-config.php written for 1.x grants it, as the demo install's does
     * to everyone. That is not an error, and it grants nothing.
     */
    public function testARoleConfigNamingItIsHarmless(): void
    {
        $config = \OWA\Core\CoreAPI::configSingleton();
        $caps   = $config->get( 'base', 'capabilities' );

        try {
            $config->addCapabilityToRole( 'viewer', 'view_reports_ecommerce' );
            $config->addCapabilityToRole( 'everyone', [ 'view_reports_ecommerce' ] );

            $this->assertNotContains( 'view_reports_ecommerce', \OWA\Core\CoreAPI::getCapabilities( 'viewer' ) );
            $this->assertNotContains( 'view_reports_ecommerce', \OWA\Core\CoreAPI::getCapabilities( 'everyone' ) );
            $this->assertContains( 'view_reports', \OWA\Core\CoreAPI::getCapabilities( 'viewer' ) );

            $viewer = $this->viewer();

            $this->assertTrue( $viewer->isCapable( 'view_reports' ) );
            $this->assertFalse( \OWA\Core\CoreAPI::isEveryoneCapable( 'view_reports_ecommerce' ) );

        } finally {
            $config->set( 'base', 'capabilities', $caps );
        }
    }
}
