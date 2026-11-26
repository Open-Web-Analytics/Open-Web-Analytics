<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * makeLink() carries the request's link state URL-encoded: a state value
 * holding & = or # stays one parameter, rather than adding others to every
 * link on the screen or cutting it short.
 */
final class LinkStateEncodingTest extends TestCase
{
    public function testAStateValueStaysOneParameter(): void
    {
        $t = new \OWA\Core\Template();
        $t->caller_params['link_state'] = [ 'siteId' => '1&owa_do=base.users#x', 'period' => 'today' ];

        $link = html_entity_decode( $t->makeLink( [ 'do' => 'base.report' ], true ) );
        parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $query );

        $this->assertSame( '1&owa_do=base.users#x', $query['siteId'] ?? null );
        $this->assertSame( 'base.report', $query['do'] ?? null );
        $this->assertArrayNotHasKey( 'owa_do', $query );
        $this->assertSame( 'today', $query['period'] ?? null );
        $this->assertNull( parse_url( $link, PHP_URL_FRAGMENT ), 'the link was cut short' );
    }

    /** What a caller passes is the caller's to encode, as the widget constraints are. */
    public function testAGivenParamIsCarriedAsGiven(): void
    {
        $t = new \OWA\Core\Template();

        $link = html_entity_decode( $t->makeLink( [ 'do' => 'reports', 'constraints' => 'host==a+b%26c' ] ) );
        parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $query );

        $this->assertSame( 'host==a b&c', $query['constraints'] ?? null );
    }
}
