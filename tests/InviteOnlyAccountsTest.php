<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An account created without a password is an invitation, and the links that
 * set a password expire.
 *
 * Nobody can sign in to an invited account until its owner sets a password
 * from the emailed link, the previous generator's values are refused, and a
 * link is random, spent once used, and expires.
 */
final class InviteOnlyAccountsTest extends TestCase
{
    /** A User that is not written anywhere. */
    private function unsavedUser(): \OWA\Module\Base\Entity\User
    {
        return new class extends \OWA\Module\Base\Entity\User {

            function create()
            {
                return true;
            }
        };
    }

    public function testAnAccountGivenNoPasswordCannotBeSignedInTo(): void
    {
        $a = $this->unsavedUser();
        $a->createNewUser( 'owa-test-invited-a', 'viewer' );

        $b = $this->unsavedUser();
        $b->createNewUser( 'owa-test-invited-b', 'viewer' );

        foreach ( [ '$2y$10', '$2y$12', '' ] as $guess ) {
            $this->assertFalse( password_verify( $guess, $a->get( 'password' ) ), "'$guess' opens it" );
        }

        $this->assertNotSame( $a->get( 'password' ), $b->get( 'password' ) );
    }

    public function testAnInvitationCarriesALinkThatWorksForSevenDays(): void
    {
        $u = $this->unsavedUser();
        $u->createNewUser( 'owa-test-invited', 'viewer' );

        $key = $u->get( 'temp_passkey' );

        $this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\.[0-9]+$/', $key );
        $this->assertTrue( \OWA\Module\Base\Entity\User::tempPasskeyIsCurrent( $key ) );

        $expires = (int) explode( '.', $key )[1];
        $this->assertEqualsWithDelta( time() + 7 * 86400, $expires, 5 );
    }

    public function testAnAccountGivenAPasswordSignsInWithIt(): void
    {
        $u = $this->unsavedUser();
        $u->createNewUser( 'owa-test-chosen', 'viewer', 'a password of my own' );

        $this->assertTrue( password_verify( 'a password of my own', $u->get( 'password' ) ) );
    }

    /** @return array<string, array{mixed, bool, bool}> key, well formed, current */
    public static function keys(): array
    {
        $hex = str_repeat( 'ab', 16 );

        return [
            'current'                        => [ $hex . '.' . ( time() + 3600 ), true, true ],
            'expired'                        => [ $hex . '.' . ( time() - 1 ), true, false ],
            'spent'                          => [ $hex . '.0', true, false ],
            'minted before keys expired'     => [ $hex, false, false ],
            'upper-case hex'                 => [ strtoupper( $hex ) . '.' . ( time() + 3600 ), false, false ],
            'short'                          => [ 'abc.' . ( time() + 3600 ), false, false ],
            'empty'                          => [ '', false, false ],
            'not a string'                   => [ [ $hex ], false, false ],
        ];
    }

    /**
     * @dataProvider keys
     */
    public function testAKeyIsCurrentOnlyUntilItsExpiry( $key, bool $wellFormed, bool $current ): void
    {
        $this->assertSame( $wellFormed, false !== \OWA\Module\Base\Entity\User::cleanTempPasskey( $key ) );
        $this->assertSame( $current, \OWA\Module\Base\Entity\User::tempPasskeyIsCurrent( $key ) );
    }

    public function testASpentKeyIsNeverCurrentAndNeverEmpty(): void
    {
        $spent = \OWA\Module\Base\Entity\User::spentTempPasskey();

        $this->assertNotSame( '', $spent );
        $this->assertNotSame( $spent, \OWA\Module\Base\Entity\User::spentTempPasskey() );
        $this->assertFalse( \OWA\Module\Base\Entity\User::tempPasskeyIsCurrent( $spent ) );
    }

    public function testAResetLinkWorksForADay(): void
    {
        $key = \OWA\Module\Base\Entity\User::newTempPasskey( \OWA\Module\Base\Entity\User::RESET_TTL );

        $this->assertEqualsWithDelta( time() + 86400, (int) explode( '.', $key )[1], 5 );
    }

    /**
     * The link's screen says which: an expired link is told to get a new one,
     * anything else that is not a key is not valid.
     */
    public function testTheLinksScreenTellsAnExpiredLinkFromAnInvalidOne(): void
    {
        $hex = str_repeat( 'cd', 16 );

        $this->assertNull( \OWA\Module\Base\Controller\UsersPasswordEntry::keyProblem( $hex . '.' . ( time() + 60 ) ) );
        $this->assertSame( 2012, \OWA\Module\Base\Controller\UsersPasswordEntry::keyProblem( $hex . '.' . ( time() - 60 ) ) );
        $this->assertSame( 2011, \OWA\Module\Base\Controller\UsersPasswordEntry::keyProblem( $hex ) );
        $this->assertSame( 2011, \OWA\Module\Base\Controller\UsersPasswordEntry::keyProblem( null ) );
    }

    /**
     * An expired key finds no account, without a lookup: the check is on the
     * key itself, before the database is asked.
     */
    public function testAnExpiredKeyAuthenticatesNobody(): void
    {
        $auth = new \OWA\Core\Auth();

        $hex = str_repeat( 'ef', 16 );

        $this->assertFalse( $auth->authenticateUserTempPasskey( $hex . '.' . ( time() - 60 ) ) );
        $this->assertFalse( $auth->authenticateUserTempPasskey( $hex ) );
        $this->assertFalse( $auth->authenticateUserTempPasskey( '' ) );
    }

    /** @return array<string, array{string, bool}> */
    public static function passwords(): array
    {
        return [
            'bcrypt, cost 10'      => [ '$2y$10', true ],
            'bcrypt, cost 12'      => [ '$2y$12', true ],
            'older bcrypt prefix'  => [ '$2a$10', true ],
            'argon2'               => [ '$argon', true ],
            'longer than a prefix' => [ '$2y$10abc', false ],
            'a chosen password'    => [ 'correct horse', false ],
        ];
    }

    /**
     * @dataProvider passwords
     */
    public function testTheOldGeneratedValueIsRecognised( string $password, bool $legacy ): void
    {
        $this->assertSame( $legacy, \OWA\Core\Auth::isLegacyGeneratedPassword( $password ) );
    }

    /**
     * An account whose stored hash IS of the generated value "$2y$10" is
     * refused with it. Its owner sets a password through a reset link.
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

            function saveCredentials()
            {
            }
        };

        return true === $auth->authByInput( 'owa-test-generated', $typed );
    }
}
