<?php

use OWA\Core\Template;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every documentation link goes through Template::makeWikiLink(), which puts
 * the page after Template::DOCS_URL, and names a 2.0 page: v2-*, or
 * Upgrading-from-1.x.
 */
final class DocsLinksTest extends TestCase
{
    /** @return array<string, string> path relative to the root => source, for the PHP under Core/ and modules/ */
    private static function sources(): array
    {
        $root = \OWA\Core\Env::root();
        $out  = [];

        foreach (['Core', 'modules'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $path = str_replace($root . '/', '', (string) $file);

                if (substr($path, -4) === '.php' && strpos($path, '/node_modules/') === false) {
                    $out[$path] = (string) file_get_contents((string) $file);
                }
            }
        }

        return $out;
    }

    public function testALinkIsThePageAfterTheDocsUrl(): void
    {
        $template = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor();

        $this->assertSame('https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki', Template::DOCS_URL);
        $this->assertSame(Template::DOCS_URL . '/v2-Home', $template->makeWikiLink('v2-Home'));
        $this->assertSame(Template::DOCS_URL . '/Upgrading-from-1.x', $template->makeWikiLink('Upgrading-from-1.x'));
    }

    /** wiki_url is gone, and OWA_WIKI_URL gets the retired constant's notice. */
    public function testTheSettingIsGone(): void
    {
        $this->assertFalse(\OWA\Core\CoreAPI::configSingleton()->isRegistered('base', 'wiki_url'));
        $this->assertArrayHasKey('OWA_WIKI_URL', \OWA\Core\Requirements::RETIRED_CONSTANTS);
    }

    public function testEveryPageLinkedIsATwoPointOhPage(): void
    {
        $pages = [];

        foreach (self::sources() as $path => $src) {
            preg_match_all('/(?:->|::)makeWikiLink\(\s*([^)]*)\)/', $src, $m);

            foreach ($m[1] as $arg) {
                $arg = trim($arg);
                $this->assertMatchesRegularExpression("/^'[^']+'$/", $arg, "$path passes makeWikiLink() something other than a page name");
                $page = trim($arg, "'");
                $this->assertTrue(strpos($page, 'v2-') === 0 || $page === 'Upgrading-from-1.x', "$path links to $page");
                $pages[$path][] = $page;
            }
        }

        $this->assertSame(['v2-Home'], $pages['modules/Base/templates/header.php'] ?? null);
        $this->assertSame(['v2-Installation'], $pages['modules/Base/templates/install_start.php'] ?? null);
        $this->assertSame(['v2-Javascript-Tracker'], $pages['modules/Base/templates/sites_invocation.php'] ?? null);
    }

    /** No documentation URL is written out but Template::DOCS_URL. */
    public function testNoOtherCodeWritesTheDocsUrl(): void
    {
        $found = [];

        foreach (self::sources() as $path => $src) {
            if (strpos($src, 'Open-Web-Analytics/Open-Web-Analytics/wiki') !== false) {
                $found[] = $path;
            }
        }

        $this->assertSame(['Core/Template.php'], $found);
    }
}
