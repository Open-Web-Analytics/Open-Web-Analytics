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
 * WHY RESTORE 0 RATHER THAN EMBRACE NULL
 * The point is that old rows and new rows AGREE. Anything reading across
 * 20260821 -- every report, and the v2 migration's parity harness -- depends on
 * one representation, not two. v2 then declares these columns NOT NULL
 * DEFAULT 0 in the schema, which is where the guarantee belongs.
 */
final class EntityUnsetColumnWriteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function session()
    {
        return owa_coreAPI::entityFactory('base.session');
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
        $s = $this->session();

        // num_goals is TINYINT and nobody assigned it.
        $this->assertNull($s->get('num_goals'), 'precondition: the column is unset');
        $this->assertSame(0, $this->writeValue($s, 'num_goals'),
            'an unset numeric column would be written as NULL');
    }

    public function testAnUnsetBooleanColumnIsWrittenAsZero(): void
    {
        $s = $this->session();

        $this->assertSame(0, $this->writeValue($s, 'is_repeat_visitor'),
            'the column that started this: a two-state fact stored as three');
    }

    public function testAnUnsetTextColumnIsWrittenAsEmptyString(): void
    {
        $s = $this->session();

        // 'site' is VARCHAR255 and went NULL on the same date as the rest.
        $this->assertSame('', $this->writeValue($s, 'site'),
            "a string column's absent value was '' for eleven years, not NULL");
    }

    /**
     * Only NULL is resolved. A caller that deliberately stored 0, false or ''
     * must get back exactly what it stored -- otherwise this would undo
     * EntityFalsyWriteTest's guarantee from the other direction.
     */
    public function testAnAssignedValueIsWrittenUnchanged(): void
    {
        $s = $this->session();

        $s->set('num_goals', 4);
        $this->assertSame(4, $this->writeValue($s, 'num_goals'));

        $s->set('is_bounce', 0);
        $this->assertSame(0, $this->writeValue($s, 'is_bounce'));

        $s->set('site', 'example.test');
        $this->assertSame('example.test', $this->writeValue($s, 'site'));
    }

    /**
     * A type with no sensible zero keeps NULL.
     *
     * The old path turned these into '0000-00-00', which is not a value worth
     * restoring and is refused outright under STRICT_ALL_TABLES.
     */
    public function testAnUnrecognisedTypeIsLeftAlone(): void
    {
        $s = $this->session();

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
        $entity = \OWA\Core\CoreAPI::entityFactory('base.click');

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
     * No column on a tracked fact entity is written as NULL.
     *
     * This is the invariant, and it is why the fix lives at the WRITE layer
     * rather than in the tracking-property map. Most fact columns are not
     * declared as tracking properties at all: base.session has 121 columns and
     * only 35 of them appear in any of the three maps, base.request 58 and 37.
     * Fixing the map would have covered 29% of the surface and left the rest
     * writing NULL exactly as before.
     *
     * A column that genuinely wants NULL is a deliberate decision -- it needs a
     * three-state type and a reason, per the v2 plan's §1.12 -- so it should
     * fail here and be argued for, not appear by accident because someone
     * declared a type this layer does not recognise.
     */
    public function testNoFactColumnIsWrittenAsNull(): void
    {
        $entities = [
            'base.session',
            'base.request',
            'base.action_fact',
            'base.commerce_transaction_fact',
            'base.commerce_line_item_fact',
        ];

        $unresolved = [];
        $checked    = 0;

        foreach ($entities as $name) {

            $entity = owa_coreAPI::entityFactory($name);

            foreach ($entity->getColumns() as $column) {

                $checked++;

                if ($this->writeValue($entity, $column) === null) {
                    $unresolved[] = $name . '.' . $column;
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'the entity columns were not enumerated');
        $this->assertSame([], $unresolved,
            'these columns would still be written as NULL: ' . implode(', ', $unresolved));
    }

    /**
     * End to end: the only place the create() change is actually observable.
     */
    public function testUnsetColumnsSurviveACreateAsZeroNotNull(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('No database available.');
        }

        $db = owa_coreAPI::dbSingleton();
        $id = '9111222333444555778';
        $db->query("DELETE FROM owa_session WHERE id = $id");

        try {
            $s = $this->session();
            $s->set('id', $id);
            $s->set('site_id', 'entity-unset-test');
            $s->set('yyyymmdd', (int) date('Ymd'));
            $s->set('timestamp', time());
            // is_repeat_visitor, num_goals and commerce_trans_count are all
            // deliberately left alone -- that is the case under test.
            $s->create();

            $row = $db->get_row(
                "SELECT is_repeat_visitor, num_goals, commerce_trans_count, site
                   FROM owa_session WHERE id = ?", [$id]);

            $this->assertNotNull($row, 'the row was not created');

            foreach (['is_repeat_visitor', 'num_goals', 'commerce_trans_count'] as $col) {
                $this->assertNotNull($row[$col],
                    sprintf('%s was stored as NULL', $col));
                $this->assertSame('0', (string) $row[$col],
                    sprintf('%s should be 0, the value eleven years of rows carry', $col));
            }

            $this->assertSame('', (string) $row['site'],
                'an absent string column should be empty, not NULL');

        } finally {
            $db->query("DELETE FROM owa_session WHERE id = $id");
        }
    }
}
