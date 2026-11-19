<?php

use OWA\Module\Base\Controller\ReportDomstreams;
use PHPUnit\Framework\TestCase;

/**
 * The page a recording's Play link opens: an http or https page on the site's
 * domain or a subdomain of it, read the way a browser reads its host. Anything
 * else gives '', and the grid shows no Play link.
 */
final class DomstreamPlayerUrlTest extends TestCase
{
    private const DOMAINS = ['site.example'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'javascript scheme'            => ['javascript:alert(1)'],
            'javascript with a host'       => ['javascript://site.example/%0aalert(1)'],
            'data scheme'                  => ['data:text/html,<p>x</p>'],
            'backslash before userinfo'    => ['https://evil.example\\@site.example/'],
            'tab in the url'               => ["https://site.example/\tx"],
            'newline in the url'           => ["https://site.example/\nx"],
            'non-ASCII character'          => ["https://site.example/\u{00e9}"],
            'another host'                 => ['https://evil.example/'],
            'site domain as userinfo'      => ['https://site.example@evil.example/'],
            'host that only ends the same' => ['https://evilsite.example/'],
            'no scheme'                    => ['//site.example/page'],
            'relative path'                => ['/page'],
            'ftp scheme'                   => ['ftp://site.example/file'],
            'empty'                        => [''],
        ];
    }

    /** @dataProvider refused */
    public function testAUrlOffTheSitesDomainOrNotAWebPageGetsNoUrl(string $url): void
    {
        $this->assertSame('', ReportDomstreams::playerUrl($url, self::DOMAINS));
    }

    /** @return array<string, array{string, string}> */
    public static function accepted(): array
    {
        return [
            'https on the domain'   => ['https://site.example/page?a=b', 'https://site.example/page?a=b'],
            'http on a subdomain'   => ['http://www.site.example:8080/x', 'http://www.site.example:8080/x'],
            'scheme and host case'  => ['HTTPS://Shop.Site.Example/', 'HTTPS://Shop.Site.Example/'],
            'fragment is dropped'   => ['https://site.example/page#top', 'https://site.example/page'],
        ];
    }

    /** @dataProvider accepted */
    public function testAWebPageOnTheSitesDomainIsOpened(string $url, string $expected): void
    {
        $this->assertSame($expected, ReportDomstreams::playerUrl($url, self::DOMAINS));
    }

    public function testASiteWithNoDomainOpensNothing(): void
    {
        $this->assertSame('', ReportDomstreams::playerUrl('https://site.example/', []));
    }

    /** The recorded page is opened without a window.opener. */
    public function testThePlayerWindowIsOpenedWithNoopener(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/modules/Base/templates/report_domstreams.php');

        $this->assertSame(1, preg_match("/window\.open\(\s*link\.attr\( 'href' \),\s*'OWA Dom Stream',\s*'([^']*)'/", $src, $m),
            'the Play handler opens the link with a features string');
        $this->assertContains('noopener', explode(',', $m[1]));
    }
}
