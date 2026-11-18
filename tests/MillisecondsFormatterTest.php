<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Engagement time is stored in milliseconds and READ as a duration.
 *
 * The beacon carries `engagement_msec`, accrued on the device. The row keeps
 * that, and the renderer decides how to say it, which is the rule "(not set)"
 * and "(unknown)" already follow: a four-minute average reads as a duration,
 * not as "247,000".
 */
final class MillisecondsFormatterTest extends TestCase
{
    private function rsm()
    {
        return new \OWA\Module\Base\Classes\ResultSetManager;
    }

    public function testItReadsAsADuration(): void
    {
        $this->assertSame('4:07', $this->rsm()->formatMilliseconds(247000));
        $this->assertSame('0:01', $this->rsm()->formatMilliseconds(950));
    }

    /** Zero is a measurement. It must not read as absent. */
    public function testZeroIsADuration(): void
    {
        $this->assertSame('0:00', $this->rsm()->formatMilliseconds(0));
    }

    /**
     * IT DOES NOT WRAP AT 24 HOURS. totalEngagementTime is a sum across a
     * whole reporting period and passes a day routinely.
     */
    public function testItDoesNotWrapAtADay(): void
    {
        $this->assertSame('1d 0:00:00', $this->rsm()->formatMilliseconds(86400000));
        $this->assertSame('1d 1:01:01', $this->rsm()->formatMilliseconds(90061000));
    }

    /** Seconds read in the same format, a day or more included. */
    public function testSecondsReadTheSameAsMilliseconds(): void
    {
        $rsm = $this->rsm();

        foreach ( [ 0, 59, 247, 3600, 86399, 86400, 90000, 90061, '330', 1.5 ] as $s ) {

            $this->assertSame( $rsm->formatMilliseconds( $s * 1000 ), $rsm->formatSeconds( $s ),
                "formatSeconds($s)" );
        }

        $this->assertSame( '1d 1:00:00', $rsm->formatSeconds( 90000 ) );
        $this->assertSame( '1d 1:00:00', $rsm->formatValue( 'timestamp', 90000 ) );
    }

    public function testSecondsAbsenceIsUntouched(): void
    {
        $this->assertNull( $this->rsm()->formatSeconds( null ) );
        $this->assertSame( '', $this->rsm()->formatSeconds( '' ) );
        $this->assertNull( $this->rsm()->formatValue( 'timestamp', null ) );
    }

    /** Absence stays absence; the dimension renderer decides the label. */
    public function testAbsenceIsUntouched(): void
    {
        $this->assertNull($this->rsm()->formatMilliseconds(null));
        $this->assertSame('', $this->rsm()->formatMilliseconds(''));
    }

    /**
     * A NULL survives every numeric formatter: a value the query did not
     * return is not shown as $0.00 or 0.00%.
     */
    public function testANullIsNotFormattedIntoAValue(): void
    {
        $rsm = $this->rsm();

        $this->assertNull( $rsm->formatValue( 'currency', null ),
            'currency must not render a NULL as 0.00' );

        $this->assertNull( $rsm->formatValue( 'percentage', null ),
            'percentage must not render a NULL as 0.00%' );

        $this->assertNull( $rsm->formatValue( 'integer', null ) );
        $this->assertNull( $rsm->formatValue( 'milliseconds', null ) );

        // A real zero still formats, so the guards are not swallowing values.
        $this->assertSame( '0.00%', $rsm->formatValue( 'percentage', 0 ) );
        $this->assertSame( '0:00', $rsm->formatValue( 'milliseconds', 0 ) );
    }

    /** And the metric layer accepts the type, or a metric would refuse it. */
    public function testTheMetricLayerAcceptsTheType(): void
    {
        $metric = new \OWA\Core\Metric;
        $metric->setDataType('milliseconds');

        $this->assertSame('milliseconds', $metric->getDataType());
    }
}
