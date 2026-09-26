<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Engagement time is stored in milliseconds and READ as a duration.
 *
 * The beacon carries `engagement_msec`, accrued on the device -- the same field
 * and unit GA collects as engagement_time_msec. The row keeps that, and the
 * renderer decides how to say it, which is the rule "(not set)" and "(unknown)"
 * already follow.
 *
 * Without this the replacement for 1.x's visitDuration would have rendered a
 * four-minute average as "247,000", which is a regression in the one respect
 * anybody would notice.
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
     * IT DOES NOT WRAP AT 24 HOURS.
     *
     * The reason this is not formatSeconds(): that is
     * date("G:i:s", mktime(0,0,$s)), which reads an hour-of-day back out, so 25
     * hours renders as 1:00:00. Harmless for the per-visit averages it was
     * written for -- and wrong for totalEngagementTime, which is a sum across a
     * whole reporting period and passes a day routinely.
     */
    public function testItDoesNotWrapAtADay(): void
    {
        $this->assertSame('1d 0:00:00', $this->rsm()->formatMilliseconds(86400000));
        $this->assertSame('1d 1:01:01', $this->rsm()->formatMilliseconds(90061000));

        // The bug being avoided, asserted so the reason stays visible.
        $this->assertSame('1:00:00', $this->rsm()->formatSeconds(90000),
            'formatSeconds wraps 25 hours to 1:00:00 -- this is why milliseconds '
          . 'does not delegate to it');
    }

    /** Absence stays absence; the dimension renderer decides the label. */
    public function testAbsenceIsUntouched(): void
    {
        $this->assertNull($this->rsm()->formatMilliseconds(null));
        $this->assertSame('', $this->rsm()->formatMilliseconds(''));
    }

    /**
     * A ratio's NULL survives every numeric formatter.
     *
     * A zero denominator gives NULL on purpose -- "no transactions, so revenue
     * per transaction is not a number" -- and a formatter that renders it as
     * $0.00 or 0.00% throws that away at the last step, reading as a measured
     * zero. numberFormatter already guarded; currency and percentage did not,
     * so every commerce ratio on a page with no purchases claimed a real zero.
     */
    public function testANullRatioIsNotFormattedIntoAValue(): void
    {
        $rsm = $this->rsm();

        $this->assertNull( $rsm->formatValue( 'currency', null ),
            'currency must not render a NULL ratio as 0.00' );

        $this->assertNull( $rsm->formatValue( 'percentage', null ),
            'percentage must not render a NULL ratio as 0.00%' );

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
