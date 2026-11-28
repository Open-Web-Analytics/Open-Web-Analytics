<?php

use OWA\Module\Domstream\Controller\PlayerLauncher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Play mints the player's overlay token when it is chosen, not when the
 * Recordings report renders: PlayerLauncher builds the overlay params at the
 * click, with a token good for OverlayToken::DEFAULT_TTL from then.
 *
 * Pure: the params need no database. Which page is opened is
 * DomstreamPlayerLauncherPageTest's.
 */
final class DomstreamPlayerLauncherTest extends TestCase
{
    private const SITE = 'owa-player-launcher-site';
    private const RECORDING = '4921417228';

    /** @return array<string, mixed> the fragment's params, decoded as the tracker decodes them */
    private function params(): array
    {
        $fragment = PlayerLauncher::overlayParams( self::SITE, self::RECORDING );
        $decoded  = json_decode( (string) base64_decode( rawurldecode( $fragment ), true ), true );

        $this->assertIsArray( $decoded, 'the fragment is not base64 JSON' );

        return $decoded;
    }

    /** @return array<string, string> the data link's query */
    private function query( string $api_url ): array
    {
        parse_str( (string) parse_url( $api_url, PHP_URL_QUERY ), $query );

        return $query;
    }

    public function testTheParamsStartThePlayerOnTheRecording(): void
    {
        $params = $this->params();
        $query  = $this->query( $params['api_url'] );

        $this->assertSame( 'loadPlayer', $params['action'] );
        $this->assertSame( 'domstreams', $query['do'] );
        $this->assertSame( self::RECORDING, $query['recording_id'] );
        $this->assertSame( self::SITE, $query['siteId'] );
    }

    public function testTheTokenIsFreshAndForThisRecordingOnly(): void
    {
        $before = time();
        $query  = $this->query( $this->params()['api_url'] );
        $claims = \OWA\Core\OverlayToken::verify( $query['overlayToken'] ?? null );

        $this->assertIsArray( $claims, 'the token does not verify' );
        $this->assertSame( 'domstreams', $claims['action'] );
        $this->assertSame( 'recording_id', $claims['resource_key'] );
        $this->assertSame( self::RECORDING, $claims['resource'] );
        $this->assertSame( self::SITE, $claims['scope']['siteId'] );
        $this->assertGreaterThanOrEqual( $before + \OWA\Core\OverlayToken::DEFAULT_TTL, (int) $claims['exp'] );
        $this->assertLessThanOrEqual( time() + \OWA\Core\OverlayToken::DEFAULT_TTL, (int) $claims['exp'] );

        $reader = static fn( string $key ) => $query[ $key ] ?? '';

        $this->assertTrue( \OWA\Core\OverlayToken::permits( $query['overlayToken'], 'domstreams', $reader ) );
    }

    /** The player shows it in its status line when the fetch fails (Player.js). */
    public function testTheParamsCarryTheFetchFailedMessage(): void
    {
        $this->assertSame( \OWA\Core\CoreAPI::t( 'The recording could not be loaded.' ),
            $this->params()['strings']['fetchFailed'] );
    }

    public function testARecordingIdThatIsNotOneOpensNothing(): void
    {
        foreach ( [ '', '0', '-1', 'abc', '1 OR 1=1', str_repeat( '9', 20 ) ] as $id ) {

            $this->assertSame( '', PlayerLauncher::pageOf( self::SITE, $id ), "opened: $id" );
        }
    }

    public function testTheLauncherNeedsViewReports(): void
    {
        $launcher = new PlayerLauncher( [ 'siteId' => self::SITE, 'recording_id' => self::RECORDING ] );

        $this->assertSame( 'view_reports', $launcher->getRequiredCapability() );
        $this->assertInstanceOf( \OWA\Core\AdminController::class, $launcher );
    }
}
