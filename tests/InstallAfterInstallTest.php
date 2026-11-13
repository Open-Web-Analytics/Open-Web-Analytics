<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * On an installed system the installer does nothing but redirect.
 *
 * Install::pre() sent a redirect when the install was complete, but
 * Controller::doAction() calls action() whatever pre() returns. The install
 * screens still rendered under the redirect, and base.installBase still ran:
 * the schema install again, and a new site for whatever domain was posted.
 */
final class InstallAfterInstallTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function installControllers(): array
    {
        return array(
            'installStart'         => array( \OWA\Module\Base\Controller\InstallStart::class ),
            'installCheckEnv'      => array( \OWA\Module\Base\Controller\InstallCheckEnv::class ),
            'installConfig'        => array( \OWA\Module\Base\Controller\InstallConfig::class ),
            'installDefaultsEntry' => array( \OWA\Module\Base\Controller\InstallDefaultsEntry::class ),
            'installBase'          => array( \OWA\Module\Base\Controller\InstallBase::class ),
            'installFinish'        => array( \OWA\Module\Base\Controller\InstallFinish::class ),
        );
    }

    /**
     * @dataProvider installControllers
     */
    public function testAnInstalledSystemRunsNoInstallStep( string $class ): void
    {
        $controller = $this->controller( $class, true );

        $this->assertSame( array(), $controller->doAction() );
        $this->assertSame( array( 'redirect' ), $controller->calls );
    }

    public function testBeforeInstallTheStepRuns(): void
    {
        $controller = $this->controller( \OWA\Module\Base\Controller\InstallStart::class, false );

        $controller->doAction();

        $this->assertContains( 'action', $controller->calls );
        $this->assertNotContains( 'redirect', $controller->calls );
    }

    /**
     * The controller, told whether the install is complete, recording what it
     * did instead of doing it.
     */
    private function controller( string $class, bool $installed )
    {
        $code = 'return new class( array() ) extends ' . $class . ' {
            public $calls = array();
            public $installed = false;
            function isInstallComplete() { return $this->installed; }
            function redirectBrowserToUrl( $url, array $also_hosts = array() ) { $this->calls[] = "redirect"; }
            function action() { $this->calls[] = "action"; }
            function isCurrentUserCapable( $capability, $currentSiteId = null ) { return true; }
        };';

        $controller = eval( $code );
        $controller->installed = $installed;

        return $controller;
    }
}
