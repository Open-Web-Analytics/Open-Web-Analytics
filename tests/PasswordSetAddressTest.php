<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Password Change Complete mail shows the address the password was set
 * from: the client (Core\ClientAddress), not the proxy that connected.
 *
 * No database: the event's properties are read from the request alone.
 */
final class PasswordSetAddressTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private $server;

    protected function setUp(): void
    {
        $this->server = \OWA\Core\CoreAPI::serviceSingleton()->request->server;
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->request->server = $this->server;
    }

    public function testTheAddressIsTheClientBehindTheProxy(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->request->server = [
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'spoofed, 203.0.113.9, 10.0.1.5',
        ] + (array) $this->server;

        $this->assertSame( [ 'ip' => '203.0.113.9', 'user_id' => 'alice' ],
            \OWA\Module\Base\Controller\UsersChangePassword::passwordSetEvent( 'alice' ) );
    }

    public function testTheControllerSendsThatEvent(): void
    {
        $source = (string) file_get_contents( dirname( __DIR__ ) . '/modules/Base/Controller/UsersChangePassword.php' );

        $this->assertStringContainsString( 'log( self::passwordSetEvent( $user_id ), \'base.set_password\' )', $source );
        $this->assertStringNotContainsString( 'REMOTE_ADDR', $source );
    }
}
