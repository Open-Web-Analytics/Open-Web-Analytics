<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** base.usersEdit with the settings chrome, which reads sites, left out. */
class UsersEditErrorFormEdit extends \OWA\Module\Base\Controller\UsersEdit
{
    protected function resolveCurrentSiteId( $siteId = '', $propertyId = '' )
    {
        return '';
    }

    protected function settingsChrome( array $params, $siteId = '', $propertyId = '' )
    {
    }
}

/**
 * A refused edit draws the EDIT form again (UsersProfile: 'edit' and
 * 'profile'), with what was submitted, not an empty Add user form.
 *
 * No database: errorAction() reads only the request.
 */
final class UsersEditErrorFormTest extends TestCase
{
    public function testARefusedEditRedrawsTheEditFormWithTheSubmittedValues(): void
    {
        $c = new UsersEditErrorFormEdit( [
            'id'            => '42',
            'user_id'       => 'alice',
            'real_name'     => 'Alice Example',
            'role'          => 'analyst',
            'email_address' => 'not-an-address',
            'nonce'         => 'n',
        ] );

        $c->errorAction();

        $this->assertTrue( $c->data['edit'] ?? null, 'the edit form, not the add form' );
        $this->assertSame( [
            'id'            => '42',
            'user_id'       => 'alice',
            'real_name'     => 'Alice Example',
            'role'          => 'analyst',
            'email_address' => 'not-an-address',
        ], $c->data['profile'] ?? null );
        $this->assertSame( 'base.usersProfile', $c->data['subview'] ?? null );
    }

    public function testAFieldThatIsNotAStringRedrawsEmpty(): void
    {
        $c = new UsersEditErrorFormEdit( [ 'user_id' => 'alice', 'real_name' => [ 'x' ] ] );

        $c->errorAction();

        $this->assertSame( '', $c->data['profile']['real_name'] ?? null );
    }
}
