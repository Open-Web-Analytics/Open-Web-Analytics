<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Caller::setCurrentUser() names the current user $login_name when given one.
 *
 * It set the role and the authenticated flag and ignored the name, so the
 * current user had no user_id.
 */
final class CallerSetCurrentUserTest extends TestCase
{
    private $user;
    private $capabilities;
    private $authenticated;

    protected function setUp(): void
    {
        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        $this->user          = $cu->user;
        $this->capabilities  = $cu->capabilities;
        $this->authenticated = $cu->is_authenticated;

        // A user of its own, so the process's current user is not edited.
        $cu->user = \OWA\Core\CoreAPI::entityFactory('base.user');
    }

    protected function tearDown(): void
    {
        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        $cu->user             = $this->user;
        $cu->capabilities     = $this->capabilities;
        $cu->is_authenticated = $this->authenticated;
    }

    private function caller(): \OWA\Core\Caller
    {
        // Booting another Caller is not what is under test.
        return (new ReflectionClass(\OWA\Core\Caller::class))->newInstanceWithoutConstructor();
    }

    public function testTheLoginNameIsTheCurrentUsersId(): void
    {
        $this->caller()->setCurrentUser('viewer', 'zz-login-probe');

        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        $this->assertSame('zz-login-probe', $cu->getUserData('user_id'));
        $this->assertSame('viewer', $cu->getRole());
        $this->assertTrue($cu->isAuthenticated());
    }

    /** No name leaves the user_id as it was, as cli.php relies on. */
    public function testNoLoginNameLeavesTheId(): void
    {
        \OWA\Core\CoreAPI::getCurrentUser()->setUserData('user_id', 'zz-before');

        $this->caller()->setCurrentUser('admin');

        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        $this->assertSame('zz-before', $cu->getUserData('user_id'));
        $this->assertSame('admin', $cu->getRole());
        $this->assertTrue($cu->isAuthenticated());
    }
}
