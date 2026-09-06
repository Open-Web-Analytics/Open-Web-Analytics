<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * getDomainName() must answer for a domain stored without a scheme.
 *
 * It used to be guarded by `strpos( $domain, '://' )` and fall off the end when
 * there was none, returning NULL. That mattered because owa_site already holds
 * both shapes, and it is about to hold only the bare one: a domain is not a URL,
 * and storing a scheme was load-bearing only while a site's identity was
 * md5( domain ), where http:// and https:// produced two unrelated sites for a
 * single website.
 *
 * The failure was silent rather than loud. Its one caller builds
 * '://' . $domain to test whether a URL already points at the site before
 * rewriting domain aliases; with a null that becomes a search for '://' alone,
 * which matches every absolute URL, so the guard passes and alias resolution is
 * skipped entirely.
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
        /* The regression. Everything else here already worked. */
        $this->assertSame(
            'example.com',
            $this->siteWithDomain( 'example.com' )->getDomainName(),
            'A domain stored without a scheme must still resolve, or domain-alias rewriting '
            . 'silently stops running for that site.' );
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
         * values must not change what this method answers, or every site using
         * domain aliases would shift at once.
         */
        $this->assertSame(
            $this->siteWithDomain( 'https://example.com' )->getDomainName(),
            $this->siteWithDomain( 'example.com' )->getDomainName() );
    }

    public function testAnEmptyDomainAnswersEmptyNotNull(): void
    {
        /*
         * Empty rather than null, so the caller's '://' . $domain stays a
         * string. Note the entity ignores falsy writes to string columns, so
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
     * THE INSTALLER NOW WRITES THE BARE SHAPE, so this column stops gaining new
     * rows with a scheme in them.
     *
     * The wizard asked for the scheme in a select and prefixed it onto what was
     * typed -- while the CLI installer stored the domain as given, so the two
     * install paths disagreed about what a domain is. The select is gone and
     * whatever is typed is normalised instead.
     *
     * @dataProvider installerDomainProvider
     */
    public function testTheInstallerStoresADomainNotAUrl( string $typed, string $stored ): void
    {
        $this->assertSame(
            $stored,
            \OWA\Module\Base\Controller\InstallBase::domainOnly( $typed ) );
    }

    public static function installerDomainProvider(): array
    {
        return array(
            'a bare domain is left alone'  => array( 'example.com', 'example.com' ),
            'https is stripped'            => array( 'https://example.com', 'example.com' ),
            'http is stripped'             => array( 'http://example.com', 'example.com' ),
            'a trailing slash goes'        => array( 'https://example.com/', 'example.com' ),
            'a subdomain survives'         => array( 'https://sub.example.co.uk', 'sub.example.co.uk' ),
            'surrounding space goes'       => array( '  example.com  ', 'example.com' ),
            'a port is not a scheme'       => array( 'example.com:8080', 'example.com:8080' ),
        );
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
        foreach ( array( 'example.com', 'https://example.com/', 'sub.example.co.uk' ) as $typed ) {

            $stored = \OWA\Module\Base\Controller\InstallBase::domainOnly( $typed );

            $this->assertSame(
                $stored,
                $this->siteWithDomain( $stored )->getDomainName(),
                sprintf( 'the round trip changed "%s"', $typed ) );
        }
    }
}
