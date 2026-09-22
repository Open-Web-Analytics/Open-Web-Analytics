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
              . 'has no reader there -- rather than to lower the cap.',
                Dimensions::MAX_PER_PROPERTY));

        $this->assertCount(
            Dimensions::MAX_PER_PROPERTY * 2,
            Dimensions::registeredColumnsOn($this->table),
            'and every one of them is really on the table');
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
