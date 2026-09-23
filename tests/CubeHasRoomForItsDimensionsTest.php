<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Cube\Dimensions;

/**
 * The cube still has room for a full set of custom dimensions.
 *
 * MySQL caps a row's DEFINITION at 65,535 bytes -- the sum of its columns'
 * declared widths in bytes, not what they hold -- and refuses any ALTER that
 * would cross it with error 1118. That is a real limit, and the cube already
 * spends most of it: about 53,000 bytes on its own columns before a single
 * dimension is registered.
 *
 * WHAT COULD GO WRONG IS A CODE CHANGE, NOT A REGISTRATION. Twenty dimensions
 * at VARCHAR(64) need about 4,000 bytes of the 12,500 left, so no amount of
 * registering can exhaust it. What can is a release adding three more
 * VARCHAR(1024) columns to the cube, at which point custom dimensions quietly
 * stop fitting on every installation at once -- and the first anyone would know
 * is a registration failing in production.
 *
 * So this belongs in a test and not in the product. The runtime once carried
 * arithmetic that predicted the server's row accounting before every
 * registration; it was deleted, because it was a second copy of MySQL's rules
 * to keep true (it was wrong twice while being written, once charging a TEXT
 * column its full 65,535-byte declared width instead of the twelve it actually
 * costs), it guarded a case registration cannot reach, and the thing it would
 * have caught is this one -- which a test catches earlier and for good.
 *
 * IT ASKS THE SERVER RATHER THAN MODELLING IT. There is no arithmetic here at
 * all: it builds the real cube, adds a full set of real dimension columns, and
 * reports what MySQL said.
 */
final class CubeHasRoomForItsDimensionsTest extends TestCase
{
    /** @var string */
    private $table = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this asks the server.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            owa_coreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s', $this->table));
        }
    }

    /**
     * A cube of the CURRENT shape takes a full set of dimensions.
     *
     * The expensive shape deliberately: user-scoped, so every dimension brings
     * a set-time column as well. If this fails, the cube has grown past what it
     * can carry and something has to come out of it -- not out of the cap.
     */
    public function testACubeOfTodaysShapeTakesAFullSetOfDimensions(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $this->table = 'owa_cube_room_probe_' . bin2hex(random_bytes(3));

        $entity = owa_coreAPI::entityFactory('base.event');
        $entity->setTableName(substr($this->table, 4));

        $this->assertTrue((bool) $entity->createTable(), 'the probe cube should be created');

        // createTable() is CREATE TABLE IF NOT EXISTS and answers true for
        // "already there" as well as for "made it", so it cannot report a
        // refusal. Asked separately, because a cube that was never created
        // makes every assertion below fail for the wrong reason.
        $this->assertTrue($db->tableExists($this->table),
            'the cube was not created at all: ' . $db->lastQueryError());

        /*
         * PARTITIONS ARE NOT PART OF THIS QUESTION, so the probe does without
         * them.
         *
         * How much of a row's 65,535 bytes the columns declare has nothing to
         * do with how the rows are divided up -- and an ALTER across the
         * cube's seventy-odd partitions is a rebuild of each, which brings in
         * file handles, temp space and server version, none of which this
         * test is about. It cost a failure that read as "the row is full" on a
         * server whose row had 12,464 bytes spare.
         */
        $db->removePartitioning($this->table);

        $columns = [];

        for ($i = 0; $i < Dimensions::MAX_PER_PROPERTY; $i++) {
            $columns['cd_probe' . $i] =
                Dimensions::definitionFor('string', Dimensions::DIMENSION_LENGTH);

            $columns['cd_probe' . $i . '_set_ts'] = OWA_DTD_BIGINT . ' NULL';
        }

        $this->assertTrue(
            $db->alterColumnsRebuilding($this->table, $columns),
            sprintf(
                'A cube can no longer take its %d custom dimensions: MySQL refused the '
              . 'ALTER, which at this size means error 1118, the 65,535-byte row limit. '
              . 'Something added columns to owa_event_raw or to the cube and pushed it '
              . 'over. The fix is to take width out of the cube -- the ten VARCHAR(1024) '
              . 'columns are about 57%% of the row between them, and raw_ua in particular '
              . 'has no reader there -- rather than to lower the cap.%s',
                Dimensions::MAX_PER_PROPERTY, $this->describeRow())
          . "\n\nThe server said: " . $db->lastQueryError());

        $this->assertCount(
            Dimensions::MAX_PER_PROPERTY * 2,
            Dimensions::registeredColumnsOn($this->table),
            'and every one of them is really on the table');
    }

    /**
     * What the cube actually costs here, for a failure message that can be
     * acted on rather than reproduced.
     *
     * A row limit is the same number everywhere, but what a table spends
     * against it is not: the charset decides how many bytes a declared
     * character takes, and a server whose default differs turns a comfortable
     * margin into a refusal.
     *
     * @return string
     */
    private function describeRow(): string
    {
        $db = owa_coreAPI::dbSingleton();

        $collation = $db->get_row(sprintf(
            "SELECT TABLE_COLLATION AS c FROM information_schema.TABLES "
          . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'", $this->table));

        $bytes = 0;
        $count = 0;

        foreach ((array) $db->get_results(sprintf(
                "SELECT COALESCE(CHARACTER_OCTET_LENGTH, 0) AS o "
              . "FROM information_schema.COLUMNS "
              . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'",
                $this->table)) as $column) {

            $octets = (int) $column['o'];
            $count++;
            $bytes += $octets > 0 ? $octets + ($octets > 255 ? 2 : 1) : 8;
        }

        return sprintf(
            "\n\nThis cube: %d columns, about %s of the 65,535-byte row (%s spare), "
          . 'collation %s.',
            $count, number_format($bytes), number_format(65535 - $bytes),
            is_array($collation) ? $collation['c'] : '(unreadable)');
    }

    /**
     * The cap is what stops a registration, and it is well inside what fits.
     *
     * Stated as a relationship rather than a number so that raising the cap is
     * a decision someone makes against the test above rather than a number that
     * drifts past it.
     */
    public function testTheCapIsTheOnlyLimitARegistrationCanReach(): void
    {
        $this->assertGreaterThan(0, Dimensions::MAX_PER_PROPERTY);

        $body = file_get_contents(
            __DIR__ . '/../modules/Base/Classes/Cube/Dimensions.php');

        $this->assertStringNotContainsString('MAX_ROW_BYTES', $body,
            'the runtime does not price the row; this test does the asking instead');
    }
}
