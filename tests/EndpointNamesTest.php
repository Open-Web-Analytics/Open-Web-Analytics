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

    /**
     * @covers-setting base.disabledEndpoints
     */
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

    private const ENDPOINTS = ['index.php', 'api/index.php', 'log.php', 'install.php', 'cli.php'];

    /** @return iterable<string, array{mixed, list<string>}> */
    public static function values(): iterable
    {
        yield 'an array'            => [['log.php', 'cli.php'], ['log.php', 'cli.php']];
        yield 'one as a string'     => ['install.php', ['install.php']];
        yield 'a comma list'        => [' index.php , api/index.php,,log.php ', ['index.php', 'api/index.php', 'log.php']];
        yield 'an empty string'     => ['', []];
        yield 'unset'               => [null, []];
        yield 'an integer'          => [5, []];
        yield 'a boolean'           => [true, []];
    }

    /**
     * OWA_DISABLED_ENDPOINTS as a string, or of no usable type, made every
     * entry point throw a TypeError (in_array() of a string).
     *
     * @dataProvider values
     *
     * @covers-setting base.disabledEndpoints
     */
    public function testEveryShapeOfTheSettingIsRead($value, array $disabled): void
    {
        $owa = new class(null) extends \OWA\Core\Caller {
            public $settings = [];
            public function __construct($config) {}
            public function getSetting($module, $name) { return $this->settings[$name] ?? null; }
        };

        $owa->settings = ['disableAllEndpoints' => false, 'disabledEndpoints' => $value];

        foreach (self::ENDPOINTS as $endpoint) {
            $this->assertSame(in_array($endpoint, $disabled, true) ? null : true, $owa->isEndpointEnabled($endpoint), $endpoint);
        }
    }

    public function testAValueOfNoUsableTypeIsNamedForSystemHealth(): void
    {
        foreach ([[5, 'int'], [true, 'bool'], [1.5, 'float']] as [$value, $type]) {
            $this->assertSame('OWA_DISABLED_ENDPOINTS is of type ' . $type . ', not a list or a comma-separated string of entry points, '
                . 'so it is ignored and no entry point is turned off.', \OWA\Core\Caller::endpointListProblem($value));
        }

        foreach ([null, [], ['log.php'], 'log.php', 'log.php, cli.php'] as $fine) {
            $this->assertNull(\OWA\Core\Caller::endpointListProblem($fine), var_export($fine, true));
        }
    }
}
