<?php

use PHPUnit\Framework\TestCase;

/**
 * A constraint a caller asked for is applied, or refused -- never dropped.
 *
 * Two causes of the same failure:
 *
 *   - `source==`            a value went missing on the way in (a cache layer
 *                           stripping owa_source, say)
 *   - `bogusDimension==x`   a name does not resolve
 *
 * A query run WITHOUT the constraint answers with the unconstrained total. That
 * is the worst available default for reporting: a lost parameter becomes
 * indistinguishable from a request for everything, and unfiltered numbers look
 * real.
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

        return (array) $p->getValue($rsm);
    }

    /** The names a caller sent, as the manager will actually constrain on them. */
    private function appliedNames($rsm): array
    {
        return array_keys((array) $rsm->getConstraints());
    }

    /**
     * A filter's value is read as written. It was entity-decoded, to match
     * values ingest had escaped, so "Tom &amp; Jerry" filtered on "Tom & Jerry".
     */
    public function testAConstraintValueIsReadAsWritten(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();

        $parsed = $rsm->parseConstraintsString('pageTitle==Tom &amp; Jerry');

        $this->assertSame('Tom &amp; Jerry', $parsed[0]['value'] ?? null);
    }

    /**
     * A clause with no operator is refused, not dropped: dropped, the query
     * ran without it. Commas with nothing between them say nothing.
     */
    public function testAClauseWithNoOperatorIsRefused(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();

        $parsed = $rsm->parseConstraintsString('pagePath==/a,b,eventName==click,,');

        $this->assertSame(['pagePath', 'eventName'], array_column($parsed, 'name'));
        $this->assertSame(['"b" is not a constraint: it needs a name, an operator and a value.'], $rsm->request_errors);

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();
        $rsm->parseConstraintsString('==direct');
        $this->assertCount(1, $rsm->request_errors, 'an operator with no name before it');
    }

    /** A value holding the operator keeps it: the clause splits at the first. */
    public function testAValueMayHoldItsOperator(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();

        $this->assertSame('a==b', $rsm->parseConstraintsString('pageTitle==a==b')[0]['value'] ?? null);
    }

    public function testAValidDimensionConstraintIsApplied(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('sessionMedium==direct'));

        $this->assertContains('sessionMedium', $this->appliedNames($rsm));
        $this->assertSame([], $this->errorsOf($rsm));
    }

    public function testAValidMetricConstraintIsApplied(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('pageViews>=5'));

        $this->assertContains('pageViews', $this->appliedNames($rsm));
        $this->assertSame([], $this->errorsOf($rsm));
    }

    /** A Yes/No dimension takes the words its cells show; anything else is refused, not compared with the flag. */
    public function testABooleanDimensionTakesYesOrNo(): void
    {
        foreach (['isOutbound==Yes', 'isOutbound!=no', 'isOutbound==1', 'isOutbound==(not set)'] as $clause) {
            $rsm = $this->rsm();
            $rsm->setConstraints($rsm->constraintsStringToArray($clause));

            $this->assertContains('isOutbound', $this->appliedNames($rsm), $clause);
            $this->assertFalse($rsm->hasRequestErrors(), $clause);
        }

        foreach (['isOutbound==Maybe' => 'is Yes or No; "Maybe" is neither', 'isOutbound=@Ye' => 'can only be filtered with is or is not'] as $clause => $why) {
            $rsm = $this->rsm();
            $rsm->setConstraints($rsm->constraintsStringToArray($clause));

            $this->assertTrue($rsm->hasRequestErrors(), $clause);
            $this->assertNotContains('isOutbound', $this->appliedNames($rsm), $clause);
            $this->assertStringContainsString($why, implode(' ', $this->errorsOf($rsm)), $clause);
        }
    }

    /**
     * "(not set)" is an empty test, which only a dimension has. Applied at
     * query time it was dropped, and the query ran without it.
     */
    public function testNotSetOnAMetricIsRefused(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('pageViews==(not set)'));

        $this->assertTrue($rsm->hasRequestErrors());
        $this->assertNotContains('pageViews', $this->appliedNames($rsm));
    }

    /** A calculated metric has no aggregate to test, so it is refused, not dropped. */
    public function testAConstraintOnACalculatedMetricIsRefused(): void
    {
        $rsm = $this->rsm();
        $rsm->setConstraints($rsm->constraintsStringToArray('pageViewsPerSession>2'));

        $this->assertTrue($rsm->hasRequestErrors());
        $this->assertSame(['"pageViewsPerSession" is calculated from other metrics, so it cannot be constrained on.'],
            $rsm->request_errors);
        $this->assertNotContains('pageViewsPerSession', $this->appliedNames($rsm));
    }

    /** A segment's query selects on dimensions only; a metric condition in it is refused. */
    public function testAMetricInASegmentIsRefused(): void
    {
        $rsm = $this->rsm();
        $rsm->setSegment('dynamic::sessionMedium==direct,pageViews>5');

        $this->assertTrue($rsm->hasRequestErrors());
        $this->assertSame(['sessionMedium'], $rsm->getDimensionNamesFromSegment());
    }

    /** A saved segment is not implemented: refused rather than run over everybody. */
    public function testASavedSegmentIsRefused(): void
    {
        $rsm = $this->rsm();
        $rsm->setSegment('id::7');

        $this->assertTrue($rsm->hasRequestErrors());
        $this->assertNull($rsm->getSegment());
    }

    public function testADimensionSegmentIsAccepted(): void
    {
        $rsm = $this->rsm();
        $rsm->setSegment('dynamic::sessionMedium==direct');

        $this->assertFalse($rsm->hasRequestErrors());
        $this->assertSame(['sessionMedium'], $rsm->getDimensionNamesFromSegment());
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
        $rsm->setConstraints($rsm->constraintsStringToArray('sessionMedium=='));

        $this->assertNotContains('sessionMedium', $this->appliedNames($rsm));

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
        $missingValue->setConstraints($missingValue->constraintsStringToArray('sessionMedium=='));

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
        $rsm->setConstraints($rsm->constraintsStringToArray('sessionMedium==direct,bogusDimension==x'));

        $this->assertContains('sessionMedium', $this->appliedNames($rsm));
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
     * Gating on "any error" would refuse to run those reports.
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
