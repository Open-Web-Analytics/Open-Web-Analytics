<?php

use PHPUnit\Framework\TestCase;

/**
 * The repository's own documents link the current wiki pages, which carry no
 * version prefix; v1-* and v2-* pages are archived ones.
 */
final class RepoDocsWikiLinksTest extends TestCase
{
    public function testNoRepositoryDocumentLinksAVersionedWikiPage(): void
    {

        $root  = dirname(__DIR__);
        $files = array_merge(
            ["$root/README.md", "$root/CONTRIBUTING.md", "$root/UPGRADING.md"],
            glob("$root/.github/ISSUE_TEMPLATE/*.md") ?: []
        );
        $links = 0;

        foreach ($files as $file) {
            preg_match_all('~github\.com/Open-Web-Analytics/Open-Web-Analytics/wiki/([^\s)#]+)~', (string) file_get_contents($file), $m);

            foreach ($m[1] as $page) {
                $links++;
                $this->assertDoesNotMatchRegularExpression('/^v[0-9]+-/', $page, basename($file) . " links $page");
            }
        }

        $this->assertGreaterThan(10, $links);
    }
}
