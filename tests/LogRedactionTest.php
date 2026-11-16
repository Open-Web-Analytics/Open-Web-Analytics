<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Credentials are not written to the debug log: the values of the sign-in
 * cookies, and of the credential query parameters in a logged URL.
 */
final class LogRedactionTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function urls(): array
    {
        return [
            'api key and signature' => [
                '/owa/api/index.php?owa_rest_params=base/v1/sites&owa_apiKey=deadbeef&owa_signature=c2ln%3D',
                '/owa/api/index.php?owa_rest_params=base/v1/sites&owa_apiKey=[redacted]&owa_signature=[redacted]',
            ],
            'un-namespaced names' => [
                '/owa/api/index.php?apiKey=deadbeef&signature=abc&period=today',
                '/owa/api/index.php?apiKey=[redacted]&signature=[redacted]&period=today',
            ],
            'overlay token' => [
                '/owa/index.php?do=base.overlayLauncher&overlayToken=t.o.k&url=x',
                '/owa/index.php?do=base.overlayLauncher&overlayToken=[redacted]&url=x',
            ],
            'password reset key and password' => [
                '/owa/index.php?do=base.usersPasswordEntry&k=0123abcd&password=hunter2',
                '/owa/index.php?do=base.usersPasswordEntry&k=[redacted]&password=[redacted]',
            ],
            'a fragment is kept' => [
                '/owa/index.php?k=0123#top',
                '/owa/index.php?k=[redacted]#top',
            ],
            'names that only contain a credential name are kept' => [
                '/owa/index.php?do=base.reportPages&siteId=1&kind=x&mykey=1&apiKeys=2',
                '/owa/index.php?do=base.reportPages&siteId=1&kind=x&mykey=1&apiKeys=2',
            ],
            'no query' => ['/owa/', '/owa/'],
        ];
    }

    /** @dataProvider urls */
    public function testCredentialParametersAreRedactedFromALoggedUrl(string $url, string $logged): void
    {
        $this->assertSame($logged, \OWA\Core\Lib::redactUrlForLog($url));
    }

    public function testTheSignInCookiesValuesAreRedacted(): void
    {
        $this->assertSame('[redacted]', \OWA\Core\CoreAPI::cookieValueForLog('u', 'admin'));
        $this->assertSame('[redacted]', \OWA\Core\CoreAPI::cookieValueForLog('p', 'a1b2c3'));
        $this->assertSame('sess=1', \OWA\Core\CoreAPI::cookieValueForLog('s_123', 'sess=1'));
    }
}
