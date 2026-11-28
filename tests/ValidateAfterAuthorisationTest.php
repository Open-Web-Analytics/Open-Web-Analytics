<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Controller::doAction() calls validate() after the capability and nonce
 * checks. Construction calls nothing a subclass's validate() does.
 */
final class ValidateAfterAuthorisationTest extends TestCase
{
    /** @var array<string, mixed> what setUp() changed, to put back */
    private $saved = [];

    protected function setUp(): void
    {
        $auth = \OWA\Core\Auth::get_instance();

        $this->saved = [
            'mode'          => \OWA\Core\CoreAPI::getSetting( 'base', 'request_type' ),
            'server'        => $_SERVER,
            'authenticated' => \OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated(),
            'auth_method'   => $auth->getAuthMethod(),
        ];

        // A refusal on a screen builds its go= URL from the request.
        $_SERVER['HTTP_HOST']   = 'owa.example.com';
        $_SERVER['REQUEST_URI'] = '/index.php?do=base.testValidateAfterAuth';
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting( 'base', 'request_type', $this->saved['mode'] );
        $_SERVER = $this->saved['server'];

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        ( new \ReflectionProperty( $cu, 'is_authenticated' ) )->setValue( $cu, $this->saved['authenticated'] );

        $this->signedInBy( $this->saved['auth_method'] );
    }

    private function mode( string $mode ): void
    {
        \OWA\Core\CoreAPI::setSetting( 'base', 'request_type', $mode );
    }

    /** As though authenticated by $method earlier in this request; null for code in this process. */
    private function signedInBy( ?string $method ): void
    {
        $auth = \OWA\Core\Auth::get_instance();

        ( new \ReflectionProperty( $auth, 'auth_method' ) )->setValue( $auth, $method );
    }

    private function signedIn( ?string $method ): void
    {
        \OWA\Core\CoreAPI::getCurrentUser()->setAuthStatus( true );
        $this->signedInBy( $method );
    }

    /**
     * A controller that records whether validate() and action() ran.
     *
     * @param array<string, mixed> $params
     * @param bool                 $capable what the capability check answers
     */
    private function controller( array $params, string $capability, bool $nonce, bool $capable = true ): object
    {
        return new class( $params, $capability, $nonce, $capable ) extends \OWA\Core\Controller {

            public $validated = 0;

            public $acted = false;

            private $capable;

            public function __construct( $params, $capability = '', $nonce = false, $capable = true ) {

                $this->capable = $capable;
                $this->setRequiredCapability( $capability );

                if ( $nonce ) {

                    $this->setNonceRequired();
                }

                parent::__construct( $params );
            }

            public function validate() {

                $this->validated++;
                $this->addValidation( 'thing', 'present', 'required' );
            }

            protected function isCurrentUserCapable( $currentUser ) {

                return $this->capable;
            }

            public function action() {

                $this->acted = true;
            }
        };
    }

    public function testConstructionDoesNotValidate(): void
    {
        $c = $this->controller( [ 'do' => 'base.testValidateAfterAuth' ], 'edit_settings', true );

        $this->assertSame( 0, $c->validated );
        $this->assertNull( $c->v );
    }

    public function testARequestRefusedForCapabilityIsNeverValidated(): void
    {
        $this->mode( \OWA\Core\RequestType::CLI );
        $this->signedIn( null );

        $c = $this->controller( [ 'do' => 'base.testValidateAfterAuth' ], 'edit_settings', false, false );
        $data = (array) $c->doAction();

        $this->assertSame( 'base.error', $data['view'] ?? null, 'the capability check did not refuse' );
        $this->assertSame( 0, $c->validated );
        $this->assertNull( $c->v );
        $this->assertFalse( $c->acted );
    }

    public function testAScreenRequestRefusedForItsNonceIsNeverValidated(): void
    {
        $this->mode( \OWA\Core\RequestType::UI );

        $c = $this->controller( [ 'do' => 'base.testValidateAfterAuth', 'nonce' => 'not-a-nonce' ], '', true );
        $data = (array) $c->doAction();

        $this->assertSame( 'base.loginForm', $data['do'] ?? null, 'the nonce check did not refuse' );
        $this->assertSame( 0, $c->validated );
        $this->assertNull( $c->v );
        $this->assertFalse( $c->acted );
    }

    public function testARestRequestRefusedForItsSessionNonceIsNeverValidated(): void
    {
        $this->mode( \OWA\Core\RequestType::REST );
        $this->signedIn( 'cookies' );

        $c = $this->controller( [ 'do' => 'base.testValidateAfterAuth', 'nonce' => 'not-a-nonce' ], 'edit_settings', false );
        $data = (array) $c->doAction();

        $this->assertSame( 'base.restApi', $data['view'] ?? null, 'the REST nonce check did not refuse' );
        $this->assertSame( 0, $c->validated );
        $this->assertNull( $c->v );
        $this->assertFalse( $c->acted );
    }

    public function testAnAuthorisedRequestIsValidatedOnceThenActedOn(): void
    {
        $this->mode( \OWA\Core\RequestType::UI );
        $this->signedIn( null );

        $c = $this->controller( [
            'do'    => 'base.testValidateAfterAuth',
            'nonce' => \OWA\Core\CoreAPI::createNonce( 'base.testValidateAfterAuth' ),
        ], 'edit_settings', true );

        $c->doAction();

        $this->assertSame( 1, $c->validated );
        $this->assertNotNull( $c->v );
        $this->assertNotTrue( $c->v->hasErrors );
        $this->assertTrue( $c->acted );
    }
}
