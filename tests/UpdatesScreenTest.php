<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * base.updates lists the modules with updates pending. With none it redirects
 * to the start page rather than showing an empty list and an Apply button.
 * It renders in the signed-out wrapper, as login and the installer do.
 */
final class UpdatesScreenTest extends TestCase
{
    private array $pending;
    private $startPage;

    protected function setUp(): void
    {
        $service         = \OWA\Core\CoreAPI::serviceSingleton();
        $this->pending   = $service->modules_needing_updates;
        $this->startPage = \OWA\Core\CoreAPI::getSetting( 'base', 'start_page' );
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules_needing_updates = $this->pending;
        \OWA\Core\CoreAPI::setSetting( 'base', 'start_page', $this->startPage );
    }

    private function run_action(): array
    {
        $controller = new \OWA\Module\Base\Controller\Updates( ['do' => 'base.updates'] );

        $returned = $controller->action();

        // A redirect is set on the controller rather than returned.
        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );

        return $returned ?? (array) $data->getValue( $controller );
    }

    /**
     * @covers-setting base.start_page
     */
    public function testWithNothingPendingItRedirectsToTheStartPage(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules_needing_updates = [];
        \OWA\Core\CoreAPI::setSetting( 'base', 'start_page', 'base.myProfile' );

        $data = $this->run_action();

        $this->assertSame( 'redirect', $data['view_method'] );
        $this->assertSame( 'base.myProfile', $data['do'],
            'It redirected somewhere other than the start_page setting.' );
        $this->assertArrayNotHasKey( 'view', $data );
    }

    /** The view the action names, assembled as index.php assembles it. */
    private function renderedView(): \OWA\Core\View
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules_needing_updates = ['base'];

        $data = $this->run_action();
        $view = \OWA\Core\CoreAPI::viewFactory( $data['view'], $data );
        $view->setData( $data );
        $view->assembleView( $data );

        return $view;
    }

    public function testItRendersInTheSignedOutWrapper(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules_needing_updates = ['base'];

        $html = (string) \OWA\Core\CoreAPI::displayView( $this->run_action() );

        $this->assertStringContainsString( 'owa_publicPage', $html,
            'The updates page took the signed-in wrapper, whose header it does not load the styles for.' );
    }

    public function testItRendersTheUpdatesListInsideTheWrapper(): void
    {
        $view = $this->renderedView();

        $this->assertSame( 'updates.php', basename( (string) $view->body->file ) );
        $this->assertSame( ['base'], $view->body->vars['modules'] ?? null );
    }

    public function testWithUpdatesPendingItListsTheModules(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules_needing_updates = ['base', 'maxmind_geoip'];

        $data = $this->run_action();

        $this->assertSame( 'base.updates', $data['view'] );
        $this->assertSame( 'delegate', $data['view_method'] );
        $this->assertSame( ['base', 'maxmind_geoip'], $data['modules'] );
    }
}
