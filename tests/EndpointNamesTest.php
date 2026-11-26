<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * disabledEndpoints names each entry point by its path from the install's
 * directory: index.php is the admin and reports, api/index.php the REST API.
 * Both asked by the name index.php, so turning the admin off turned off the
 * API too, and api/index.php, as documented, turned off nothing.
 */
final class EndpointNamesTest extends TestCase
{
    public function testTheApiAsksByItsPath(): void
    {
        $this->assertStringContainsString("isEndpointEnabled( 'api/index.php' )",
            (string) file_get_contents(__DIR__ . '/../api/index.php'));
        $this->assertStringContainsString('isEndpointEnabled( basename( __FILE__ ) )',
            (string) file_get_contents(__DIR__ . '/../index.php'));
    }

    public function testTheAdminAndTheApiAreTurnedOffApart(): void
    {
        // The check alone, on settings of the test's: the caller's boot is not what is tested.
        $owa = new class(null) extends \OWA\Core\Caller {
            public $settings = [];
            public function __construct($config) {}
            public function getSetting($module, $name) { return $this->settings[$name] ?? null; }
        };

        $owa->settings = ['disableAllEndpoints' => false, 'disabledEndpoints' => ['index.php']];
        $this->assertNull($owa->isEndpointEnabled('index.php'));
        $this->assertTrue($owa->isEndpointEnabled('api/index.php'));

        $owa->settings['disabledEndpoints'] = ['api/index.php'];
        $this->assertTrue($owa->isEndpointEnabled('index.php'));
        $this->assertNull($owa->isEndpointEnabled('api/index.php'));
    }
}
