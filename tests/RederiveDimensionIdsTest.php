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
     * owa_click.target_id must be migrated, and must NOT be a declared key.
     *
     * It holds setStringGuid( target_url ), which is a document id when the click
     * went to a page of this site and meaningless when it went anywhere else --
     * 59,083 of 266,498 resolved on one installation. A foreign key asserts a
     * referential guarantee and there is none, so the entity does not declare it
     * and key enumeration cannot see it. The migration therefore has to name it,
     * or the values that DO resolve are left pointing at ids that have moved.
     */
    public function testTargetIdIsMigratedWithoutBeingDeclaredAKey(): void
    {
        $click = \OWA\Core\CoreAPI::entityFactory('base.click');

        $this->assertFalse($click->properties['target_id']->isForeignKey(),
            'a click target can be any URL on the web, so this is not a foreign key');

        $undeclared = \OWA\Module\Base\Controller\RederiveDimensionIdsCli::UNDECLARED_KEYS;

        $this->assertSame('base.document', $undeclared['click']['target_id'] ?? null,
            'the migration must name target_id explicitly, since nothing can infer it');
    }

    /** Declaring it would have repointed every document join on clicks. */
    public function testReportJoinsToDocumentsStillGoThroughDocumentId(): void
    {
        $click = \OWA\Core\CoreAPI::entityFactory('base.click');

        $properties = new ReflectionProperty($click, '_tableProperties');
        $properties->setAccessible(true);
        $table = $properties->getValue($click);

        $this->assertSame('document_id', $table['relatedEntities']['base.document'] ?? null);
    }

    /**
     * Keys that cannot be converted must not stop the command finishing.
     *
     * A fact key pointing at a dimension row that does not exist has no content
     * to derive an id from, so nothing can convert it -- 14,097 of them on one
     * installation. They are left in place deliberately: nulling them would
     * change what the data asserts, from "there was a prior page we cannot
     * identify" to "there was no prior page".
     *
     * That only works because the completion test counts narrow keys JOINED to
     * the map. A dangling key fails that join, contributes nothing, and so can
     * never hold the flag set forever. Counting all narrow keys instead would
     * pin such an installation to 32-bit ids permanently.
     */
    public function testTheCompletionTestIgnoresKeysItCannotConvert(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/RederiveDimensionIdsCli.php'
        );

        $start = strpos($source, 'protected function countNarrowKeys()');
        $end   = strpos($source, 'protected function countDanglingKeys()');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString('JOIN', $body,
            'the completion test must join the map, so unconvertible keys are excluded');
        $this->assertStringNotContainsString('LEFT JOIN', $body,
            'a LEFT JOIN would count dangling keys and the flag could never clear');
    }

    /**
     * The map is scratch, not schema.
     *
     * A new installation derives 63-bit ids from its first event and never runs
     * this command, so registering the table as an entity only created an empty
     * one on every install that would never be used. The command builds it with
     * DDL when it needs it and drops it when it does not.
     */
    public function testTheMapIsNotPartOfAnyInstallationSchema(): void
    {
        $entities = \OWA\Core\CoreAPI::serviceSingleton()->modules['base']->getEntities();

        $this->assertNotContains('guid_map', $entities);

        $this->assertFileDoesNotExist(dirname(__DIR__) . '/modules/Base/Entity/GuidMap.php',
            'the map is scratch built by the command, not an entity');
    }

    /**
     * Two gates, answering different questions.
     *
     * The flag decides whether this installation should be converting at all,
     * and it comes first so an ordinary rerun stops without touching a fact
     * table -- that is what makes running the command repeatedly harmless.
     *
     * --force skips only that gate. What is actually LEFT to do is always read
     * from the rows, so a forced run and a resumed run both get a truthful
     * answer, and an installation whose flag was cleared while keys were still
     * unconverted can still be finished.
     */
    public function testTheFlagGatesTheRunAndTheDataDecidesTheWork(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/RederiveDimensionIdsCli.php'
        );

        $action = substr($source, strpos($source, 'function action()'));
        $action = substr($action, 0, strpos($action, 'protected function deriveFor'));

        $force = strpos($action, "getParam( 'force' )");
        $flag  = strpos($action, "getSetting( 'base', 'use_32bit_hash' )");
        $data  = strpos($action, '$this->workRemains()');

        $this->assertNotFalse($force, '--force must be available');
        $this->assertNotFalse($flag, 'the flag must gate an ordinary run');
        $this->assertNotFalse($data, 'what remains must be read from the data');

        $this->assertLessThan($data, $flag,
            'the cheap flag check must come before anything that scans rows');

        $this->assertStringContainsString('! $force && !', $action,
            '--force must skip the flag gate and nothing else');
    }

    /**
     * The map lives exactly as long as it is needed: dropped when completion is
     * verified, and never before.
     *
     * While a migration is incomplete it is the only thing linking an old id to
     * its new one, and it cannot be rebuilt -- rebuilding reads dimension rows,
     * which are all wide by then. So a drop on any earlier path would make a
     * killed run unrecoverable. Equally, keeping it after completion leaves one
     * row per converted dimension behind for good, to answer a question nobody
     * asks.
     */
    public function testTheMapIsDroppedOnlyAfterCompletionIsVerified(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/RederiveDimensionIdsCli.php'
        );

        $action = substr($source, strpos($source, 'function action()'));
        $action = substr($action, 0, strpos($action, 'protected function deriveFor'));

        // Every drop lives in action(), never inside a phase.
        $this->assertSame(
            substr_count($source, '$this->dropMap();'),
            substr_count($action, '$this->dropMap();'),
            'dropMap() must not be called from any phase, only from action()'
        );

        // There are two legitimate call sites -- the early "already finished but
        // the flag was never cleared" path, and the main completion path -- and
        // both must come after something has established there is nothing left.
        $gate = strpos($action, '$this->workRemains()');
        $this->assertNotFalse($gate, 'the data-driven gate must come first');

        $offset = 0;
        $drops  = 0;

        while (($at = strpos($action, '$this->dropMap();', $offset)) !== false) {
            $this->assertGreaterThan($gate, $at,
                'the map must never be dropped before the work-remaining check');
            $drops++;
            $offset = $at + 1;
        }

        $this->assertGreaterThan(0, $drops, 'the map has to be dropped somewhere');

        // The main path drops only after completion is verified by counting.
        $verify = strpos($action, 'countNarrowKeys()');
        $last   = strrpos($action, '$this->dropMap();');

        $this->assertNotFalse($verify);
        $this->assertLessThan($last, $verify,
            'the completing drop must follow the verification count');
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
