<?php

use PHPUnit\Framework\TestCase;

/**
 * isDimensionRelated() must answer, not warn.
 *
 * WHY THIS EXISTS
 * ---------------
 * lookupDimension() returns null when a name does not resolve against the entity
 * being tested, having already recorded why via addError(). isDimensionRelated()
 * indexed that null directly:
 *
 *     $dimension = $this->lookupDimension($dimension_name, $entity);
 *     if ($dimension['denormalized'] === true) {     // <- null['denormalized']
 *
 * so every miss produced "Trying to access array offset on value of type null"
 * and the method fell out of the bottom returning an implicit NULL from a
 * predicate named is...().
 *
 * This fired on ORDINARY requests, not just bad input: the callers loop every
 * requested dimension against every candidate entity looking for one that fits
 * them all, so most pairings are expected to miss -- and every miss logged a
 * warning. That is how it turned up in a live Apache log, on a perfectly normal
 * e-commerce report.
 *
 * Callers all test `if (!$check)`, so null and false were already equivalent to
 * them. The fix changes no behaviour; it removes the warning and makes the
 * return type match the method name. These tests pin both halves -- and the
 * positive case too, because "return false everywhere" would also silence the
 * warning while breaking every report.
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
    public function testAnUnresolvableDimensionIsNotRelatedAndDoesNotWarn(string $name): void
    {
        $rsm = $this->manager();
        $result = null;

        $diags = $this->diagnosticsFrom(
            fn() => $rsm->isDimensionRelated($name, 'base.event'),
            $result
        );

        $this->assertSame([], $diags,
            'a dimension that does not resolve against this entity raised a PHP diagnostic');
        $this->assertFalse($result,
            'an unresolvable dimension is "not related" -- and must say so with false, not null');
    }

    public static function unresolvableDimensions(): array
    {
        return [
            'zz_not_a_thing'    => ['zz_not_a_thing'],
            'empty string'      => [''],
            'sql-ish'           => ["1' OR '1'='1"],
        ];
    }

    /** A dimension the cube carries is related to it. */
    public function testAResolvableDimensionIsStillRelated(): void
    {
        $rsm = $this->manager();
        $result = null;

        $diags = $this->diagnosticsFrom(fn() => $rsm->isDimensionRelated('pagePath', 'base.event'), $result);

        $this->assertSame([], $diags);
        $this->assertTrue($result, '"return false everywhere" would also silence the warning');
    }

    /**
     * Registered, but not a column of the entity asked about. v1 would have
     * joined it in through a foreign key; v2 has no joins, so the answer is no.
     */
    public function testARegisteredDimensionOnAnotherEntityIsNotRelated(): void
    {
        $rsm = $this->manager();
        $result = null;

        $diags = $this->diagnosticsFrom(fn() => $rsm->isDimensionRelated('pagePath', 'base.event_raw'), $result);

        $this->assertSame([], $diags);
        $this->assertFalse($result);
    }

    public function testTheReturnIsAlwaysBoolean(): void
    {
        $rsm = $this->manager();

        foreach ([
            ['pagePath',       'base.event'],      // true
            ['pagePath',       'base.event_raw'],  // false: registered, not on this entity
            ['zz_not_a_thing', 'base.event'],      // false: not registered
        ] as [$name, $entity]) {

            $this->assertIsBool(
                @$rsm->isDimensionRelated($name, $entity),
                "isDimensionRelated('$name', '$entity') did not return a boolean"
            );
        }
    }
}
