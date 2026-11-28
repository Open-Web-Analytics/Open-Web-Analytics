<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\V2Event;
use OWA\Module\Base\Classes\TrackingEventHelpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The stored form of a URL: the path decoded once, the query and fragment as
 * the page had them. BeaconDecodeOnceTest stores it through ingest.
 */
final class ReadableUrlTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> location.href => stored */
    public static function urls(): array
    {
        return [
            'UTF-8 and a space'    => ['https://x.test/caf%C3%A9/a%20b?q=a%20b&t=50%25', 'https://x.test/café/a b?q=a%20b&t=50%25'],
            'UTF-8 in the query'   => ['https://x.test/caf%C3%A9/x?q=caf%C3%A9', 'https://x.test/café/x?q=caf%C3%A9'],
            'a literal % and &'    => ['https://x.test/50%25off/a%26b?x=1%262&y=%2541', 'https://x.test/50%off/a&b?x=1%262&y=%2541'],
            'a plus sign'          => ['https://x.test/a+b?q=a+b', 'https://x.test/a+b?q=a+b'],
            'one pass'             => ['https://x.test/a%2541', 'https://x.test/a%41'],
            'an encoded ? and #'   => ['https://x.test/a%3Fb%23c?q=1', 'https://x.test/a%3Fb%23c?q=1'],
            'a control character'  => ['https://x.test/a%0Ab%00', 'https://x.test/a%0Ab%00'],
            'a fragment as sent'   => ['https://x.test/a%20b#c%20d', 'https://x.test/a b#c%20d'],
            'a stray %'            => ['https://x.test/100%/x%zz', 'https://x.test/100%/x%zz'],
            'the host as sent'     => ['https://us%65r@x.test:8080/p%20q', 'https://us%65r@x.test:8080/p q'],
            'no path'              => ['https://x.test?q=a%20b', 'https://x.test?q=a%20b'],
            'a relative URL'       => ['/a%20b?q=a%20b', '/a b?q=a%20b'],
            'invalid UTF-8'        => ['https://x.test/caf%E9/x?q=%E9', 'https://x.test/caf%E9/x?q=%E9'],
            'no UTF-8 at all'      => ['https://x.test/bad%FF%FEseq', 'https://x.test/bad%FF%FEseq'],
            'valid and invalid'    => ['https://x.test/caf%C3%A9/x%E9', 'https://x.test/café/x%E9'],
            'valid then invalid'   => ['https://x.test/caf%C3%A9%E9', 'https://x.test/café%E9'],
            'a cut-off character'  => ['https://x.test/a%E2%82', 'https://x.test/a%E2%82'],
            'four bytes'           => ['https://x.test/%F0%9F%98%80', 'https://x.test/😀'],
        ];
    }

    /** @dataProvider urls */
    public function testThePathIsDecodedOnceAndTheRestIsAsSent(string $href, string $stored): void
    {
        $this->assertSame($stored, V2Event::readableUrl($href));
    }

    public function testAnythingButANonEmptyStringIsAsGiven(): void
    {
        $this->assertSame('', V2Event::readableUrl(''));
        $this->assertNull(V2Event::readableUrl(null));
        $this->assertFalse(V2Event::readableUrl(false));
    }

    /** The property callback applies it to a current beacon only. */
    public function testOnlyACurrentBeaconsUrlIsRewritten(): void
    {
        $href = 'https://x.test/caf%C3%A9?q=a%20b';

        $current = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $current->set('beacon_version', '2');

        $older = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');

        $this->assertSame('https://x.test/café?q=a%20b', TrackingEventHelpers::readableUrl($href, $current));
        $this->assertSame($href, TrackingEventHelpers::readableUrl($href, $older), 'no version: decoded twice already, left alone');
    }

    /** Every URL a client sends is read through it, before any other callback. */
    public function testEveryClientUrlPropertyCallsItFirst(): void
    {
        $config = json_decode((string) file_get_contents(
            \OWA\Core\Env::root() . '/modules/Base/config/tracking_properties.json'), true);

        $urls = 0;

        foreach ($config as $name => $property) {

            if (($property['data_type'] ?? '') !== 'url' || ($property['set_by'] ?? '') !== 'client') {
                continue;
            }

            $urls++;

            $this->assertSame('OWA\\Module\\Base\\Classes\\TrackingEventHelpers::readableUrl',
                ((array) ($property['callbacks'] ?? []))[0] ?? null, $name);
        }

        $this->assertGreaterThanOrEqual(4, $urls);
    }

    /** A download's path, a string property, is decoded once as a URL's path is. */
    public function testADownloadsPathIsDecodedOnce(): void
    {
        $config = json_decode((string) file_get_contents(
            \OWA\Core\Env::root() . '/modules/Base/config/tracking_properties.json'), true);

        $this->assertSame(['OWA\\Module\\Base\\Classes\\TrackingEventHelpers::readableUrl'], $config['file_name']['callbacks']);

        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->set('beacon_version', '2');

        $this->assertSame('/docs/café 2.pdf', TrackingEventHelpers::readableUrl('/docs/caf%C3%A9%202.pdf', $event));
        $this->assertSame('/docs/a%3Fb.pdf', TrackingEventHelpers::readableUrl('/docs/a%3Fb.pdf', $event));
    }

    /**
     * The spam rules' copies of a current beacon's URLs come from the keys the
     * beacon sent, not from page_location and HTTP_REFERER, which readableUrl()
     * rewrites later in the pass (TrackingPropertyOrderTest).
     */
    public function testTheSpamCopiesAreTheUrlsAsCarried(): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->set('beacon_version', '2');
        $event->set('p_l', 'https://x.test/a%20b?q=%3Cx%3E');
        $event->set('page_location', 'https://x.test/a b?q=%3Cx%3E');
        $event->set('p_r', 'https://r.test/c%20d');
        $event->set('HTTP_REFERER', 'https://r.test/c d');

        $this->assertSame('https://x.test/a%20b?q=%3Cx%3E', TrackingEventHelpers::receivedPageLocation('', $event));
        $this->assertSame('https://r.test/c%20d', TrackingEventHelpers::receivedReferer('', $event));

        $older = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $older->set('page_url', 'https://x.test/old');
        $older->set('page_location', 'https://x.test/old-renamed');

        $this->assertSame('https://x.test/old', TrackingEventHelpers::receivedPageLocation('', $older), "an older tracker's key");

        $built = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $built->set('page_location', 'https://x.test/p');

        $this->assertSame('https://x.test/p', TrackingEventHelpers::receivedPageLocation('', $built),
            'no format version: page_location is not rewritten, so it is the copy');
    }
}
