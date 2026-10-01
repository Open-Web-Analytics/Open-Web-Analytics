<?php

use PHPUnit\Framework\TestCase;

/**
 * A column nobody assigned is written as its type's zero, not as NULL.
 *
 * WHAT WAS WRONG
 * Entity::create() sets EVERY column, so a column no caller touched still goes
 * into the INSERT -- explicitly, as null. That was harmless until the PDO
 * driver landed (#1028, 2026-08-21): the old driver interpolated values as
 * quoted literals, so a PHP null became '' and MySQL -- with sql_mode empty --
 * coerced '' to 0 on a numeric column. Eleven years of rows were written that
 * way. PDO binds the null instead, so identical application code began storing
 * a real NULL.
 *
 * On the demo install that flipped ~70 owa_session columns and ~14 owa_request
 * columns from 0 to NULL overnight: is_repeat_visitor, every goal_N, every
 * commerce_*, num_goals, the prior_session_* group. Measured, not inferred --
 * '0' rows stop dead on 20260821 and NULL rows start there, while
 * is_new_visitor (which the tracker always sends) is unchanged across the same
 * boundary.
 *
 * WHY IT MATTERS
 * Each distinct value is its own GROUP BY bucket, so a two-state fact starts
 * reporting as three and a counter that meant "none" stops being comparable to
 * the eleven years of 0s above it.
 *
 * WHAT v2 KEEPS OF IT
 * A column that declares itself nullable stores NULL for absence -- the form
 * v2 uses, and the one the reporting layer renders as "(not set)". A column
 * declared NOT NULL still takes its type's zero, since NULL there is refused
 * under STRICT_ALL_TABLES. Asserted on owa_event_raw, which has both.
 */
final class EntityUnsetColumnWriteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function row()
    {
        return \OWA\Core\CoreAPI::entityFactory('base.event_raw');
    }

    /** writeValue() is protected; it is the seam, so reach it directly. */
    private function writeValue($entity, string $column)
    {
        $m = new ReflectionMethod($entity, 'writeValue');
        $m->setAccessible(true);

        return $m->invoke($entity, $column);
    }

    public function testAnUnsetNumericColumnIsWrittenAsZero(): void
    {
        $s = $this->row();

        // yyyymmdd is a NOT NULL INT and nobody assigned it.
        $this->assertNull($s->get('yyyymmdd'), 'precondition: the column is unset');
        $this->assertSame(0, $this->writeValue($s, 'yyyymmdd'),
            'an unset NOT NULL numeric column would be written as NULL and refused');
    }

    public function testAnUnsetBooleanColumnIsWrittenAsZero(): void
    {
        $this->assertSame(0, $this->writeValue($this->row(), 'is_goal_event'),
            'a two-state fact must not be stored as a third');
    }

    public function testAnUnsetTextColumnIsWrittenAsEmptyString(): void
    {
        $this->assertSame('', $this->writeValue($this->row(), 'event_type'));
    }

    /** A nullable column stores its absence as NULL. */
    public function testAnUnsetNullableColumnIsWrittenAsNull(): void
    {
        $s = $this->row();

        $this->assertNull($this->writeValue($s, 'tagged_medium'), 'text');
        $this->assertNull($this->writeValue($s, 'engagement_msec'), 'numeric: 0 would be a real value');
    }

    /**
     * Only NULL is resolved. A caller that deliberately stored 0, false or ''
     * must get back exactly what it stored -- otherwise this would undo
     * EntityFalsyWriteTest's guarantee from the other direction.
     */
    public function testAnAssignedValueIsWrittenUnchanged(): void
    {
        $s = $this->row();

        $s->set('event_seq', 4);
        $this->assertSame(4, $this->writeValue($s, 'event_seq'));

        $s->set('is_goal_event', 0);
        $this->assertSame(0, $this->writeValue($s, 'is_goal_event'));

        $s->set('tagged_medium', 'email');
        $this->assertSame('email', $this->writeValue($s, 'tagged_medium'));
    }

    /**
     * A type with no sensible zero keeps NULL.
     *
     * The old path turned these into '0000-00-00', which is not a value worth
     * restoring and is refused outright under STRICT_ALL_TABLES.
     */
    public function testAnUnrecognisedTypeIsLeftAlone(): void
    {
        $s = $this->row();

        $unknown = new \OWA\Module\Base\Classes\DbColumn('probe_col', 'SOME_FUTURE_TYPE');
        $s->setProperty($unknown);

        $this->assertNull($this->writeValue($s, 'probe_col'),
            'a type with no defined zero must not be guessed at');
    }

    /**
     * The text-type list must read OWA's vocabulary, not MySQL's spelling --
     * the same requirement EntityFalsyWriteTest imposes on the numeric list.
     *
     * A regex over 'CHAR|TEXT|BLOB' is a MySQL DDL grammar sitting in the
     * entity layer. It would go wrong quietly on the first dialect that spells
     * its string types differently: nothing would match, absent values would go
     * back to being NULL, and the failure would look like missing data rather
     * than a type check that stopped recognising types.
     */
    public function testTheTextTypeListIsDerivedFromDeclaredTypesNotLiterals(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');

        $m = new ReflectionMethod($entity, 'textColumnTypes');
        $m->setAccessible(true);
        $types = $m->invoke($entity);

        $this->assertNotEmpty($types, 'no text column types resolved at all');

        $declared = [];
        foreach (get_defined_constants() as $name => $value) {
            if (strpos($name, 'OWA_DTD_') === 0) {
                $declared[] = (string) $value;
            }
        }

        foreach ($types as $type) {
            $this->assertContains($type, $declared,
                sprintf('"%s" is a hand-written spelling, not a declared OWA_DTD_* value', $type));
        }

        // The sprintf template is not a type any column carries.
        if (defined('OWA_DTD_VARCHAR')) {
            $this->assertNotContains((string) constant('OWA_DTD_VARCHAR'), $types,
                'OWA_DTD_VARCHAR is a template (VARCHAR(%s)), not a concrete column type');
        }
    }

    /**
     * No NOT NULL column is written as NULL, and no nullable one as a zero.
     *
     * The first is refused under STRICT_ALL_TABLES, so an insert that sent it
     * would fail outright. The second would store a real 0 or '' where nothing
     * was known.
     */
    public function testEveryColumnIsWrittenAsItsOwnAbsence(): void
    {
        $wrong   = [];
        $checked = 0;

        foreach (['base.event_raw', 'base.visitor_acquisition'] as $name) {

            $entity = \OWA\Core\CoreAPI::entityFactory($name);

            foreach ($entity->getColumns() as $column) {

                if (in_array($column, ['id'], true)) {
                    continue;
                }

                $checked++;

                $nullable = ! empty($entity->getProperty($column)->nullable);
                $value    = $this->writeValue($entity, $column);

                if ($nullable ? $value !== null : $value === null) {
                    $wrong[] = $name . '.' . $column . ($nullable ? ' (nullable, got a zero)' : ' (NOT NULL, got NULL)');
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the entity columns were not enumerated');
        $this->assertSame([], $wrong, implode(', ', $wrong));
    }

    /**
     * End to end: the only place the create() change is actually observable.
     */
    public function testUnsetColumnsSurviveACreateAsTheirAbsence(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('No database available.');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $id = '9111222333444555778';
        $db->query('DELETE FROM owa_event_raw WHERE id = ?', [$id]);

        try {
            $s = $this->row();
            $s->set('id', $id);
            $s->set('site_id', 'entity-unset-test');
            $s->set('event_type', 'page_view');
            $s->set('yyyymmdd', (int) date('Ymd'));
            $s->set('ts', time() * 1000000);
            // is_goal_event, is_outbound and tagged_medium are deliberately
            // left alone -- that is the case under test.
            $s->create();

            $row = $db->get_row(
                'SELECT is_goal_event, is_outbound, tagged_medium FROM owa_event_raw WHERE id = ?', [$id]);

            $this->assertNotNull($row, 'the row was not created');
            $this->assertSame('0', (string) $row['is_goal_event']);
            $this->assertSame('0', (string) $row['is_outbound']);
            $this->assertNull($row['tagged_medium'], 'a nullable column stores absence as NULL');

        } finally {
            $db->query('DELETE FROM owa_event_raw WHERE id = ?', [$id]);
        }
    }
}
