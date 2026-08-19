<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A page referenced as the PRIOR page gets a document row, so the priorPage*
 * report dimensions resolve.
 *
 * RequestHandlers hashes prior_page into prior_document_id, and four registered
 * dimensions -- priorPageUrl, priorPagePath, priorPageTitle, priorPageType --
 * join owa_document through that key. Nothing ever created the row they join to.
 * A prior page that had been tracked in its own right had a row already; one
 * that never was left the key dangling and those four dimensions silently empty,
 * for 7.2% of the rows carrying the key on one installation and 2.0% on another.
 */
final class PriorPageDocumentTest extends TestCase
{
    /** @var string[] */
    private $created = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->created as $id) {
            $db->query(sprintf("DELETE FROM owa_document WHERE id = '%s'", $db->prepare($id)));
        }

        $this->created = [];
    }

    private function handler(): object
    {
        return new \OWA\Module\Base\Handler\DocumentHandlers();
    }

    /** @return mixed */
    private function callProtected(object $o, string $m, array $args)
    {
        $r = new ReflectionMethod($o, $m);
        $r->setAccessible(true);

        return $r->invokeArgs($o, $args);
    }

    private function track(string $id): string
    {
        $this->created[] = $id;

        return $id;
    }

    public function testAReferencedPageGetsARowAtTheIdTheFactKeyUses(): void
    {
        $url = 'https://example.com/never-tracked/' . bin2hex(random_bytes(4));

        // The id RequestHandlers would write into prior_document_id.
        $expected = (string) \OWA\Core\Lib::setStringGuid($url);
        $this->track($expected);

        $this->callProtected($this->handler(), 'ensureDocumentFor', [new stdClass(), $url]);

        $doc = \OWA\Core\CoreAPI::entityFactory('base.document');
        $doc->load($expected);

        $this->assertTrue($doc->wasPersisted(),
            'the fact key would otherwise point at a row that does not exist');
        $this->assertSame($url, $doc->get('url'));
    }

    /** The uri must match what a real pageview would have recorded. */
    public function testTheUriMatchesWhatATrackedPageviewWouldStore(): void
    {
        $h = $this->handler();

        $this->assertSame('/a/b', $this->callProtected($h, 'uriFor', ['https://example.com/a/b']));
        $this->assertSame('/a?x=1', $this->callProtected($h, 'uriFor', ['https://example.com/a?x=1']));
        $this->assertSame('/', $this->callProtected($h, 'uriFor', ['https://example.com']));
    }

    /**
     * A page that WAS tracked keeps what its own pageview recorded. A later
     * reference to it must not flatten the title.
     */
    public function testAnExistingRowIsNeverOverwritten(): void
    {
        $url = 'https://example.com/tracked/' . bin2hex(random_bytes(4));
        $id  = $this->track((string) \OWA\Core\Lib::setStringGuid($url));

        $doc = \OWA\Core\CoreAPI::entityFactory('base.document');
        $doc->set('id', $id);
        $doc->set('url', $url);
        $doc->set('page_title', 'The Real Title');
        $doc->create();

        $this->callProtected($this->handler(), 'ensureDocumentFor', [new stdClass(), $url]);

        $after = \OWA\Core\CoreAPI::entityFactory('base.document');
        $after->load($id);

        $this->assertSame('The Real Title', $after->get('page_title'),
            'a reference to an already-tracked page must not overwrite its recorded data');
    }

    /** Nothing to reference means nothing to create. */
    public function testNothingIsCreatedForAnAbsentOrPlaceholderUrl(): void
    {
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $before = (int) ((array) $db->get_results('SELECT COUNT(*) AS n FROM owa_document')[0])['n'];

        foreach ([null, '', '(not set)'] as $value) {
            $this->callProtected($this->handler(), 'ensureDocumentFor', [new stdClass(), $value]);
        }

        $after = (int) ((array) $db->get_results('SELECT COUNT(*) AS n FROM owa_document')[0])['n'];

        $this->assertSame($before, $after, 'no row should be invented for a missing url');
    }
}
