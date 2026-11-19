<?php

use OWA\Module\Base\Classes\ProfileDomains;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * ProfileDomains::originOf(): the origin of an http(s) url on a Profile's
 * domain, and '' for one a browser could open somewhere else.
 */
final class ProfileDomainsOriginTest extends TestCase
{
    private const DOMAINS = [ 'example.test' ];

    public function testAPageOnTheDomainHasItsOrigin(): void
    {
        $this->assertSame( 'https://example.test', ProfileDomains::originOf( 'https://example.test/a', self::DOMAINS ) );
        $this->assertSame( 'http://shop.example.test:8080', ProfileDomains::originOf( 'http://shop.example.test:8080/a?b=1', self::DOMAINS ) );
    }

    /** @return array<string, array{string}> */
    public static function readElsewhere(): array
    {
        return [
            'a backslash before the user'  => [ 'https://elsewhere.test\\@example.test/' ],
            'a backslash as the separator' => [ 'https://example.test\\.elsewhere.test/' ],
            'a backslash in the path'      => [ 'https://example.test/a\\b' ],
            'a tab'                        => [ "https://elsewhere.te\tst@example.test/" ],
            'a newline'                    => [ "https://example.test/\nx" ],
            'a NUL'                        => [ "https://example.test\0.elsewhere.test/" ],
            'a DEL'                        => [ "https://example.test/\x7f" ],
        ];
    }

    /** @dataProvider readElsewhere */
    public function testAUrlABrowserReadsDifferentlyHasNoOrigin( string $url ): void
    {
        $this->assertSame( '', ProfileDomains::originOf( $url, self::DOMAINS ) );
    }
}
