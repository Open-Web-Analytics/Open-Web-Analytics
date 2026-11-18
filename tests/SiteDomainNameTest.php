<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * getDomainName() must answer for a domain stored without a scheme.
 *
 * owa_site holds both shapes on an upgraded install, and stores only the bare
 * one going forward: a domain is not a URL.
 */
final class SiteDomainNameTest extends TestCase
{
    private function siteWithDomain( string $domain )
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        if ( $domain !== '' ) {

            $site->set( 'domain', $domain );
        }

        return $site;
    }

    public function testABareDomainIsReturnedRatherThanNull(): void
    {
        /* A domain with no scheme. */
        $this->assertSame(
            'example.com',
            $this->siteWithDomain( 'example.com' )->getDomainName(),
            'A domain stored without a scheme must still resolve.' );
    }

    public function testASchemeIsStrippedWhenPresent(): void
    {
        $this->assertSame( 'example.com', $this->siteWithDomain( 'https://example.com' )->getDomainName() );
        $this->assertSame( 'example.com', $this->siteWithDomain( 'http://example.com' )->getDomainName() );
    }

    public function testATrailingSlashIsRemoved(): void
    {
        $this->assertSame( 'example.com', $this->siteWithDomain( 'http://example.com/' )->getDomainName() );
    }

    public function testBothShapesAgree(): void
    {
        /*
         * The property the migration depends on: stripping schemes from stored
         * values must not change what this method answers.
         */
        $this->assertSame(
            $this->siteWithDomain( 'https://example.com' )->getDomainName(),
            $this->siteWithDomain( 'example.com' )->getDomainName() );
    }

    public function testAnEmptyDomainAnswersEmptyNotNull(): void
    {
        /*
         * Empty rather than null, so a caller always has a string. Note the
         * entity ignores falsy writes to string columns, so
         * this is the un-set case rather than a set-to-empty one.
         */
        $this->assertSame( '', $this->siteWithDomain( '' )->getDomainName() );
    }

    public function testASubdomainIsLeftIntact(): void
    {
        $this->assertSame(
            'sub.example.co.uk',
            $this->siteWithDomain( 'sub.example.co.uk' )->getDomainName() );
    }

    /**
     * THE INSTALLER WRITES THE BARE SHAPE, so this column gains no new rows
     * with a scheme in them: the wizard and the CLI installer both normalise
     * whatever is typed.
     *
     * @dataProvider installerDomainProvider
     */
    public function testTheInstallerStoresADomainNotAUrl( string $typed, string $stored ): void
    {
        $this->assertSame(
            $stored,
            \OWA\Module\Base\Classes\SiteManager::domainOnly( $typed ) );
    }

    public static function installerDomainProvider(): array
    {
        return [
            'a bare domain is left alone'  => [ 'example.com', 'example.com' ],
            'https is stripped'            => [ 'https://example.com', 'example.com' ],
            'http is stripped'             => [ 'http://example.com', 'example.com' ],
            'a trailing slash goes'        => [ 'https://example.com/', 'example.com' ],
            'a subdomain survives'         => [ 'https://sub.example.co.uk', 'sub.example.co.uk' ],
            'surrounding space goes'       => [ '  example.com  ', 'example.com' ],
            'a port is not a scheme'       => [ 'example.com:8080', 'example.com:8080' ],
            'a scheme in capitals'         => [ 'HTTPS://Example.com/', 'Example.com' ],
            'a port after a scheme stays'  => [ 'http://localhost:8080/', 'localhost:8080' ],
            'two schemes, two slashes'     => [ 'https://http://example.com//', 'example.com' ],
            'another scheme is not http'   => [ 'ftp://example.com', 'ftp://example.com' ],
        ];
    }

    /**
     * ...and what it writes is what getDomainName() answers, unchanged.
     *
     * The two have to agree: one normalises on the way in and the other on the
     * way out, and a round trip that altered the value would mean the stored
     * domain and the reported one were different strings.
     */
    public function testWhatTheInstallerStoresSurvivesTheRoundTrip(): void
    {
        foreach ( [ 'example.com', 'https://example.com/', 'sub.example.co.uk' ] as $typed ) {

            $stored = \OWA\Module\Base\Classes\SiteManager::domainOnly( $typed );

            $this->assertSame(
                $stored,
                $this->siteWithDomain( $stored )->getDomainName(),
                sprintf( 'the round trip changed "%s"', $typed ) );
        }
    }
}
