<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * CoreAPI::metricFactory() builds a metric from its registered name.
 *
 * Given a name without a dot it read the registration, a list of definitions,
 * as the class id: "Array", which it then failed to resolve, so every
 * registered name threw.
 */
final class MetricFactoryNameTest extends TestCase
{
    public function testARegisteredNameBuildsThatMetric(): void
    {
        $m = \OWA\Core\CoreAPI::metricFactory('pageViews');

        $this->assertInstanceOf(\OWA\Module\Base\Metric\ConfigurableMetric::class, $m);
        $this->assertSame('pageViews', $m->getName());
    }

    /** Built as the registration's class with its params, as ResultSetManager builds it. */
    public function testItIsTheMetricTheRegistrationBuilds(): void
    {
        $registered = \OWA\Core\CoreAPI::serviceSingleton()->getMetricClasses('pageViews');
        $definition = end($registered);

        $this->assertEquals(
            \OWA\Core\CoreAPI::metricFactory($definition['class'], $definition['params']),
            \OWA\Core\CoreAPI::metricFactory('pageViews'));
    }

    /** $params given are applied over the registered ones. */
    public function testParamsGivenWin(): void
    {
        $m = \OWA\Core\CoreAPI::metricFactory('pageViews', ['label' => 'Probe label']);

        $this->assertSame('Probe label', $m->getLabel());
    }

    public function testAnUnregisteredNameNamesItself(): void
    {
        $this->expectExceptionMessage('No metric is registered as zzNoSuchMetric.');

        \OWA\Core\CoreAPI::metricFactory('zzNoSuchMetric');
    }
}
