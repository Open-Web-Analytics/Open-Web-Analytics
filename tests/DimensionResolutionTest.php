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
 * This fires on ORDINARY requests, not just bad input. lookupDimension() returns
 * null when a name does not resolve against THE ENTITY BEING TESTED, and a
 * denormalized dimension such as productName lives only under its own entity
 * (base.commerce_line_item_fact). The callers loop every requested dimension
 * against every candidate entity looking for one that fits them all, so most
 * pairings are expected to miss -- and every miss logged a warning. That is how
 * it turned up in a live Apache log, on a perfectly normal e-commerce report.
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
        return owa_coreAPI::supportClassFactory('base', 'resultSetManager');
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
            fn() => $rsm->isDimensionRelated($name, 'base.request'),
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
            // Denormalized onto base.commerce_line_item_fact, so it does not
            // resolve against base.request. The exact name from the live log.
            'productName'       => ['productName'],
            'zz_not_a_thing'    => ['zz_not_a_thing'],
            'empty string'      => [''],
            'sql-ish'           => ["1' OR '1'='1"],
        ];
    }

    /**
     * The guard must not swallow the real answer. Without this, replacing the
     * body with `return false;` would pass every other test in this file.
     */
    public function testAResolvableDimensionIsStillRelated(): void
    {
        $rsm = $this->manager();
        $result = null;

        $diags = $this->diagnosticsFrom(
            fn() => $rsm->isDimensionRelated('pageTitle', 'base.request'),
            $result
        );

        $this->assertSame([], $diags);
        $this->assertTrue($result,
            'pageTitle resolves via the global registry and has a foreign key to base.request');
    }

    /**
     * A dimension that RESOLVES but has no foreign key to the entity. This is
     * the ordinary case in the callers, which loop dimensions against candidate
     * entities looking for one that fits them all -- most pairings do not.
     *
     * Covered explicitly because it is the only path that reaches the trailing
     * return. Without it, deleting that return re-introduced the implicit null
     * and every other test here still passed.
     */
    public function testAResolvableDimensionWithNoForeignKeyIsNotRelated(): void
    {
        $rsm = $this->manager();
        $result = null;

        // pageTitle resolves fine, but base.session has no foreign key to it.
        $diags = $this->diagnosticsFrom(
            fn() => $rsm->isDimensionRelated('pageTitle', 'base.session'),
            $result
        );

        $this->assertSame([], $diags);
        $this->assertFalse($result,
            'no foreign key means not related -- and must be false, not an implicit null');
    }

    /**
     * The method is a predicate. Returning null from the failure path made
     * `$check === false` quietly untrue, so any caller tightening its test from
     * `!$check` to `=== false` would have silently changed meaning.
     */
    public function testTheReturnIsAlwaysBoolean(): void
    {
        $rsm = $this->manager();

        $cases = [
            ['pageTitle',      'base.request'],   // true
            ['pageTitle',      'base.session'],   // false via the no-fk path
            ['productName',    'base.request'],   // false via the unresolvable path
            ['zz_not_a_thing', 'base.request'],
        ];

        foreach ($cases as [$name, $entity]) {
            $this->assertIsBool(
                @$rsm->isDimensionRelated($name, $entity),
                $name . ' / ' . $entity . ': is...() must answer with a boolean'
            );
        }
    }
}
