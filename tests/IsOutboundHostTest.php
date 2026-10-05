<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * TrackingEventHelpers::isOutboundHost() -- the tracker's outbound rule
 * (OWATracker.isOutboundUrl()) for clicks the tracker did not classify. The
 * cases are EnhancedMeasurement.test.js's, so the two rules cannot drift.
 */
final class IsOutboundHostTest extends TestCase
{
    public function testADifferentHostIsOutboundAndTheSameHostIsNot(): void
    {
        $this->assertTrue(TrackingEventHelpers::isOutboundHost('elsewhere.example', 'site.example'));
        $this->assertFalse(TrackingEventHelpers::isOutboundHost('site.example', 'site.example'));
    }

    public function testNothingToCompareIsNotAClaimThatTheClickLeft(): void
    {
        $this->assertFalse(TrackingEventHelpers::isOutboundHost(null, 'site.example'));
        $this->assertFalse(TrackingEventHelpers::isOutboundHost('', 'site.example'));
        $this->assertFalse(TrackingEventHelpers::isOutboundHost('other.example', ''));
    }

    public function testTheCookieDomainAndEveryHostUnderItAreInternal(): void
    {
        foreach (['site.example', 'www.site.example', 'shop.site.example', 'SHOP.Site.Example'] as $host) {
            $this->assertFalse(TrackingEventHelpers::isOutboundHost($host, 'www.site.example', '.site.example'), $host);
        }

        $this->assertTrue(TrackingEventHelpers::isOutboundHost('other.example', 'www.site.example', '.site.example'));
    }

    public function testASuffixIsNotASubdomain(): void
    {
        $this->assertTrue(TrackingEventHelpers::isOutboundHost('evilsite.example', 'www.site.example', 'site.example'));
        $this->assertTrue(TrackingEventHelpers::isOutboundHost('site.example.evil.test', 'www.site.example', 'site.example'));
    }

    /**
     * With no cookie domain set, the tracker uses the page's host without
     * www. -- so from www the apex and its siblings are the site.
     */
    public function testTheDefaultIsThePagesHostWithoutWww(): void
    {
        $this->assertFalse(TrackingEventHelpers::isOutboundHost('site.example', 'www.site.example'));
        $this->assertFalse(TrackingEventHelpers::isOutboundHost('shop.site.example', 'www.site.example'));
        $this->assertFalse(TrackingEventHelpers::isOutboundHost('www.site.example', 'site.example'));

        // From a subdomain that is not www, the default is that subdomain.
        $this->assertTrue(TrackingEventHelpers::isOutboundHost('www.site.example', 'blog.site.example'));
    }

    public function testAConfiguredDomainWidensAPageOnASubdomain(): void
    {
        $this->assertFalse(TrackingEventHelpers::isOutboundHost('www.site.example', 'blog.site.example', 'site.example'));
    }
}
