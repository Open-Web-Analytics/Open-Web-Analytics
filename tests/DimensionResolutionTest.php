<?php

use PHPUnit\Framework\TestCase;

/**
 * How a query resolves the names it is given.
 *
 * lookupDimension() answers a dimension of the cube, or null -- without a PHP
 * diagnostic -- for a name that is not one. A query naming a metric or a
 * dimension that is not one is refused with a request error naming it.
 */
final class DimensionResolutionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function manager(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory('base', 'resultSetManager');
    }

    /** A manager for a query with no constraints, which is what a caller sets up. */
    private function query(array $metrics, array $dimensions = []): object
    {
        $rsm = $this->manager();
        $rsm->metrics = $metrics;
        $rsm->setDimensions($dimensions);
        $rsm->setConstraints([]);

        return $rsm;
    }

    /**
     * Capture PHP diagnostics raised during $fn rather than letting them pass
     * as warnings nothing fails on.
     */
    private function diagnosticsFrom(callable $fn, &$result = null): array
    {
        $seen = [];

        set_error_handler(static function ($no, $str) use (&$seen) {
            $seen[] = $str;
            return true;
        });

        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        return $seen;
    }

    /**
     * @dataProvider unresolvableDimensions
     */
    public function testAnUnresolvableDimensionIsNullAndDoesNotWarn(string $name): void
    {
        $rsm = $this->manager();
        $result = 'unset';

        $diags = $this->diagnosticsFrom(
            fn() => $rsm->lookupDimension($name, \OWA\Core\CoreAPI::entityFactory('base.event')),
            $result
        );

        $this->assertSame([], $diags, 'an unknown name raised a PHP diagnostic');
        $this->assertNull($result);
        $this->assertContains("$name is not a registered dimension.", $rsm->errors);
    }

    public static function unresolvableDimensions(): array
    {
        return [
            'zz_not_a_thing'    => ['zz_not_a_thing'],
            'empty string'      => [''],
            'sql-ish'           => ["1' OR '1'='1"],
        ];
    }

    /** A dimension of the cube resolves to its column, behind the table's alias. */
    public function testADimensionOfTheCubeResolves(): void
    {
        $rsm = $this->manager();
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event');
        $result = null;

        $diags = $this->diagnosticsFrom(fn() => $rsm->lookupDimension('pagePath', $entity), $result);

        $this->assertSame([], $diags);
        $this->assertSame('pagePath', $result['name'] ?? null);
        $this->assertStringStartsWith($entity->getTableAlias() . '.', (string) ($result['column'] ?? ''));
    }

    /** No dimension is a column of any other entity. */
    public function testADimensionOfAnotherEntityDoesNotResolve(): void
    {
        $rsm = $this->manager();
        $result = 'unset';

        $diags = $this->diagnosticsFrom(
            fn() => $rsm->lookupDimension('pagePath', \OWA\Core\CoreAPI::entityFactory('base.event_raw')),
            $result
        );

        $this->assertSame([], $diags);
        $this->assertNull($result);
    }

    /** A query naming a dimension that is not one is refused, and says which. */
    public function testAQueryNamingAnUnknownDimensionIsRefused(): void
    {
        $rsm = $this->query(['pageViews'], ['pagePath', 'zz_not_a_thing']);

        $this->assertNull($rsm->loadBaseEntity());
        $this->assertSame(['"zz_not_a_thing" cannot be queried here.'], $rsm->request_errors);
    }

    /** ...and so is one naming a metric that is not one. */
    public function testAQueryNamingAnUnknownMetricIsRefused(): void
    {
        $rsm = $this->query(['pageViews', 'zzNotAMetric']);

        $this->assertNull($rsm->loadBaseEntity());
        $this->assertSame(['"zzNotAMetric" cannot be queried here.'], $rsm->request_errors);
    }

    /** Known names give the cube, with a calculated metric's children built. */
    public function testKnownNamesGiveTheCube(): void
    {
        $rsm = $this->query(['pageViewsPerSession'], ['pagePath']);

        $entity = $rsm->loadBaseEntity();

        $this->assertSame([], $rsm->request_errors);
        $this->assertSame(\OWA\Core\Module::FACT_ENTITY, $entity->getName());
        $this->assertArrayHasKey('pageViewsPerSession', $rsm->calculatedMetrics);
        $this->assertArrayHasKey('pageViews', $rsm->metricObjects);
        $this->assertArrayHasKey('sessions', $rsm->metricObjects);
    }
}
