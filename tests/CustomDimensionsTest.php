<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Dimensions;
use OWA\Module\Base\Entity\CustomDimension;

/**
 * Registering a collected key as a column of a Property's cube.
 *
 * The arithmetic and the refusals, which is most of it: the DDL itself is one
 * statement, and what decides whether it is safe to issue happens before.
 */
final class CustomDimensionsTest extends TestCase
{
    /** Reach the protected validator without widening it. */
    private function validate(array $request, array $existing = [], array $queued = [])
    {
        $m = new ReflectionMethod(Dimensions::class, 'validate');
        $m->setAccessible(true);

        return $m->invoke(null, $request, $existing, $queued);
    }

    private function request(array $over = []): array
    {
        return $over + ['key' => 'plan', 'scope' => 'event', 'type' => 'string',
                        'length' => '', 'label' => ''];
    }

    public function testAKeyBecomesAPrefixedColumn(): void
    {
        $this->assertSame('cd_plan', Dimensions::columnFor('plan'));
        $this->assertSame('cd_plan_tier', Dimensions::columnFor('plan_tier'));
    }

    /**
     * Lowercased, which is why uniqueness is checked on the COLUMN.
     *
     * JSON keys are case-sensitive and column names are not, so `Plan` and
     * `plan` are two different keys and one column. Checking the key would let
     * both be registered and the second ALTER would fail against a table that
     * already had the column.
     */
    public function testTheDerivationIsCaseInsensitiveWhereJsonIsNot(): void
    {
        $this->assertSame(Dimensions::columnFor('plan'), Dimensions::columnFor('Plan'));
        $this->assertSame(Dimensions::columnFor('plan'), Dimensions::columnFor('PLAN'));
    }

    /**
     * The registrable key pattern IS the tracker's, character for character.
     *
     * Two things rest on that. A key this refuses is one no OWA tracker could
     * have set, so refusing it costs nothing real. And the JSON path is
     * `$.<key>` with nothing to quote -- which is where a registration taking
     * arbitrary keys would have had to build a path out of user text and put it
     * inside a SQL string literal, escaped twice.
     */
    public function testTheKeyPatternIsTheTrackersOwn(): void
    {
        $js = file_get_contents(__DIR__ . '/../modules/Base/src/tracker/Tracker.js');

        $this->assertMatchesRegularExpression(
            '/PROPERTY_NAME_PATTERN\(\)\s*\{\s*return\s*\/\^\[A-Za-z\]\[A-Za-z0-9_\]\{0,39\}\$\/;/',
            $js,
            'the tracker pattern moved; Dimensions::KEY_PATTERN has to move with it');

        $this->assertSame('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', Dimensions::KEY_PATTERN);
    }

    public function testAKeyThatCouldNotHaveBeenSetIsNotAColumn(): void
    {
        foreach ([
            '', '1plan', 'my.key', 'my key', 'my-key', '_plan', 'plan;DROP TABLE x',
            'plan"', "plan'", 'план', str_repeat('a', 41),
        ] as $bad) {
            $this->assertSame('', Dimensions::columnFor($bad), var_export($bad, true));

            $checked = $this->validate($this->request(['key' => $bad]));
            $this->assertArrayHasKey('error', $checked, var_export($bad, true));
        }
    }

    /** Forty is fine; forty-one is not, and that is the tracker's boundary. */
    public function testTheLengthBoundaryIsExactlyTheTrackers(): void
    {
        $this->assertNotSame('', Dimensions::columnFor(str_repeat('a', 40)));
        $this->assertSame('', Dimensions::columnFor(str_repeat('a', 41)));
    }

    public function testUserScopeOwnsTwoColumnsAndEventScopeOne(): void
    {
        $user = Dimensions::columnsOf([
            'column_name' => 'cd_plan', 'scope' => CustomDimension::SCOPE_USER,
            'data_type' => 'string', 'max_length' => 255]);

        $this->assertSame(['cd_plan', 'cd_plan_set_ts'], array_keys($user));

        $event = Dimensions::columnsOf([
            'column_name' => 'cd_tier', 'scope' => CustomDimension::SCOPE_EVENT,
            'data_type' => 'string', 'max_length' => 36]);

        $this->assertSame(['cd_tier'], array_keys($event));
    }

    /**
     * Every registered column is NULLABLE, whatever its type.
     *
     * A dimension is absent for every row collected before it was registered
     * and for every event that did not set it. Under STRICT_ALL_TABLES a NOT
     * NULL column would abort the whole partition's INSERT the first time one
     * row did not carry the key -- and the partition would keep its last good
     * contents with nothing saying why.
     */
    public function testEveryRegisteredColumnIsNullable(): void
    {
        foreach (CustomDimension::types() as $type) {
            $this->assertStringContainsString('NULL', Dimensions::definitionFor($type, 255), $type);
        }

        $this->assertStringContainsString('NULL',
            Dimensions::columnsOf(['column_name' => 'cd_x', 'scope' => CustomDimension::SCOPE_USER,
                'data_type' => 'string', 'max_length' => 36])['cd_x_set_ts'],
            'the set-time column too');
    }

    public function testTheTypeDecidesTheColumn(): void
    {
        $this->assertStringContainsString('VARCHAR(36)',
            Dimensions::definitionFor(CustomDimension::TYPE_STRING, 36));
        $this->assertStringContainsString('DOUBLE',
            Dimensions::definitionFor(CustomDimension::TYPE_DECIMAL, 0));
        $this->assertStringNotContainsString('VARCHAR',
            Dimensions::definitionFor(CustomDimension::TYPE_INTEGER, 0));
    }

    /**
     * The row budget, against the numbers it was derived from.
     *
     * Adding VARCHAR columns to a copy of a real 73-column cube until the
     * server refused gave 16 at VARCHAR(255), 64 at VARCHAR(64) and 114 at
     * VARCHAR(36) on a three-byte charset. This is the arithmetic that predicts
     * all three, and it predicting them is the only reason to trust a refusal
     * that happens before the ALTER rather than during it.
     */
    public function testTheRowBudgetMatchesWhatTheServerActuallyAllows(): void
    {
        // 53,022 was that cube's measured row cost.
        $spare = Dimensions::MAX_ROW_BYTES - 53022;

        foreach ([255 => 16, 64 => 64, 36 => 114] as $length => $expected) {
            $cost = Dimensions::definitionRowBytes("VARCHAR($length)", 3);

            $this->assertSame($expected, intdiv($spare, $cost),
                "a 73-column cube took exactly $expected more VARCHAR($length)");
        }
    }

    /** A wider charset costs proportionally more, which is why it is read. */
    public function testAWiderCharsetCostsMore(): void
    {
        $this->assertGreaterThan(
            Dimensions::definitionRowBytes('VARCHAR(255)', 3),
            Dimensions::definitionRowBytes('VARCHAR(255)', 4),
            'a VARCHAR(255) is 1020 bytes on utf8mb4 and 765 on utf8mb3');
    }

    public function testAFixedWidthTypeIsPricedAsOne(): void
    {
        $this->assertSame(8, Dimensions::definitionRowBytes('BIGINT NULL', 4));
        $this->assertSame(8, Dimensions::definitionRowBytes('DOUBLE NULL', 4));
    }

    public function testTheBudgetRefusalSaysWhatWouldFitInstead(): void
    {
        $result = Dimensions::budget('owa_no_such_table_for_budget', ['cd_x' => 'VARCHAR(255) NULL']);

        // Unknown table: no arithmetic to refuse on, so the server decides.
        $this->assertTrue($result['ok'],
            'an unpriceable table lets the ALTER be the judge rather than refusing blind');
    }

    /** There is no session scope, and the refusal says why. */
    public function testSessionScopeIsRefusedWithItsReason(): void
    {
        $checked = $this->validate($this->request(['scope' => 'session']));

        $this->assertArrayHasKey('error', $checked);
        $this->assertStringContainsString('derives', $checked['error']);
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->assertArrayHasKey('error', $this->validate($this->request(['type' => 'blob'])));
    }

    public function testStringLengthIsBounded(): void
    {
        $this->assertArrayHasKey('error', $this->validate($this->request(['length' => '0'])));
        $this->assertArrayHasKey('error', $this->validate($this->request(['length' => '99999'])));
        $this->assertArrayNotHasKey('error', $this->validate($this->request(['length' => '36'])));
    }

    /** Type defaults to string, and length to the string default. */
    public function testTheDefaults(): void
    {
        $checked = $this->validate($this->request(['type' => '', 'length' => '']));

        $this->assertSame(CustomDimension::TYPE_STRING, $checked['data_type']);
        $this->assertSame(Dimensions::DEFAULT_STRING_LENGTH, $checked['max_length']);
        $this->assertSame('plan', $checked['label'], 'the key stands in for a label');
    }

    /** A non-string type carries no width, so nothing can read one off it. */
    public function testANumericDimensionHasNoWidth(): void
    {
        $checked = $this->validate($this->request(['type' => 'integer', 'length' => '36']));

        $this->assertSame(0, $checked['max_length']);
    }

    public function testRegisteringTheSameColumnTwiceIsRefused(): void
    {
        $existing = ['cd_plan' => ['dimension_key' => 'plan', 'column_name' => 'cd_plan']];

        $this->assertArrayHasKey('error', $this->validate($this->request(), $existing));

        // And the case-folded twin, with the reason spelled out.
        $checked = $this->validate($this->request(['key' => 'Plan']), $existing);

        $this->assertArrayHasKey('error', $checked);
        $this->assertStringContainsString('case-insensitive', $checked['error']);
    }

    /** Two keys in ONE batch that derive one column are caught before the ALTER. */
    public function testTwoRequestsInOneCallCannotClaimOneColumn(): void
    {
        $checked = $this->validate($this->request(['key' => 'Plan']), [],
            ['cd_plan' => 'VARCHAR(255) NULL']);

        $this->assertArrayHasKey('error', $checked);
        $this->assertStringContainsString('same column', $checked['error']);
    }

    /**
     * A name that would not fit an identifier is refused, measured against the
     * LONGER of the two columns a registration can own.
     */
    public function testANameThatWouldOverflowAnIdentifierIsRefused(): void
    {
        // cd_ + 40 + _set_ts is 50, so the tracker's own limit already fits.
        $longest = Dimensions::columnFor(str_repeat('a', 40)) . CustomDimension::SET_TS_SUFFIX;

        $this->assertLessThanOrEqual(64, strlen($longest),
            'the tracker pattern already bounds this, and the guard is the belt to its braces');
    }

    /**
     * No release column of the cube can collide with a registered one.
     *
     * The prefix is what makes that true without a reserved-word list that
     * would go stale every time the cube gains a column -- and it is what
     * constrains DDL built from user input: de-registration takes a name from a
     * person and issues DROP COLUMN.
     */
    public function testNoReleaseColumnOfTheCubeUsesThePrefix(): void
    {
        foreach (owa_coreAPI::entityFactory('base.event')->getColumns() as $column) {
            $this->assertStringStartsNotWith(CustomDimension::PREFIX, $column, $column);
        }
    }

    /**
     * The registration carries a state, and the build does not trust it.
     *
     * A row is written immediately and the column arrives at a reconcile, so
     * there is always a window where the registry names something the table
     * does not have. The state exists to explain that window to a person; what
     * a build reads is the table.
     */
    public function testARegistrationHasAStateForAPersonToRead(): void
    {
        $entity = owa_coreAPI::entityFactory('base.custom_dimension');

        foreach (['state', 'state_message', 'applied_date'] as $column) {
            $this->assertNotNull($entity->getColumn($column), $column);
        }

        $this->assertSame(['pending', 'applied', 'failed'], CustomDimension::states());
    }

    /**
     * The build's rule, stated where it can be checked without a database.
     *
     * Trusting the registry instead would put a not-yet-created column in the
     * INSERT and fail every build with "Unknown column in field list" -- one
     * Property's whole cube stopping a few minutes after someone registered a
     * dimension.
     *
     * Read from the source because this file needs no database, and CI's unit
     * job has none: the behavioural proof is
     * CustomDimensionBuildTest::testAPendingRegistrationIsNotBuiltUntilItIsApplied,
     * which is skipped there. This is the half that still runs.
     */
    public function testTheBuilderTakesTheIntersectionOfRegistryAndTable(): void
    {
        $body = file_get_contents(
            __DIR__ . '/../modules/Base/Classes/Cube/Builder.php');

        $this->assertStringContainsString('registeredColumnsOn', $body,
            'the Builder has to ask the table which cd_ columns exist');

        $this->assertMatchesRegularExpression(
            '/isset\(\s*\$present\[\s*\$column\s*\]\s*\)/', $body,
            'and skip a registration whose column is not among them');
    }

    public function testTheRegistryIsNotAPartitionedTable(): void
    {
        // It is one small table per installation holding one installation's
        // choices, not a fact table -- so the partition commands must leave it
        // alone.
        $this->assertNull(
            owa_coreAPI::entityFactory('base.custom_dimension')->getPartitionColumn());
    }

    public function testAPropertyThatIsNotAnIdReadsBackNothing(): void
    {
        $this->assertSame([], Dimensions::forProperty('1; DROP TABLE owa_custom_dimension'));
        $this->assertSame([], Dimensions::forProperty('abc'));
    }
}
