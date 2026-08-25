<?php

use PHPUnit\Framework\TestCase;

/**
 * A constraint a caller asked for is applied, or refused -- never dropped.
 *
 * The failure this guards is the same one twice, from two causes:
 *
 *   - `source==`            a value went missing on the way in
 *   - `bogusDimension==x`   a name does not resolve
 *
 * Either way the constraint used to vanish and the query ran WITHOUT it,
 * answering with the unconstrained total. That is the worst available default
 * for reporting: a lost parameter becomes indistinguishable from a request for
 * everything, and unfiltered numbers look real. The empty-value half was found
 * in production -- a cache layer stripping owa_source, and the Source Detail
 * report showing the same visit count for every source with no error anywhere.
 *
 * The unknown-name half was found while tracing stored SQL-injection probes on
 * the demo install. Those were inert, but `bogusDimension==direct` returned
 * exactly what no constraint at all returned, which is how it surfaced.
 *
 * Neither of these was covered by a test before this file.
 */
final class ConstraintRefusalTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function rsm()
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the dimension/metric registry loads modules');
        }

        return new \OWA\Module\Base\Classes\ResultSetManager;
    }

    private function errorsOf($rsm): array
    {
        $p = new ReflectionProperty($rsm, 'errors');
        $p->setAccessible(true);

        return (array) $p->getValue($rsm);
    }

    /** The names a caller sent, as the manager will actually constrain on them. */
    private function appliedNames($rsm): array
    {
        return array_keys((array) $rsm->getConstraints());
    }

    public function testAValidDimensionConstraintIsApplied(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('medium==direct'));

        $this->assertContains('medium', $this->appliedNames($rsm));
        $this->assertSame([], $this->errorsOf($rsm));
    }

    public function testAValidMetricConstraintIsApplied(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('pageViews>=5'));

        $this->assertContains('pageViews', $this->appliedNames($rsm));
        $this->assertSame([], $this->errorsOf($rsm));
    }

    /**
     * The case this file was written for: a name that resolves to nothing.
     */
    public function testAnUnknownConstraintNameIsRefused(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('bogusDimension==direct'));

        $this->assertNotContains('bogusDimension', $this->appliedNames($rsm),
            'an unresolvable name must not reach the query');

        $errors = $this->errorsOf($rsm);

        $this->assertCount(1, $errors,
            'an unknown constraint name must be reported exactly once');
        $this->assertStringContainsString('bogusDimension', $errors[0]);
        $this->assertStringContainsString('not a request for everything', $errors[0]);
    }

    /** The half that already worked, pinned so the two cannot drift apart. */
    public function testAConstraintWithNoValueIsRefused(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('medium=='));

        $this->assertNotContains('medium', $this->appliedNames($rsm));

        $errors = $this->errorsOf($rsm);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('not a request for everything', $errors[0]);
    }

    /**
     * Both causes are refused the SAME way. They are one failure with two
     * origins, and a caller should not have to tell them apart to notice.
     */
    public function testBothRefusalsBehaveAlike(): void
    {
        $missingValue = $this->rsm();
        $missingValue->setConstraints($missingValue->constraintsStringToArray('medium=='));

        $unknownName = $this->rsm();
        $unknownName->setConstraints($unknownName->constraintsStringToArray('bogusDimension==direct'));

        $this->assertCount(1, $this->errorsOf($missingValue));
        $this->assertCount(1, $this->errorsOf($unknownName));

        $this->assertSame([], $this->appliedNames($missingValue));
        $this->assertSame([], $this->appliedNames($unknownName));
    }

    /**
     * A refusal must not take the good constraints with it.
     *
     * Reporting a bad name by abandoning the whole query would trade a silent
     * wrong answer for a loud missing one.
     */
    public function testAGoodConstraintSurvivesABadOneBesideIt(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('medium==direct,bogusDimension==x'));

        $this->assertContains('medium', $this->appliedNames($rsm));
        $this->assertNotContains('bogusDimension', $this->appliedNames($rsm));
        $this->assertCount(1, $this->errorsOf($rsm));
    }

    /**
     * A malformed request is not queried at all.
     *
     * Recording the error and running anyway was the worst of both: the caller
     * got the complaint AND a full set of numbers computed without the filter
     * they asked for. Numbers that look right are worse than none.
     */
    public function testARefusedConstraintMeansNoQueryRuns(): void
    {
        $rsm = $this->rsm();
        $rsm->setSiteId(md5('owa-test-site'));
        $rsm->metrics = $rsm->metricsStringToArray('pageViews');
        $rsm->setDimensions($rsm->dimensionsStringToArray('pagePath'));
        $rsm->setTimePeriod('date_range', '20100101', '20301231', '', '');
        $rsm->setConstraints($rsm->constraintsStringToArray('bogusDimension==direct'));

        $rs = $rsm->getResults();

        $this->assertSame(0, (int) $rs->resultsTotal,
            'a refused request must not return rows -- unfiltered numbers look real');
        $this->assertNotEmpty($rs->request_errors,
            'the caller must be told why nothing came back');
    }

    /**
     * The distinction the gate depends on.
     *
     * Most of $errors is routine -- a denormalized dimension such as
     * productName resolves only against certain entities, so lookupDimension()
     * records "not a registered dimension" during perfectly ordinary reports.
     * Measured: it fires twice in a clean run of the suite with no bad input
     * anywhere. Gating on "any error" would refuse to run those reports.
     */
    public function testARoutineLookupMissDoesNotRefuseTheQuery(): void
    {
        $rsm = $this->rsm();

        $rsm->addError('productName is not a registered dimension.');

        $this->assertFalse($rsm->hasRequestErrors(),
            'a routine internal miss must not be mistaken for a malformed request');
    }

    public function testAMalformedRequestIsDistinguishedFromNoise(): void
    {
        $rsm = $this->rsm();

        $rsm->addError('routine noise');
        $this->assertFalse($rsm->hasRequestErrors());

        $rsm->addRequestError('the caller asked for something impossible');
        $this->assertTrue($rsm->hasRequestErrors());

        // A request error is still reported alongside the rest.
        $this->assertContains('the caller asked for something impossible', $this->errorsOf($rsm));
    }

    /**
     * siteId is set as a constraint internally, so the name check must not
     * reject it -- it is a registered dimension, and site scoping runs through
     * exactly this path.
     */
    public function testSiteScopingIsNotRefusedByTheNameCheck(): void
    {
        $rsm = $this->rsm();
        $rsm->setSiteId('some-site-id');

        $this->assertContains('siteId', $this->appliedNames($rsm),
            'site scoping must survive: every report depends on it');
        $this->assertSame([], $this->errorsOf($rsm));
    }
}
