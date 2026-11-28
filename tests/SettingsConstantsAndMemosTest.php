<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Settings;

/**
 * Constants that govern their settings (OWA_SQS_REGION, OWA_MAILER_OPTIONS),
 * the server's cookie domain, and the memos a settings flush drops.
 */
final class SettingsConstantsAndMemosTest extends TestCase
{
    use SettingsSingletonSnapshot;

    /** @var string|null */
    private $host;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
        $this->host = $_SERVER['HTTP_HOST'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();

        if ( $this->host === null ) {
            unset( $_SERVER['HTTP_HOST'] );
        } else {
            $_SERVER['HTTP_HOST'] = $this->host;
        }
    }

    public function testOwaSqsRegionIsTheRegionSettingsConstant(): void
    {
        $this->assertSame( 'OWA_SQS_REGION', Settings::constantForSetting( 'sqs', 'region' ) );
    }

    public function testOwaMailerOptionsIsDeclaredAndConfigOnly(): void
    {
        $this->assertSame( 'OWA_MAILER_OPTIONS', Settings::constantForSetting( 'base', 'mailer-options' ) );
        $this->assertFalse( $this->settings()->mayPersistInstallWide( 'base', 'mailer-options' ) );
    }

    /**
     * An array constant reaches PHPMailer's SMTPOptions as it was defined.
     *
     * @covers-setting base.mailer-options
     */
    public function testOwaMailerOptionsBecomesSmtpOptions(): void
    {
        $options = [ 'ssl' => [ 'cafile' => '/owatest/ca.pem', 'peer_name' => 'smtp.example.com' ] ];

        $c = $this->settings();
        $c->applyDeclaredConstants( [ 'OWA_MAILER_OPTIONS' => $options, 'OWA_MAILER_USE_SMTP' => true ] );

        $this->assertSame( 'OWA_MAILER_OPTIONS', $c->configFileConstantFor( 'base', 'mailer-options' ) );
        $this->assertSame( $options, ( new \OWA\Module\Base\Classes\Mailer() )->mailer->SMTPOptions );
    }

    public function testANonArrayMailerOptionsIsNotUsed(): void
    {
        $this->settings()->applyDeclaredConstants( [ 'OWA_MAILER_OPTIONS' => 'verify_peer=0', 'OWA_MAILER_USE_SMTP' => true ] );

        $this->assertSame( [], ( new \OWA\Module\Base\Classes\Mailer() )->mailer->SMTPOptions );
    }

    /** A 1.x config's $this->set() of mailer-options is refused with the constant to use. */
    public function testTheConfigFileRefusalNamesOwaMailerOptions(): void
    {
        $problems = \OWA\Core\ConfigFile::problemsIn(
            "<?php\n\$this->set('base', 'mailer-options', array('ssl' => array('verify_peer' => true)));\n" );

        $this->assertCount( 1, $problems );
        $this->assertSame( "define( 'OWA_MAILER_OPTIONS', … );", $problems[0]['replacement'] );
    }

    public function testMainAbsoluteUrlIsDeclared(): void
    {
        $this->assertTrue( $this->settings()->isRegistered( 'base', 'main_absolute_url' ) );
    }

    /** The cookie domain is the host as given: a www. host keeps its www. */
    public function testAWwwHostKeepsItsWww(): void
    {
        $c = $this->settings();

        $_SERVER['HTTP_HOST'] = 'www.example.com:8080';
        $c->setCookieDomain();
        $this->assertSame( '.www.example.com', $c->get( 'base', 'cookie_domain' ) );

        $c->setCookieDomain( 'www.example.org' );
        $this->assertSame( '.www.example.org', $c->get( 'base', 'cookie_domain' ) );
    }

    public function testASingleLabelHostSetsNoCookieDomain(): void
    {
        $c = $this->settings();
        $c->set( 'base', 'cookie_domain', '.owatest.example.com' );

        $c->setCookieDomain( 'localhost' );

        $this->assertSame( '.owatest.example.com', $c->get( 'base', 'cookie_domain' ) );
    }

    /** A settings flush drops the tracking memos of scoped settings. */
    public function testASettingsFlushForgetsTheCampaignKeyMaps(): void
    {
        $keys = new ReflectionProperty( \OWA\Module\Base\Classes\TrackingEventHelpers::class, 'campaignKeys' );
        $tags = new ReflectionProperty( \OWA\Module\Base\Classes\TrackingEventHelpers::class, 'landingTags' );

        $keys->setValue( null, [ 'owatest-site' => [ 'utm_source' => 'tagged_source' ] ] );
        $tags->setValue( null, [ 'owatest-site|https://example.com/' => [] ] );

        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertSame( [], $keys->getValue() );
        $this->assertSame( [], $tags->getValue() );
    }

    public function testForgettingCubesForgetsWhichPropertyASiteIsOf(): void
    {
        $memo = new ReflectionProperty( \OWA\Module\Base\Classes\Cube\Cubes::class, 'property_of_site' );
        $memo->setValue( null, [ 'owatest-site' => '7775000000000099' ] );

        \OWA\Module\Base\Classes\Cube\Cubes::forgetExistence();

        $this->assertSame( [], $memo->getValue() );
    }

    /** The snapshot restores what a constant marks on the registry. */
    public function testTheSnapshotRestoresTheRegistry(): void
    {
        $c = $this->settings();

        $this->assertSame( '', $c->configFileConstantFor( 'base', 'mailer-host' ) );

        $c->applyDeclaredConstants( [ 'OWA_MAILER_HOST' => 'smtp.example.com' ] );
        $this->assertSame( 'OWA_MAILER_HOST', $c->configFileConstantFor( 'base', 'mailer-host' ) );

        $this->restoreSettings();

        $this->assertSame( '', $c->configFileConstantFor( 'base', 'mailer-host' ) );
    }
}
