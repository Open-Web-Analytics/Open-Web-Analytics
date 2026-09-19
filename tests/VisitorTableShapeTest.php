<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * owa_visitor carries only columns something writes or something reads.
 *
 * The table survives into v2, so its shape is worth asserting rather than
 * letting it drift. Before this it held five last_session_* columns that no
 * code in the repository mentioned outside the entity's own declarations, and
 * a first_session_dayofyear that was written at visitor creation and never
 * read back.
 */
final class VisitorTableShapeTest extends TestCase
{
    /** @return string[] */
    private function declaredColumns(): array
    {
        return (array) owa_coreAPI::entityFactory('base.visitor')->getColumns();
    }

    /** The dropped columns are gone from the entity, so no install recreates them. */
    public function testRetiredColumnsAreNoLongerDeclared(): void
    {
        $retired = [
            'last_session_id',
            'last_session_year',
            'last_session_month',
            'last_session_day',
            'last_session_dayofyear',
            'first_session_dayofyear',
        ];

        $declared = $this->declaredColumns();

        foreach ($retired as $column) {
            $this->assertNotContains($column, $declared,
                "$column was dropped by Update033 and must not be declared, or a fresh "
                . 'install would create it again while no upgraded install has it');
        }
    }

    /**
     * And the columns that earn their place are still there.
     *
     * Paired with the assertion above deliberately: a test that only checks for
     * absence passes just as well if the entity stopped declaring anything at
     * all.
     */
    public function testTheColumnsThatAreUsedSurvive(): void
    {
        $kept = [
            // read as dimensions
            'id', 'user_email',
            'first_session_source', 'first_session_medium', 'first_session_campaign',
            'first_session_ad', 'first_session_search_terms',
            // written, and named by historical updates that would halt without them
            'first_session_id', 'first_session_timestamp', 'first_session_yyyymmdd',
            'first_session_year', 'first_session_month', 'first_session_day',
            'num_prior_sessions', 'user_name',
        ];

        $declared = $this->declaredColumns();

        foreach ($kept as $column) {
            $this->assertContains($column, $declared, "base.visitor must still declare $column");
        }
    }

    /**
     * Update033 reverses itself, exactly, and in both directions repeatedly.
     *
     * An update that drops a column cannot reverse itself by reading the
     * definition back off the entity -- the entity describes the CURRENT
     * schema, and the definition it would need is precisely what this update
     * removed. Update033 therefore carries the six definitions itself, pinned
     * to the version it leaves behind.
     *
     * That matters because a rollback is a release being reverted: the code
     * goes back too, the older entity declares these columns again, and a
     * schema that could not follow would strand it against a table missing
     * columns it declares.
     *
     * Asserts the types as well as the names. A down() that restores a column
     * with the wrong type is worse than one that fails, because nothing
     * complains until something writes to it.
     */
    public function testUpdate033ReversesItselfExactly(): void
    {
        $db     = owa_coreAPI::dbSingleton();
        $update = new \OWA\Module\Base\Update\Update033;

        $expected = [
            'last_session_id'         => 'bigint',
            'last_session_year'       => 'int',
            'last_session_month'      => 'varchar(255)',
            'last_session_day'        => 'int',
            'last_session_dayofyear'  => 'int',
            'first_session_dayofyear' => 'int',
        ];

        $present = function () use ($db): array {
            $out = [];
            foreach ((array) $db->get_results('SHOW COLUMNS FROM owa_visitor') as $row) {
                $row = (array) $row;
                $out[$row['Field']] = strtolower((string) $row['Type']);
            }
            return $out;
        };

        $this->assertTrue($update->down(), 'down() must succeed');

        $restored = $present();

        foreach ($expected as $column => $type) {
            $this->assertArrayHasKey($column, $restored, "down() must restore $column");
            $this->assertSame($type, $restored[$column], "$column must come back as $type");
        }

        $this->assertTrue($update->down(), 'down() must be runnable twice');

        $this->assertTrue($update->up(), 'up() must succeed');

        $after = $present();

        foreach (array_keys($expected) as $column) {
            $this->assertArrayNotHasKey($column, $after, "up() must remove $column again");
        }

        $this->assertTrue($update->up(), 'up() must be runnable twice');
    }

    /**
     * The constraint that decides what CAN be dropped, pinned as a test.
     *
     * Entity::addColumn() builds its ALTER from the entity's declared property,
     * so an update calling addColumn() for a property that no longer exists
     * returns false -- and Update005 and friends return false in turn, halting
     * the chain for anyone upgrading from an old schema. Dropping a column is
     * therefore only safe once no update names it.
     *
     * This asserts the rule rather than the list: every column an update
     * mentions by name must still be declared. It fails the moment someone
     * removes a declaration that the upgrade path still depends on, which is
     * otherwise only visible in the schema-upgrade CI job.
     */
    public function testNoUpdateNamesAColumnTheEntityNoLongerDeclares(): void
    {
        $declared = $this->declaredColumns();
        $missing  = [];

        foreach ((array) glob(OWA_DIR . 'modules/Base/Update/Update*.php') as $file) {

            $src = (string) file_get_contents($file);

            /*
             * An update that RETIRES a column names it too, and must: that is
             * how it knows what to drop. The rule being asserted is about
             * addColumn() rebuilding a column from a declaration that has to
             * still exist -- a drop needs no declaration and cannot halt the
             * chain, since dropColumnIfPresent() treats "already gone" as
             * success.
             */
            if (str_contains($src, 'dropColumnIfPresent')) {
                continue;
            }

            // Only the visitor entity's columns are in scope; other entities
            // have their own declarations and their own updates.
            foreach (['last_session_', 'first_session_'] as $prefix) {

                if (! preg_match_all('/\b(' . $prefix . '[a-z_]+)\b/', $src, $m)) {
                    continue;
                }

                foreach (array_unique($m[1]) as $column) {
                    if (! in_array($column, $declared, true)) {
                        $missing[] = basename($file) . ' -> ' . $column;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            "An update names an owa_visitor column the entity no longer declares.\n"
            . "addColumn() builds its ALTER from the declaration, so that update returns\n"
            . 'false and halts the upgrade chain for anyone below its schema version.');
    }
}
