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
 * is far tighter. Measured on a cube of today's shape: 8.0 takes 19 custom
 * dimensions, 8.4 takes 25.
 *
 * Two models of the first limit were written and both were confidently wrong
 * about the second -- the more careful one predicted 8.4's refusal point
 * exactly, four times over, while 8.0 was refusing something it called fine. So
 * the capacity is measured against whatever server is actually there, and this
 * asserts the outcome rather than the calculation.
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

        $entity = owa_coreAPI::entityFactory('base.event');
        $entity->setTableName(substr($this->table, 4));
        $entity->createTable();

        // Row size has nothing to do with how rows are divided up, and an
        // ALTER across seventy-odd partitions is a rebuild of each. Dropping
        // them takes this file from a minute and a half to a few seconds.
        owa_coreAPI::dbSingleton()->removePartitioning($this->table);
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            owa_coreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s', $this->table));
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
        $db = owa_coreAPI::dbSingleton();

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
        $db = owa_coreAPI::dbSingleton();

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
     * a long VARCHAR is already stored off the page. Measured on MySQL 8.0,
     * dropping raw_ua bought nothing at all and dropping eight wide columns
     * bought two dimensions. The on-page cost is in the MANY SHORT columns.
     */
    public function testThereIsRoomForAUsefulNumberOfDimensions(): void
    {
        $db = owa_coreAPI::dbSingleton();
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

    /** The outer cap is what a person is promised; the server may allow less. */
    public function testTheCapIsAnOuterBoundAndTheBudgetIsMeasured(): void
    {
        $this->assertSame(20, Dimensions::MAX_PER_PROPERTY);

        $body = file_get_contents(
            __DIR__ . '/../modules/Base/Classes/Cube/Dimensions.php');

        $this->assertStringContainsString('capacityFor', $body,
            'the budget is asked of the server rather than calculated');

        foreach (['MAX_ROW_BYTES', 'definitionRowBytes', 'tableRowBytes'] as $gone) {
            $this->assertStringNotContainsString($gone, $body,
                "$gone was removed: it modelled the wrong limit");
        }
    }
}
