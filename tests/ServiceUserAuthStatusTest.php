<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * ServiceUser::setAuthStatus() sets what it is given.
 *
 * It ignored its argument and always set true, so setAuthStatus(false) -- a
 * request to withdraw authentication -- authenticated the user.
 */
final class ServiceUserAuthStatusTest extends TestCase
{
    public function testTrueAuthenticates(): void
    {
        $u = new \OWA\Module\Base\Classes\ServiceUser();
        $u->setAuthStatus( true );

        $this->assertTrue( $u->isAuthenticated() );
    }

    public function testFalseDoesNotAuthenticate(): void
    {
        $u = new \OWA\Module\Base\Classes\ServiceUser();
        $u->setAuthStatus( false );

        $this->assertFalse( $u->isAuthenticated() );
    }

    public function testFalseWithdrawsAnEarlierTrue(): void
    {
        $u = new \OWA\Module\Base\Classes\ServiceUser();
        $u->setAuthStatus( true );
        $u->setAuthStatus( false );

        $this->assertFalse( $u->isAuthenticated() );
    }

    public function testOnlyATrueBooleanAuthenticates(): void
    {
        // A truthy non-boolean is not taken as consent to authenticate.
        $u = new \OWA\Module\Base\Classes\ServiceUser();
        $u->setAuthStatus( 'yes' );

        $this->assertFalse( $u->isAuthenticated() );
    }
}
