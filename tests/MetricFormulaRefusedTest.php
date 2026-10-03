<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A calculated metric is a ratio or a difference. The 1.x formula -- an
 * expression eval()'d with child values substituted in by metric name -- is
 * refused when the metric is built, and nothing in OWA eval()s any more.
 */
final class MetricFormulaRefusedTest extends TestCase
{
    public function testAFormulaMetricIsBuiltAsNeitherRatioNorDifference(): void
    {
        $m = new \OWA\Module\Base\Metric\ConfigurableMetric([
            'name'          => 'aliceFormula',
            'label'         => 'Alice formula',
            'metric_type'   => 'calculated',
            'data_type'     => 'decimal',
            'formula'       => 'pageViews / sessions',
            'child_metrics' => ['pageViews', 'sessions'],
        ]);

        $this->assertTrue($m->isCalculated());
        $this->assertFalse($m->isRatio());
        $this->assertFalse($m->isDifference());
        $this->assertSame([], $m->getChildMetrics(), 'no children taken from a formula declaration');
        $this->assertFalse(method_exists($m, 'getFormula'));
    }

    public function testARatioStillBuilds(): void
    {
        $m = new \OWA\Module\Base\Metric\ConfigurableMetric([
            'name'        => 'aliceRatio',
            'label'       => 'Alice ratio',
            'metric_type' => 'calculated',
            'data_type'   => 'decimal',
            'numerator'   => 'pageViews',
            'denominator' => 'sessions',
        ]);

        $this->assertTrue($m->isRatio());
        $this->assertSame(['pageViews', 'sessions'], $m->getChildMetrics());
    }

    public function testNothingInOwaEvals(): void
    {
        $hits = [];
        foreach (['Core', 'modules'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php' || strpos($f->getPathname(), '/vendor/') !== false) {
                    continue;
                }
                foreach (token_get_all((string) file_get_contents($f->getPathname())) as $t) {
                    if (is_array($t) && $t[0] === T_EVAL) {
                        $hits[] = substr($f->getPathname(), strlen(dirname(__DIR__)) + 1) . ':' . $t[2];
                    }
                }
            }
        }

        $this->assertSame([], $hits);
    }
}
