<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Event;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The device and OS readings of a user agent: device type (an Android
 * without the Mobile token is a tablet), and the brand, model and OS version
 * a reduced user agent reports for every device, which are absent.
 *
 * Through the bundled ua-parser rules, as ingest reads them.
 */
final class DeviceDerivationTest extends TestCase
{
    const GALAXY_TAB  = 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const REDUCED_TAB = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const REDUCED_PHONE = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
    const KINDLE      = 'Mozilla/5.0 (Linux; Android 9; KFTRWI) AppleWebKit/537.36 (KHTML, like Gecko) Silk/120.4.1 like Chrome/120.0.0.0 Safari/537.36';
    const FIRE_WEBVIEW = 'Mozilla/5.0 (Linux; Android 11; KFMAWI Build/RS8332.3115N; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.230 Safari/537.36';
    const FIREFOX_TAB = 'Mozilla/5.0 (Android 13; Tablet; rv:120.0) Gecko/120.0 Firefox/120.0';
    const FIREFOX_PHONE = 'Mozilla/5.0 (Android 13; Mobile; rv:120.0) Gecko/120.0 Firefox/120.0';
    const IPHONE      = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
    const IPAD        = 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
    const MAC_SAFARI  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15';
    const WINDOWS     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const WINDOWS_7   = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36';
    const CHROMEBOOK  = 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const GOOGLEBOT   = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    const GOOGLEBOT_PHONE = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private function event(string $ua): Event
    {
        $event = new Event();
        $event->set('HTTP_USER_AGENT', $ua);

        return $event;
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function deviceTypes(): array
    {
        return [
            'Galaxy Tab'                  => [self::GALAXY_TAB, 'tablet'],
            'a reduced Chrome tablet'     => [self::REDUCED_TAB, 'tablet'],
            'a reduced Chrome phone'      => [self::REDUCED_PHONE, 'mobile'],
            'Kindle Fire, Silk'           => [self::KINDLE, 'tablet'],
            'Fire tablet web view'        => [self::FIRE_WEBVIEW, 'tablet'],
            'Firefox tablet'              => [self::FIREFOX_TAB, 'tablet'],
            'Firefox phone'               => [self::FIREFOX_PHONE, 'mobile'],
            'iPhone'                      => [self::IPHONE, 'mobile'],
            'iPad'                        => [self::IPAD, 'tablet'],
            'iPad Safari asking for the desktop site' => [self::MAC_SAFARI, 'desktop'],
            'Windows'                     => [self::WINDOWS, 'desktop'],
            'a phone crawler'             => [self::GOOGLEBOT_PHONE, 'mobile'],
        ];
    }

    /** @dataProvider deviceTypes */
    public function testTheDeviceType(string $ua, ?string $type): void
    {
        $this->assertSame($type, Helpers::resolveDeviceType(null, $this->event($ua)));
    }

    public function testAPlaceholderBrandOrModelIsAbsent(): void
    {
        foreach ([self::GALAXY_TAB, self::REDUCED_TAB, self::REDUCED_PHONE, self::FIRE_WEBVIEW,
                  self::FIREFOX_TAB, self::FIREFOX_PHONE, self::GOOGLEBOT, self::GOOGLEBOT_PHONE] as $ua) {
            $this->assertNull(Helpers::resolveDeviceBrand(null, $this->event($ua)), $ua);
        }

        foreach ([self::REDUCED_TAB, self::REDUCED_PHONE, self::FIREFOX_TAB, self::FIREFOX_PHONE,
                  self::GOOGLEBOT, self::GOOGLEBOT_PHONE] as $ua) {
            $this->assertNull(Helpers::resolveDeviceModel(null, $this->event($ua)), $ua);
        }

        $this->assertSame('SM-X700', Helpers::resolveDeviceModel(null, $this->event(self::GALAXY_TAB)));
        $this->assertSame('Apple', Helpers::resolveDeviceBrand(null, $this->event(self::IPHONE)));
        $this->assertSame('iPhone', Helpers::resolveDeviceModel(null, $this->event(self::IPHONE)));
        $this->assertSame('Amazon', Helpers::resolveDeviceBrand(null, $this->event(self::KINDLE)));
    }

    public function testAFrozenOsVersionIsAbsent(): void
    {
        foreach ([self::WINDOWS, self::MAC_SAFARI, self::CHROMEBOOK, self::REDUCED_TAB, self::REDUCED_PHONE] as $ua) {
            $this->assertNull(Helpers::resolveOsVersion(null, $this->event($ua)), $ua);
        }

        $this->assertSame('13', Helpers::resolveOsVersion(null, $this->event(self::GALAXY_TAB)));
        $this->assertSame('17.1', Helpers::resolveOsVersion(null, $this->event(self::IPHONE)));
        $this->assertSame('7', Helpers::resolveOsVersion(null, $this->event(self::WINDOWS_7)));
    }
}
