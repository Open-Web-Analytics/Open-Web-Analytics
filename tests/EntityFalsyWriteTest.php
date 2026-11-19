<?php

use PHPUnit\Framework\TestCase;

/**
 * An entity can store a legitimate falsy value on a numeric column.
 *
 * set() and update() keep 0 and false on a numeric column. A truthiness guard
 * would discard them without a word, and the caller could not tell.
 *
 * WHY BY TYPE, NOT BY REMOVING THE GUARD
 * The two falsy cases are not alike. On a numeric column, 0 is a value. On a
 * string column, '' is what a caller passes when it has nothing, and several
 * handlers depend on `set('tagged_medium', $maybeEmpty)` leaving the existing value
 * alone -- storing empties there would blank data that is deliberately kept.
 * So numeric columns store falsy values; everything else ignores them. The type
 * is the one every column declares as DbColumn's second constructor argument.
 *
 * v2's event table is full of columns where 0 is ordinary -- the sticky
 * `engaged` flag, `is_exit`, engagement-time deltas.
 */
final class EntityFalsyWriteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function row()
    {
        return \OWA\Core\CoreAPI::entityFactory('base.event_raw');
    }

    public function testANumericColumnAcceptsZero(): void
    {
        $s = $this->row();
        $s->set('event_seq', 0);

        $this->assertSame(0, $s->get('event_seq'),
            'a numeric column silently discarded a legitimate 0');
    }

    public function testABooleanColumnAcceptsZeroAndFalse(): void
    {
        $s = $this->row();

        $s->set('is_goal_event', 0);
        $this->assertSame(0, $s->get('is_goal_event'));

        $s2 = $this->row();
        $s2->set('is_goal_event', false);
        $this->assertFalse($s2->get('is_goal_event'));
    }

    /** Storing it is only half the job; update() has to include it. */
    public function testAFalsyValueIsMarkedDirtySoUpdateWillWriteIt(): void
    {
        $s = $this->row();
        $s->set('is_goal_event', 1);
        $s->dirty = [];              // as if freshly loaded from the database

        $s->set('is_goal_event', 0);

        $this->assertArrayHasKey('is_goal_event', $s->dirty,
            'setting a column to 0 left it un-dirty, so update() would skip it');
    }

    /**
     * 0 over NULL is a change, though PHP's == calls the two equal: a column
     * never set, then set to 0, has to reach update().
     */
    public function testZeroOverNullIsMarkedDirty(): void
    {
        $s = $this->row();
        $this->assertNull($s->get('event_seq'));

        $s->set('event_seq', 0);

        $this->assertArrayHasKey('event_seq', $s->dirty,
            '0 written over NULL was not marked dirty, so update() would not write it');
        $this->assertSame(0, $s->dirty['event_seq']);
    }

    /** The same number in another type is not a change. */
    public function testTheSameNumberAsAStringIsNotMarkedDirty(): void
    {
        $s = $this->row();
        $s->set('event_seq', 1);
        $s->dirty = [];

        $s->set('event_seq', '1');

        $this->assertArrayNotHasKey('event_seq', $s->dirty);
    }

    /**
     * The half that must NOT change: an empty string on a text column is still
     * ignored, because callers pass one when they have nothing and rely on the
     * existing value surviving.
     */
    public function testAStringColumnStillIgnoresAnEmptyValue(): void
    {
        $s = $this->row();
        $s->set('tagged_medium', 'organic');

        $s->set('tagged_medium', '');
        $this->assertSame('organic', $s->get('tagged_medium'),
            "an empty string blanked a text column -- handlers depend on it being ignored");

        $s->set('tagged_medium', null);
        $this->assertSame('organic', $s->get('tagged_medium'), 'null blanked a text column');
    }

    /** The string '0' is a value on a text column, not nothing. */
    public function testAStringColumnStoresTheStringZero(): void
    {
        $s = $this->row();
        $s->set('tagged_medium', 'organic');

        $s->set('tagged_medium', '0');
        $this->assertSame('0', $s->get('tagged_medium'), "'0' was dropped as if it were empty");
    }

    /**
     * clear() is how a caller says "this is now blank".
     *
     * set() ignoring '' is deliberate and load-bearing (the test above), so an edit
     * form expresses an emptied field -- a cleared description -- through clear().
     */
    public function testClearEmptiesAStringColumnThatSetWillNotTouch(): void
    {
        $s = $this->row();
        $s->set('tagged_medium', 'organic');

        // Precondition: set() cannot do this, which is why clear() exists.
        $s->set('tagged_medium', '');
        $this->assertSame('organic', $s->get('tagged_medium'));

        $s->clear('tagged_medium');

        $this->assertNull($s->get('tagged_medium'),
            'clear() did not empty the column');
    }

    /**
     * ...and marks it dirty, which is the half set() cannot reach: its falsy
     * guard wraps the markDirty call as well as the assignment, so a value it
     * skips is never written by update() either.
     */
    public function testAClearedColumnIsMarkedDirtySoUpdateWritesIt(): void
    {
        $s = $this->row();
        $s->set('tagged_medium', 'organic');
        $s->clear('tagged_medium');

        $this->assertArrayHasKey('tagged_medium', $s->dirty,
            'a cleared column is not dirty, so update() would not write the blank');
        $this->assertNull($s->dirty['tagged_medium']);
    }

    /**
     * clear() writes what create() writes for a column nobody set: NULL on a
     * nullable column, '' on a text column that is not, 0 on a numeric one.
     * '' on a numeric column is refused under strict mode.
     */
    public function testClearWritesTheColumnsOwnEmptyValue(): void
    {
        $raw = $this->row();
        $raw->set('tagged_medium', 'organic');
        $raw->set('ts', 1700000000000000);
        $raw->clear('tagged_medium');
        $raw->clear('ts');

        $this->assertNull($raw->dirty['tagged_medium'], 'a nullable column is cleared to NULL');
        $this->assertSame(0, $raw->dirty['ts'], 'a numeric column that cannot hold NULL is cleared to 0');
        $this->assertSame(0, $raw->get('ts'));

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->set('description', 'A site');
        $site->clear('description');

        $this->assertSame('', $site->dirty['description'], 'a text column that cannot hold NULL is cleared to an empty string');
        $this->assertSame('', $site->get('description'));
    }

    /** clear() on a column the entity does not have is a no-op, not a fatal. */
    public function testClearIgnoresAnUnknownColumn(): void
    {
        $s = $this->row();
        $s->clear('no_such_column_here');

        $this->assertArrayNotHasKey('no_such_column_here', $s->dirty);
    }

    /**
     * A numeric column must not accept a non-numeric falsy value either.
     */
    public function testANumericColumnStillIgnoresEmptyAndNull(): void
    {
        $s = $this->row();
        $s->set('event_seq', 7);

        $s->set('event_seq', '');
        $this->assertSame(7, $s->get('event_seq'));

        $s->set('event_seq', null);
        $this->assertSame(7, $s->get('event_seq'));
    }

    /**
     * End to end through the database, which is the only place the update()
     * change can be observed. Also asserts an untouched column survives, since
     * the risk of consulting the dirty list is writing too much, not too little.
     */
    public function testAZeroSurvivesACreateUpdateRoundTrip(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('No database available.');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $id = '9111222333444555777';
        $db->query("DELETE FROM owa_event_raw WHERE id = $id");

        try {
            $s = $this->row();
            $s->set('id', $id);
            $s->set('site_id', 'entity-falsy-test');
            $s->set('yyyymmdd', (int) date('Ymd'));
            $s->set('event_type', 'page_view');
            $s->set('ts', time() * 1000000);
            $s->set('is_goal_event', 1);
            $s->set('event_seq', 3);
            $s->create();

            $s2 = $this->row();
            $s2->getByPk('id', $id);
            $s2->set('is_goal_event', 0);
            $s2->update();

            $row = $db->get_row("SELECT is_goal_event, event_seq FROM owa_event_raw WHERE id = ?", [$id]);

            $this->assertSame('0', (string) $row['is_goal_event'],
                'the 0 never reached the database');
            $this->assertSame('3', (string) $row['event_seq'],
                'an untouched column was overwritten by the update');

        } finally {
            $db->query("DELETE FROM owa_event_raw WHERE id = $id");
        }
    }

    /**
     * The numeric-type check must read OWA's vocabulary, not MySQL's spelling.
     *
     * This started as a regex over type strings -- BIGINT|INT|TINYINT|... -- and
     * that is a MySQL DDL grammar sitting in the entity layer. It would have
     * gone wrong quietly on the first dialect that spells its integers
     * differently: nothing would match, falsy values would silently go back to
     * being discarded, and the failure would look like data that never arrived
     * rather than a type check that stopped recognising types.
     *
     * The constants are the portable part. OWA_DTD_BIGINT means "a big integer"
     * in every dialect; its VALUE is only what the loaded one calls it. So every
     * entry in the numeric list must be the value of some declared OWA_DTD_*
     * constant -- a literal spelling that no dialect declares fails here, which
     * is exactly what a reintroduced regex or a hand-written list would produce.
     */
    public function testTheNumericTypeListIsDerivedFromDeclaredTypesNotLiterals(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');

        $method = new ReflectionMethod($entity, 'numericColumnTypes');
        $types = $method->invoke($entity);

        $this->assertNotEmpty($types, 'no numeric column types were resolved at all');

        $declared = [];

        foreach (get_defined_constants() as $name => $value) {

            if (strpos($name, 'OWA_DTD_') === 0) {
                $declared[$value] = $name;
            }
        }

        foreach ($types as $type) {

            $this->assertArrayHasKey(
                $type,
                $declared,
                sprintf(
                    '"%s" is not the value of any OWA_DTD_* constant, so it is a hard-coded SQL '
                  . 'spelling. It will stop matching on a dialect that spells the type another '
                  . 'way, and falsy values on those columns will be dropped silently.',
                    $type
                )
            );
        }
    }

    /**
     * Stated through the constants rather than their values, so it keeps
     * meaning the same thing under a dialect that spells them differently.
     */
    public function testDeclaredIntegerTypesAreRecognisedThroughTheirConstants(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');

        $method = new ReflectionMethod($entity, 'numericColumnTypes');
        $types = $method->invoke($entity);

        foreach (['OWA_DTD_BIGINT', 'OWA_DTD_INT', 'OWA_DTD_BOOLEAN'] as $constant) {

            $this->assertContains(
                (string) constant($constant),
                $types,
                sprintf('%s must count as a numeric column type', $constant)
            );
        }

        $this->assertNotContains(
            (string) OWA_DTD_VARCHAR255,
            $types,
            'a string column must not accept falsy writes -- an empty string means "no value"'
        );
    }
}
