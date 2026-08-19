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

        foreach (['base.visitor', 'base.session'] as $name) {
            $this->assertArrayNotHasKey($name, $dims,
                "$name's id is an event guid, not derived from content, and must never be re-derived");
        }
    }

    /**
     * base.site IS content-derived and must be converted, despite not being a
     * reporting dimension.
     *
     * owa_site.id is generateId( site_id ), and nine call sites load a site that
     * way -- makeUrlCanonical among them. Leaving it behind breaks all of them
     * silently, and a failing makeUrlCanonical means URLs stop being
     * canonicalised at all: no query-string filters, no default page, no domain
     * aliases. Every document id derived afterwards then differs from the ones
     * derived before, splitting the dimension on the installation that just
     * migrated.
     */
    public function testTheSiteIdIsConvertedEvenThoughItIsNotADimension(): void
    {
        $dims = $this->dimensions();

        $this->assertArrayHasKey('base.site', $dims,
            'owa_site.id is derived from site_id and breaks nine call sites if left behind');
        $this->assertSame('site_id', $dims['base.site']['column']);
        $this->assertFalse($dims['base.site']['trim']);
    }

    /**
     * ...and the one other place a derived site id is stored.
     *
     * owa_site_user.site_id is a BIGINT holding owa_site.id, not the site_id
     * string the fact tables carry, and it carries no key declaration.
     */
    public function testSiteGrantsFollowTheSiteId(): void
    {
        $undeclared = \OWA\Module\Base\Controller\RederiveDimensionIdsCli::UNDECLARED_KEYS;

        $this->assertSame('base.site', $undeclared['site_user']['site_id'] ?? null,
            'access grants key on the derived site id and must follow it');
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
     * A fact table can have a content-derived primary key, and this one does.
     *
     * CommerceTransactionHandlers sets owa_commerce_transaction_fact.id to
     * generateId( ct_order_id ), which is how a repeat or corrected transaction
     * finds the existing row instead of writing a second one. Leave it out of
     * the migration and the same order derives a different id afterwards, finds
     * nothing, and duplicates the transaction.
     *
     * Pinned because "fact tables do not have derived ids" is the assumption
     * that made me miss it.
     */
    public function testTheCommerceTransactionKeyIsConverted(): void
    {
        $dims = $this->dimensions();

        $this->assertArrayHasKey('base.commerce_transaction_fact', $dims,
            'its primary key is generateId( order_id ) and duplicates transactions if left behind');
        $this->assertSame('order_id', $dims['base.commerce_transaction_fact']['column']);
    }

    /**
     * The verifier must not consult the list it is checking.
     *
     * A check that walks DIMENSIONS cannot notice an entity missing from
     * DIMENSIONS, and an omitted entity also has no work to plan, so it sails
     * through the "nothing to convert" path too. Both paths therefore run a
     * check that reads the data instead.
     */
    public function testTheVerifierDoesNotReadTheListItIsChecking(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/RederiveDimensionIdsCli.php'
        );

        $start = strpos($source, 'protected function findStaleDerivedIds()');
        $this->assertNotFalse($start);

        $end  = strpos($source, 'protected function dropMap()', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString('dimensionNames()', $body,
            'the verifier must not walk the list, or it cannot see an omission');
        $this->assertStringContainsString("getEntities()", $body,
            'it must consider every entity, not only the ones already covered');
        $this->assertStringContainsString('crc32(', $body,
            'it detects ids left on the old scheme by reproducing them');

        // Both exits check: the completing one and the nothing-to-plan one.
        $action = substr($source, strpos($source, 'function action()'));
        $action = substr($action, 0, strpos($action, 'protected function deriveFor'));

        $this->assertSame(2, substr_count($action, '$this->findStaleDerivedIds()'),
            'both the completion path and the nothing-to-plan path must verify');
    }

    /**
     * The audit, kept executable.
     *
     * Every column a handler writes a derived id into must be covered, whether
     * or not the entity declares it as a key. This list was built by walking
     * every call site of setStringGuid() and generateId() in the codebase --
     * there are about fifty and only a dozen set or write an id -- which is what
     * should have been done at the start instead of discovering owa_site,
     * owa_click.target_id, owa_commerce_transaction_fact and these four one at a
     * time as each broke something.
     *
     * If a handler starts writing a derived id into a new column, add it here
     * and to the command together.
     */
    public function testEveryColumnWrittenWithADerivedIdIsCovered(): void
    {
        // table => columns a handler demonstrably writes a derived id into
        $written = [
            'owa_feed_request' => ['ua_id', 'os_id', 'document_id', 'host_id'],
            'owa_click'        => ['ua_id', 'document_id', 'target_id'],
            'owa_request'      => [
                'prior_document_id', 'document_id', 'ua_id', 'host_id', 'os_id', 'referer_id',
                'campaign_id', 'ad_id', 'source_id', 'location_id', 'referring_search_term_id',
            ],
            'owa_session'      => [
                'first_page_id', 'last_page_id', 'referer_id', 'ua_id', 'host_id', 'os_id',
                'campaign_id', 'ad_id', 'source_id', 'location_id', 'referring_search_term_id',
            ],
            'owa_domstream'    => ['document_id'],
            'owa_site_user'    => ['site_id'],
        ];

        $class = new ReflectionClass(\OWA\Module\Base\Controller\RederiveDimensionIdsCli::class);
        $method = $class->getMethod('factKeyColumns');
        $method->setAccessible(true);
        $covered = $method->invoke($class->newInstanceWithoutConstructor());

        $gaps = [];

        foreach ($written as $table => $columns) {
            foreach ($columns as $column) {
                if (!isset($covered[$table][$column])) {
                    $gaps[] = $table . '.' . $column;
                }
            }
        }

        $this->assertSame([], $gaps,
            "These columns are written with a content-derived id but the migration does not "
            . "rewrite them, so they will keep pointing at ids that have moved:
  "
            . implode("
  ", $gaps));
    }

    /**
     * A database that stops answering must never read as a finished migration.
     *
     * This happened. On a real run the connection dropped near the end: the drop
     * phase silently did nothing, every COUNT came back empty and was read as
     * zero, the completion check concluded there was nothing left, clearing the
     * flag failed silently too, and the command printed "Done. This installation
     * now derives 63-bit ids." over a half-converted database. Db::query()
     * swallows errors, so failure and emptiness were the same value.
     *
     * A COUNT(*) always returns exactly one row, so an empty result means the
     * query did not run. Nothing may turn that into a zero.
     */
    public function testAFailedCountIsNeverReadAsZero(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/RederiveDimensionIdsCli.php'
        );

        $this->assertStringNotContainsString("['n'] ?? 0", $source,
            'defaulting a missing count to zero turns a dead connection into "nothing left to do"');

        $this->assertStringContainsString('protected function countOrNull(', $source,
            'counts must be able to report that they did not run');

        // Every conclusion of "nothing left" must be backed by proof the
        // database is still there.
        $action = substr($source, strpos($source, 'function action()'));
        $action = substr($action, 0, strpos($action, 'protected function countOrNull'));

        $this->assertSame(2, substr_count($action, '$this->databaseIsAnswering()'),
            'both paths that clear the flag must first prove the database is answering');
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
