<?php

use PHPUnit\Framework\TestCase;

/**
 * The shipped .htaccess passes the Authorization header to PHP.
 *
 * Apache withholds the header from PHP-FPM, FastCGI and CGI unless the server
 * sets CGIPassAuth On, so without this line every REST request carrying an
 * application password is answered 401 on those setups. This keeps the line
 * from being dropped or narrowed.
 */
final class HtaccessAuthorizationTest extends TestCase
{
    private static function htaccess(): string
    {
        return (string) file_get_contents(__DIR__ . '/../.htaccess');
    }

    public function testTheHeaderIsCopiedIntoTheEnvironment(): void
    {
        $this->assertMatchesRegularExpression(
            '/<IfModule mod_setenvif\.c>\s*SetEnvIfNoCase \^Authorization\$ "\(\.\+\)" HTTP_AUTHORIZATION=\$1\s*<\/IfModule>/',
            self::htaccess(),
            '.htaccess no longer copies the Authorization header to HTTP_AUTHORIZATION.'
        );
    }
}
