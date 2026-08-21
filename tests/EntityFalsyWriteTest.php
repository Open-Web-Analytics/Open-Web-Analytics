<?php

use PHPUnit\Framework\TestCase;

/**
 * An entity can store a legitimate falsy value on a numeric column.
 *
 * WHAT WAS WRONG
 * Entity::set() guarded on `if ( $value )`, so 0, false and '' were all
 * discarded without a word. Setting a numeric column to 0 did nothing, and the
 * caller could not tell. Entity::update() then repeated the mistake, including
 * a column only when its value was truthy, so even a 0 that had been stored
 * could not be persisted.
 *
 * The visible consequence was SessionHandlers writing the STRING 'false' into
 * is_bounce: a truthy value was the only kind that survived both guards, and
 * MySQL coerced the non-numeric string to 0 on the way in. That worked by
 * accident, broke under a strict sql_mode, and was TRUTHY when read back into
 * PHP -- so a session that had not bounced reported that it had.
 *
 * WHY BY TYPE, NOT BY REMOVING THE GUARD
 * The two falsy cases are not alike. On a numeric column, 0 is a value. On a
 * string column, '' is what a caller passes when it has nothing, and several
 * handlers depend on `set('medium', $maybeEmpty)` leaving the existing value
 * alone -- storing empties there would blank data that is deliberately kept
 * today. So numeric columns widened; everything else unchanged.
 *
 * The type is not new information: every column already declares one as
 * DbColumn's second constructor argument. Nothing consulted it until now.
 *
 * WHY IT MATTERS BEYOND is_bounce
 * v2's event table is full of columns where 0 is ordinary -- the sticky
 * `engaged` flag, `is_exit`, engagement-time deltas. Without this, writing any
 * of them through an entity would silently do nothing.
 */
final class EntityFalsyWriteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function session()
    {
        return owa_coreAPI::entityFactory('base.session');
    }

    public function testANumericColumnAcceptsZero(): void
    {
        $s = $this->session();
        $s->set('num_pageviews', 0);

        $this->assertSame(0, $s->get('num_pageviews'),
            'a numeric column silently discarded a legitimate 0');
    }

    public function testABooleanColumnAcceptsZeroAndFalse(): void
    {
        $s = $this->session();

        $s->set('is_bounce', 0);
        $this->assertSame(0, $s->get('is_bounce'));

        $s2 = $this->session();
        $s2->set('is_bounce', false);
        $this->assertFalse($s2->get('is_bounce'));
    }

    /** Storing it is only half the job; update() has to include it. */
    public function testAFalsyValueIsMarkedDirtySoUpdateWillWriteIt(): void
    {
        $s = $this->session();
        $s->set('is_bounce', 1);
        $s->dirty = [];              // as if freshly loaded from the database

        $s->set('is_bounce', 0);

        $this->assertArrayHasKey('is_bounce', $s->dirty,
            'setting a column to 0 left it un-dirty, so update() would skip it');
    }

    /**
     * The half that must NOT change: an empty string on a text column is still
     * ignored, because callers pass one when they have nothing and rely on the
     * existing value surviving.
     */
    public function testAStringColumnStillIgnoresAnEmptyValue(): void
    {
        $s = $this->session();
        $s->set('medium', 'organic');

        $s->set('medium', '');
        $this->assertSame('organic', $s->get('medium'),
            "an empty string blanked a text column -- handlers depend on it being ignored");

        $s->set('medium', null);
        $this->assertSame('organic', $s->get('medium'), 'null blanked a text column');
    }

    /** A numeric column must not accept a non-numeric falsy value either. */
    public function testANumericColumnStillIgnoresEmptyAndNull(): void
    {
        $s = $this->session();
        $s->set('num_pageviews', 7);

        $s->set('num_pageviews', '');
        $this->assertSame(7, $s->get('num_pageviews'));

        $s->set('num_pageviews', null);
        $this->assertSame(7, $s->get('num_pageviews'));
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

        $db = owa_coreAPI::dbSingleton();
        $id = '9111222333444555777';
        $db->query("DELETE FROM owa_session WHERE id = $id");

        try {
            $s = $this->session();
            $s->set('id', $id);
            $s->set('site_id', 'entity-falsy-test');
            $s->set('yyyymmdd', (int) date('Ymd'));
            $s->set('timestamp', time());
            $s->set('is_bounce', 1);
            $s->set('num_pageviews', 3);
            $s->create();

            $s2 = $this->session();
            $s2->getByPk('id', $id);
            $s2->set('is_bounce', 0);
            $s2->update();

            $row = $db->get_row("SELECT is_bounce, num_pageviews FROM owa_session WHERE id = ?", [$id]);

            $this->assertSame('0', (string) $row['is_bounce'],
                'the 0 never reached the database');
            $this->assertSame('3', (string) $row['num_pageviews'],
                'an untouched column was overwritten by the update');

        } finally {
            $db->query("DELETE FROM owa_session WHERE id = $id");
        }
    }
}
