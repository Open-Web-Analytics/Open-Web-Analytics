<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\ResultSetManager;

/**
 * A result row's metadata describes that column, and no other.
 *
 * applyMetaDataToSingleResultRow() assigned $type and $data_type inside two
 * branches -- the key is a requested dimension, or a requested metric -- and a
 * row can hold a key that is neither. A calculated metric's children arrive in
 * the result without having been asked for: revenuePerTransaction is
 * 'transactionRevenue / transactions', so both of those land in the row while
 * only the parent is in $metrics.
 *
 * Reaching the third branch left the two variables holding whatever the
 * PREVIOUS key had set, so the child was reported as a dimension and formatted
 * as a date. On the first key there was nothing to inherit and PHP warned about
 * an undefined variable, which is how this surfaced -- four times in the Apache
 * error log, every one a REST query for revenuePerTransaction.
 *
 * The registry lookups are answered here rather than loaded, so these assert
 * the metadata assignment and nothing else.
 */
final class ResultRowMetaDataTest extends TestCase
{
    private function manager(array $dimensions, array $metrics)
    {
        $rsm = $this->getMockBuilder(ResultSetManager::class)
            ->onlyMethods(array('lookupDimension', 'getMetric', 'formatValue', 'getLabel'))
            ->getMock();

        $rsm->dimensions = $dimensions;
        $rsm->metrics    = $metrics;

        $rsm->method('lookupDimension')->willReturn(array('data_type' => 'timestamp'));

        $rsm->method('getMetric')->willReturn(new class {
            public function getDataType() { return 'currency'; }
        });

        // Identity, so a formatted value that differs is the formatter having
        // been chosen by the wrong data_type rather than a formatting change.
        $rsm->method('formatValue')->willReturnCallback(function ($type, $value) {
            return $value;
        });

        $rsm->method('getLabel')->willReturnCallback(function ($key = '') {
            return $key;
        });

        return $rsm;
    }

    public function testARequestedDimensionAndMetricAreDescribedCorrectly(): void
    {
        $row = $this->manager(array('date'), array('revenuePerTransaction'))
            ->applyMetaDataToSingleResultRow(
                array('date' => '20260915', 'revenuePerTransaction' => 12.5));

        $this->assertSame('dimension', $row['date']['result_type']);
        $this->assertSame('timestamp', $row['date']['data_type']);

        $this->assertSame('metric', $row['revenuePerTransaction']['result_type']);
        $this->assertSame('currency', $row['revenuePerTransaction']['data_type']);
    }

    /**
     * The defect. transactionRevenue is a child of the requested calculated
     * metric, so it is in the row but in neither list -- and it followed a
     * dimension.
     */
    public function testAnUnrequestedKeyDoesNotInheritThePreviousColumnsMetadata(): void
    {
        $row = $this->manager(array('date'), array('revenuePerTransaction'))
            ->applyMetaDataToSingleResultRow(array(
                'date'               => '20260915',
                'transactionRevenue' => 1234,
            ));

        $this->assertNull($row['transactionRevenue']['result_type'],
            'a key that is neither a requested dimension nor a requested metric '
            . 'must not be reported as the previous column was');

        $this->assertNull($row['transactionRevenue']['data_type']);

        // The column it followed is unaffected.
        $this->assertSame('dimension', $row['date']['result_type']);
        $this->assertSame('timestamp', $row['date']['data_type']);
    }

    /**
     * The same key first in the row: nothing to inherit, so this is the case
     * that warned rather than the case that lied.
     */
    public function testAnUnrequestedKeyFirstInTheRowRaisesNoWarning(): void
    {
        $seen = array();

        set_error_handler(function ($no, $message) use (&$seen) {
            $seen[] = $message;
            return true;
        }, E_WARNING | E_NOTICE);

        try {
            $row = $this->manager(array(), array())
                ->applyMetaDataToSingleResultRow(array('transactionRevenue' => 1234));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(array(), $seen,
            'reading an unset $type / $data_type is what reached the error log');

        $this->assertNull($row['transactionRevenue']['result_type']);
        $this->assertNull($row['transactionRevenue']['data_type']);
        $this->assertSame(1234, $row['transactionRevenue']['value']);
    }

    /**
     * Null reaches the formatter, so it has to be a value the formatter lookup
     * survives. Asserted against the real method rather than the stub above.
     */
    public function testTheFormatterLookupSurvivesANullDataType(): void
    {
        if (!class_exists('\OWA\Module\Base\Classes\ResultSetManager')) {
            $this->markTestSkipped('the class did not load');
        }

        $rsm = new ResultSetManager;

        $this->assertSame('unformatted', $rsm->formatValue(null, 'unformatted'),
            'an unknown data_type must return the value untouched');
    }
}
