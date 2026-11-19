<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Cube\Dimensions;

/**
 * The cube can be created, and has room for a useful number of dimensions.
 *
 * WHICH LIMIT BINDS DEPENDS ON THE SERVER, which is why none of this is
 * arithmetic. MySQL 8.4 refuses a wide table at 65,535 bytes -- the row
 * DEFINITION limit, the sum of declared widths -- and MySQL 8.0 refuses at
 * 8,126, InnoDB's limit on the part of a row that lives on the page. The second
 * is far tighter: on a cube of this shape 8.0 takes 19 custom dimensions, 8.4
 * takes 25. So the capacity is measured against whatever server is actually
 * there, and this asserts the outcome rather than a calculation.
 */
final class CubeHasRoomForItsDimensionsTest extends TestCase
{
    /**
     * Below this, something has gone wrong with the cube rather than with the
     * server. Well under either measured figure, because the number legitimately
     * differs between servers and a test that pinned one would fail on the other.
     */
    private const USEFUL_MINIMUM = 10;

    /** @var string */
    private $table = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this asks the server.');
        }

        $this->table = 'owa_cube_room_' . bin2hex(random_bytes(3));

        $entity = \OWA\Core\CoreAPI::entityFactory('base.event');
        $entity->setTableName(substr($this->table, 4));
        $entity->createTable();

        // Row size has nothing to do with how rows are divided up, and an
        // ALTER across seventy-odd partitions is a rebuild of each. Dropping
        // them takes this file from a minute and a half to a few seconds.
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('ALTER TABLE %s REMOVE PARTITIONING', $this->table));
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s', $this->table));
        }
    }

    /**
     * THE CUBE IS CREATABLE AT ALL, which depends on the row format.
     *
     * InnoDB caps the on-page part of a row at about 8,126 bytes, and the
     * formats differ in how much of a long column they can move off it: DYNAMIC
     * leaves a 20-byte pointer, COMPACT and REDUNDANT leave a 768-byte prefix
     * of each one inline. The cube has ten VARCHAR(1024) columns, so the
     * difference decides whether it exists.
     */
    public function testTheCubeIsCreatedWithTheRowFormatItNeeds(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertTrue($db->tableExists($this->table), 'the probe cube should be created');

        $row = $db->get_row(sprintf(
            "SELECT ROW_FORMAT AS f FROM information_schema.TABLES "
          . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'", $this->table));

        $this->assertSame('Dynamic', $row['f'],
            'the cube must declare ROW_FORMAT=DYNAMIC rather than inherit whatever '
          . 'innodb_default_row_format happens to be on this server');
    }

    /**
     * And the declaration is load-bearing, not decoration.
     *
     * If this ever stops failing, the cube has become narrow enough not to need
     * DYNAMIC -- which would be worth knowing, and would mean the row has a
     * great deal more slack than it does today.
     */
    public function testTheCubeWouldNotSurviveACompactRow(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertFalse(
            (bool) @$db->query(sprintf('ALTER TABLE %s ROW_FORMAT=COMPACT', $this->table)),
            'a COMPACT cube is refused, which is why DYNAMIC is declared rather than hoped for');

        $this->assertStringContainsString('1118', $db->lastQueryError(),
            'and refused for the row size, not something else');
    }

    /**
     * There is room for a useful number of dimensions.
     *
     * If this fails, a release has added columns to owa_event_raw or to the
     * cube and taken the room with them. Note before reaching for the widest
     * columns: on the limit that actually binds they are nearly free, because
     * a long VARCHAR is already stored off the page. On MySQL 8.0,
     * dropping raw_ua buys nothing at all and dropping eight wide columns
     * buys two dimensions. The on-page cost is in the MANY SHORT columns.
     */
    public function testThereIsRoomForAUsefulNumberOfDimensions(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $n  = 0;

        while ($n < Dimensions::MAX_PER_PROPERTY) {
            $ok = $db->alterColumnsRebuilding($this->table, [
                'cd_fit' . $n => Dimensions::definitionFor('string', Dimensions::DIMENSION_LENGTH),
                'cd_fit' . $n . '_set_ts' => OWA_DTD_BIGINT . ' NULL',
            ]);

            if (!$ok) {
                break;
            }

            $n++;
        }

        $this->assertGreaterThanOrEqual(self::USEFUL_MINIMUM, $n, sprintf(
            "This cube takes only %d custom dimensions on this server.\n"
          . 'The server said: %s', $n, $db->lastQueryError()));
    }

    /**
     * The outer cap is what a person is promised; the server may allow less,
     * and capacityFor() answers what it allows: on a cube padded until the
     * server refuses one more column, none, not the cap.
     */
    public function testTheCapIsAnOuterBoundAndTheBudgetIsMeasured(): void
    {
        $this->assertSame(20, Dimensions::MAX_PER_PROPERTY);

        $db       = \OWA\Core\CoreAPI::dbSingleton();
        $property = '9' . random_int(100000000, 999999999);
        $cube     = Cubes::tableFor($property);

        $this->assertTrue((bool) $db->query(sprintf('CREATE TABLE %s LIKE %s', $cube, $this->table)));

        try {
            Dimensions::forgetCapacity();
            $this->assertGreaterThanOrEqual(self::USEFUL_MINIMUM, Dimensions::capacityFor($property));

            // Padded by asking: filler until the server refuses a single column.
            $filler = 0;
            for ($batch = 32; $batch >= 1 && $filler < 400; ) {
                $add = [];
                for ($i = 0; $i < $batch; $i++) {
                    $add['filler' . ($filler + $i)] = 'VARCHAR(64) NULL';
                }

                if ($db->alterColumnsRebuilding($cube, $add)) {
                    $filler += $batch;
                } else {
                    $batch = intdiv($batch, 2);
                }
            }

            $this->assertLessThan(400, $filler, 'the server should have refused before we ran out of patience');

            Dimensions::forgetCapacity();
            $this->assertSame(0, Dimensions::capacityFor($property), 'a full cube has room for none');
        } finally {
            Dimensions::forgetCapacity();
            $db->query(sprintf('DROP TABLE IF EXISTS %s', $cube));
            $db->query(sprintf('DROP TABLE IF EXISTS %s', $cube . Dimensions::CAPACITY_SUFFIX));
        }
    }
}
