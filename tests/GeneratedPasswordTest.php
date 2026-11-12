<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An account created without a password gets a random one, and the value the
 * old generator gave every such account cannot be used to sign in.
 *
 */
final class GeneratedPasswordTest extends TestCase
{
    public function testAGeneratedPasswordIsRandom(): void
    {
        $u = new \OWA\Module\Base\Entity\User();

        $a = $u->generateRandomPassword();
        $b = $u->generateRandomPassword();

        $this->assertMatchesRegularExpression( '/^[0-9a-f]{24}$/', $a );
        $this->assertNotSame( $a, $b );
        $this->assertFalse( \OWA\Core\Auth::isLegacyGeneratedPassword( $a ) );
    }

    /** @return array<string, array{string, bool}> */
    public static function passwords(): array
    {
        return array(
            'bcrypt, cost 10'      => array( '$2y$10', true ),
            'bcrypt, cost 12'      => array( '$2y$12', true ),
            'older bcrypt prefix'  => array( '$2a$10', true ),
            'argon2'               => array( '$argon', true ),
            'longer than a prefix' => array( '$2y$10abc', false ),
            'a chosen password'    => array( 'correct horse', false ),
            'empty'                => array( '', false ),
        );
    }

    /**
     * @dataProvider passwords
     */
    public function testTheOldGeneratedValueIsRecognised( string $password, bool $legacy ): void
    {
        $this->assertSame( $legacy, \OWA\Core\Auth::isLegacyGeneratedPassword( $password ) );
    }

    /**
     * An account whose stored hash IS of the old value -- one created before
     * this fix and never given a password by its owner -- is refused with it.
     * The same account with a password of its own signs in.
     */
    public function testSigningInWithTheOldGeneratedValueIsRefused(): void
    {
        $this->assertFalse( $this->signIn( '$2y$10', '$2y$10' ) );
        $this->assertTrue( $this->signIn( 'a password of my own', 'a password of my own' ) );
    }

    private function signIn( string $stored, string $typed ): bool
    {
        $user = new \OWA\Module\Base\Entity\User();
        $user->set( 'user_id', 'owa-test-generated' );
        $user->set( 'password', \OWA\Core\Lib::encryptPassword( $stored ) );

        // The user the database would return, without a database.
        $auth = new class( $user ) extends \OWA\Core\Auth {

            private $stub;

            public function __construct( $stub )
            {
                parent::__construct();
                $this->stub = $stub;
            }

            function getUser()
            {
                $this->u = $this->stub;
            }

            // No cookies from a test process.
            function saveCredentials()
            {
            }

            // The cookie check a refused password falls through to, without a
            // database.
            function reconstructAuthCredential( $user_id )
            {
                return $this->generateAuthCredential( $user_id, $this->stub->get( 'password' ) );
            }
        };

        return true === $auth->authByInput( 'owa-test-generated', $typed );
    }
}
