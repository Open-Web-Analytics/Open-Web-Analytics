<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Dimensions;
use OWA\Module\Base\Entity\CustomDimension;

/**
 * The cube still has room for a full set of custom dimensions.
 *
 * MySQL caps a row's DEFINITION at 65,535 bytes -- the sum of its columns'
 * declared widths in bytes, not what they hold -- and refuses any ALTER that
 * would cross it. The cube already spends most of it: about 53,000 bytes on its
 * own columns before a single dimension is registered.
 *
 * WHAT COULD GO WRONG IS A CODE CHANGE, NOT A REGISTRATION. Twenty dimensions
 * at VARCHAR(64) need about 4,000 bytes of the 12,500 left, so no amount of
 * registering can exhaust it. What can is a release adding three more
 * VARCHAR(1024) columns to the cube, at which point custom dimensions quietly
 * stop fitting on every installation at once -- and the first anyone would know
 * is a registration failing in production. So it belongs in a test, and the
 * runtime carries no arithmetic for it at all.
 *
 * IT COUNTS THE ENTITY RATHER THAN ASKING A SERVER, and that is a correction.
 * The first version built a real cube and ran a real ALTER, which is more
 * faithful in principle and was wrong in practice: it failed twice in CI for
 * reasons that had nothing to do with the row -- an ALTER across seventy-odd
 * partitions brings in file handles, temp space and server version -- while the
 * row it was asking about had 12,464 bytes spare on the very same machine.
 * A question about declared widths is answerable from the declarations, needs
 * no database, and therefore runs in every CI environment rather than the one
 * that has MySQL.
 *
 * THE ARITHMETIC WAS VALIDATED AGAINST THE SERVER BEFORE BEING TRUSTED. On a
 * real 73-column cube it predicted the exact point at which MySQL refused, in
 * four separate cases: 16 more VARCHAR(255), 64 more VARCHAR(64), 114 more
 * VARCHAR(36), and the same numbers again with a TEXT column present. The one
 * thing it got wrong on the way is recorded in the off-page rule below, because
 * it is the trap: information_schema reports a TEXT column's width as its whole
 * capacity, and charging that prices one column past the entire row.
 */
final class CubeHasRoomForItsDimensionsTest extends TestCase
{
    /** MySQL's row definition limit. */
    private const MAX_ROW_BYTES = 65535;

    /**
     * Bytes per character, taken from the charset OWA DECLARES rather than
     * guessed.
     *
     * Every table is created `CHARACTER SET = utf8`, which MySQL 8 still maps
     * to utf8mb3 at three bytes. Reading the constant rather than writing 3
     * here is what makes testTheCubeWouldNotFitAtFourBytesPerCharacter below
     * mean something: change the constant and the two tests disagree, which is
     * the point at which somebody has to look.
     */
    private function bytesPerChar(): int
    {
        return strpos(OWA_DTD_CHARACTER_ENCODING_UTF8, 'mb4') !== false ? 4 : 3;
    }

    /**
     * What one declared column costs against the row.
     *
     * A variable-length column costs its declared width in BYTES plus one
     * length byte, or two once that exceeds 255. Anything stored off the page
     * -- TEXT, BLOB, JSON -- costs a pointer instead, whatever its declared
     * capacity says.
     */
    private function cost(string $type): int
    {
        $type = strtolower($type);

        if (preg_match('/(text|blob|json)/', $type)) {
            return 12;
        }

        if (preg_match('/(?:var)?char\s*\(\s*(\d+)\s*\)/', $type, $m)) {
            $bytes = (int) $m[1] * $this->bytesPerChar();

            return $bytes + ($bytes > 255 ? 2 : 1);
        }

        foreach ([
            'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'bigint' => 8,
            'int' => 4, 'float' => 4, 'double' => 8, 'decimal' => 8,
            'datetime' => 5, 'timestamp' => 4, 'date' => 3, 'time' => 3, 'year' => 1,
        ] as $name => $bytes) {
            if (strpos($type, $name) !== false) {
                return $bytes;
            }
        }

        // Unknown types are charged the widest fixed width rather than
        // nothing, so a type this does not know about cannot make the
        // estimate optimistic.
        return 8;
    }

    /** What the cube's own columns declare. */
    private function cubeRowBytes(): int
    {
        $entity = owa_coreAPI::entityFactory('base.event');
        $bytes  = 0;

        foreach ($entity->getColumns() as $name) {
            $bytes += $this->cost((string) $entity->getColumnDefinition($name));
        }

        return $bytes;
    }

    /** What a full set of dimensions declares, in its most expensive shape. */
    private function dimensionBytes(): int
    {
        // User-scoped: every one brings a set-time column as well.
        $each = $this->cost(Dimensions::definitionFor(
                    CustomDimension::TYPE_STRING, Dimensions::DIMENSION_LENGTH))
              + $this->cost(OWA_DTD_BIGINT);

        return Dimensions::MAX_PER_PROPERTY * $each;
    }

    /**
     * If this fails, take width OUT OF THE CUBE rather than lowering the cap.
     *
     * The ten VARCHAR(1024) columns are about 57% of the row between them, and
     * raw_ua in particular has no reader in the cube at all -- its stated
     * purpose is re-deriving a parser fix, and that reads owa_event_raw.
     */
    public function testACubeOfTodaysShapeHasRoomForAFullSetOfDimensions(): void
    {
        $cube       = $this->cubeRowBytes();
        $dimensions = $this->dimensionBytes();

        $this->assertLessThan(
            self::MAX_ROW_BYTES,
            $cube + $dimensions,
            sprintf(
                "A cube can no longer take its %d custom dimensions.\n"
              . "  the cube declares  %s bytes\n"
              . "  %d dimensions need %s bytes\n"
              . "  the row allows     %s bytes\n"
              . 'Something added columns to owa_event_raw or to the cube and pushed it '
              . 'over. Take width out of the cube rather than lowering the cap.',
                Dimensions::MAX_PER_PROPERTY,
                number_format($cube), Dimensions::MAX_PER_PROPERTY,
                number_format($dimensions), number_format(self::MAX_ROW_BYTES)));
    }

    /**
     * And it is not scraping in.
     *
     * A cube one column away from the limit passes the test above and fails for
     * the next person to add anything, so the margin is asserted rather than
     * left to be discovered. Not a round number: it is one more VARCHAR(1024),
     * which is the unit the cube actually grows in.
     */
    public function testThereIsMoreThanOneColumnOfMarginLeft(): void
    {
        $spare = self::MAX_ROW_BYTES - $this->cubeRowBytes() - $this->dimensionBytes();

        $this->assertGreaterThan(
            $this->cost('VARCHAR(1024)'),
            $spare,
            sprintf('only %s bytes spare, which is less than one more wide column',
                number_format($spare)));
    }

    /**
     * AND IT WOULD NOT FIT AT FOUR BYTES PER CHARACTER. Recorded, because it is
     * a real constraint on a decision somebody will reach for.
     *
     * `utf8` is deprecated in MySQL and is documented as an alias that will
     * eventually mean utf8mb4. On that day, or on the day somebody changes
     * OWA_DTD_CHARACTER_ENCODING_UTF8 to get emoji into page titles, the cube
     * stops being creatable AT ALL -- not "stops having room for dimensions",
     * but exceeds the row limit on its own, before a single one is registered.
     * Verified from the other direction too: CONVERT TO CHARACTER SET utf8mb4
     * on a real cube is refused.
     *
     * So the width has to come out of the cube BEFORE that move, not after.
     * This test is the note that says so, in the place someone will be standing
     * when they need it.
     */
    public function testTheCubeWouldNotFitAtFourBytesPerCharacter(): void
    {
        if ($this->bytesPerChar() === 4) {
            $this->fail(
                'The charset was changed to a four-byte one. The cube does not fit at '
              . 'four bytes per character -- take width out of it first; the ten '
              . 'VARCHAR(1024) columns are about 57% of the row between them.');
        }

        $wide = (int) round(($this->cubeRowBytes() + $this->dimensionBytes()) * 4 / 3);

        $this->assertGreaterThan(self::MAX_ROW_BYTES, $wide,
            'if this ever passes, the cube has become narrow enough to move to utf8mb4 '
          . '-- which is worth knowing, and worth doing');
    }

    /**
     * The arithmetic above, checked against the case that fooled it once.
     *
     * information_schema reports a TEXT column's width as its whole capacity --
     * 65,535, and over four billion for LONGTEXT -- so charging the declared
     * width prices a single one past the entire row. Measured: a table takes
     * 197 off-page columns whatever their declared size, where it takes 85
     * VARCHAR(255), so they are bounded by a different limit from this one.
     */
    public function testAnOffPageColumnIsPricedAsAPointer(): void
    {
        foreach (['TEXT', 'LONGTEXT NULL', 'BLOB', 'JSON NULL'] as $type) {
            $this->assertSame(12, $this->cost($type), $type);
        }

        // A VARCHAR still costs its declared width, which is the distinction
        // the pointer rule exists to draw: 255 characters at this table's
        // bytes per character, plus a two-byte length prefix.
        $this->assertSame(
            255 * $this->bytesPerChar() + 2,
            $this->cost('VARCHAR(255)'));
    }

    /** The cap is the only limit a registration can reach. */
    public function testTheRuntimeDoesNotPriceTheRow(): void
    {
        $body = file_get_contents(
            __DIR__ . '/../modules/Base/Classes/Cube/Dimensions.php');

        foreach (['MAX_ROW_BYTES', 'definitionRowBytes', 'tableRowBytes'] as $gone) {
            $this->assertStringNotContainsString($gone, $body,
                "$gone was removed: predicting the server's row accounting is this "
              . "test's job, not the runtime's");
        }

        $this->assertStringContainsString('1118', $body,
            'and a refused ALTER names the error an operator will actually see');
    }
}
