<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\ReportSegment;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The segment picker's lists (ReportSegment::options()) are the query layer's
 * vocabulary, read from the registry: no query, so no period is needed and
 * no page view pays for an aggregate to fill a picker.
 */
final class ReportSegmentOptionsTest extends TestCase
{
    public function testTheListsComeFromTheRegistryWithoutAPeriod(): void
    {
        $options = (new ReportSegment(null, null, null, null, ''))->options();
        $rsm     = new \OWA\Module\Base\Classes\ResultSetManager();

        $this->assertNotEmpty($options['dimensions']);
        $this->assertSame($rsm->relatedMetrics(), $options['metrics']);

        foreach (ReportSegment::EXCLUDED_GROUPS as $group) {
            $this->assertArrayNotHasKey($group, $options['dimensions'], "$group is not offered");
        }

        $this->assertSame(array_diff_key($rsm->relatedDimensions(), array_flip(ReportSegment::EXCLUDED_GROUPS)),
            $options['dimensions']);
    }
}
