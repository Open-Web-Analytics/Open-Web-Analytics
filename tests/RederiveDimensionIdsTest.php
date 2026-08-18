<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The migration's contract, in the parts that are cheap to assert and expensive
 * to get wrong.
 *
 * The command itself is exercised against a real database in the CLI suite; what
 * is pinned here is the table that decides what gets hashed. Every entry in it
 * has to match what ingestion does, and a wrong entry does not fail: it derives
 * an id ingestion will never derive again, and that dimension quietly stops
 * accumulating history from the moment the migration runs.
 */
final class RederiveDimensionIdsTest extends TestCase
{
    private function dimensions(): array
    {
        return \OWA\Module\Base\Controller\RederiveDimensionIdsCli::DIMENSIONS;
    }

    /**
     * The dimensions whose handler normalises before hashing but stores the raw
     * value. These are the ones a generic "hash the content column" loop gets
     * wrong, because setStringGuid() lowercases internally but does not trim.
     */
    public function testTheDimensionsThatTrimBeforeHashingAreMarkedAsSuch(): void
    {
        $dims = $this->dimensions();

        foreach (['base.campaign_dim', 'base.source_dim', 'base.ad_dim'] as $name) {
            $this->assertArrayHasKey($name, $dims);
            $this->assertTrue($dims[$name]['trim'],
                "$name hashes trim(strtolower(value)) at ingestion, so the migration must trim too");
        }
    }

    /** ...and the ones that hash their content verbatim must not trim it. */
    public function testTheDimensionsThatHashVerbatimDoNotTrim(): void
    {
        $dims = $this->dimensions();

        foreach (['base.document', 'base.ua', 'base.os', 'base.host', 'base.referer'] as $name) {
            $this->assertArrayHasKey($name, $dims);
            $this->assertFalse($dims[$name]['trim'],
                "$name hashes the value as given, so trimming here would derive an id ingestion never will");
        }
    }

    /** Every named content column must actually exist on its entity. */
    public function testEveryContentColumnExistsOnItsEntity(): void
    {
        foreach ($this->dimensions() as $name => $spec) {
            $entity = \OWA\Core\CoreAPI::entityFactory($name);

            $this->assertArrayHasKey($spec['column'], $entity->properties,
                "$name has no column '{$spec['column']}' to derive its id from");
        }
    }

    /**
     * Ids that are event guids or operator-chosen strings are NOT content
     * derived, and converting them would destroy the association outright.
     */
    public function testIdentifiersThatAreNotContentDerivedAreExcluded(): void
    {
        $dims = $this->dimensions();

        foreach (['base.visitor', 'base.session', 'base.site'] as $name) {
            $this->assertArrayNotHasKey($name, $dims,
                "$name's id is not derived from its content and must never be re-derived");
        }
    }

    /**
     * The property that makes the migration re-runnable: crc32 ids are below
     * 2^32 and derived ids are 63-bit, so applying the map twice is a no-op and
     * "still narrow" is a usable definition of "still to do".
     */
    public function testTheNarrowCeilingSeparatesOldIdsFromNew(): void
    {
        $ceiling = \OWA\Module\Base\Controller\RederiveDimensionIdsCli::NARROW_CEILING;

        $this->assertSame(4294967296, $ceiling, 'the crc32 space is 2^32');

        $above = 0;

        for ($i = 0; $i < 5000; $i++) {
            if (\OWA\Core\Lib::wideStringGuid('https://example.com/' . bin2hex(random_bytes(6))) >= $ceiling) {
                $above++;
            }
        }

        $this->assertGreaterThan(4990, $above,
            'derived ids must land above the crc32 range, or old and new cannot be told apart');
    }
}
