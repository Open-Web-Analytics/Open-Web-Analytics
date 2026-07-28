<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * Contract + auth tests for the users REST endpoints:
 *
 *   GET    /owa/api/base/v1/users            -> owa_usersRestController   (edit_users)
 *   POST   /owa/api/base/v1/users            -> owa_addUserRestController (edit_users + nonce)
 *   DELETE /owa/api/base/v1/users/{user_id}  -> owa_deleteUserRestController (edit_users + nonce)
 *
 * The GET roster sanitizes secrets in the VIEW (owa_usersRestView), so these
 * tests render through the view and assert on the final JSON body -- a
 * controller-$data-only check would miss a view-level disclosure. This is the
 * same disclosure class as the siteUsers fix: the roster must not leak
 * api_key / password / temp_passkey.
 */
final class UsersRestControllerTest extends RestControllerTestCase
{
    // ------------------------------------------------------------------
    // GET /users
    // ------------------------------------------------------------------

    public function testGetUsersRejectsUnauthenticated(): void
    {
        $resp = $this->callEndpoint(
            'owa_usersRestController',
            'usersRestController.php',
            []
        );

        $this->assertNotAuthenticated($resp, 'GET /users');
    }

    public function testGetUsersRejectsNonAdmin(): void
    {
        // A viewer lacks edit_users -> authenticated-but-not-capable.
        $this->authenticateAs('viewer');

        $resp = $this->callEndpoint(
            'owa_usersRestController',
            'usersRestController.php',
            []
        );

        $this->assertNotSame(200, $resp['status'],
            'A viewer without edit_users must not receive the user roster.');
        $this->assertNull($resp['data'],
            'A non-capable user must not receive a roster payload.');
    }

    public function testGetUsersReturnsRosterAndHidesSecrets(): void
    {
        // A user whose secrets we can search the body for.
        $target = $this->makeUser('viewer', 'target');
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_usersRestController',
            'usersRestController.php',
            []
        );

        $this->assertSame(200, $resp['status'], 'An admin should get 200 from GET /users.');
        $this->assertSame('base.usersRest', $resp['view']);
        $this->assertIsArray($resp['data'], 'The roster payload should be an array.');
        $this->assertNotEmpty($resp['data'], 'The roster should include at least the fixture users.');

        // The target user must be present (so we know the body genuinely
        // contains user records and the secret-absence check is meaningful).
        $this->assertStringContainsString($target['user_id'], $resp['raw'],
            'The target user should appear in the roster.');

        // No record may carry a secret column...
        foreach ($resp['data'] as $record) {
            $this->assertIsArray($record);
            foreach (['api_key', 'password', 'temp_passkey'] as $secret) {
                $this->assertArrayNotHasKey($secret, $record,
                    "The users roster must not disclose {$secret}.");
            }
        }

        // ...and the concrete secret values must not appear anywhere in the body.
        $this->assertNotInBody($target['api_key'], $resp['raw'],
            'The target user api_key value must not be disclosed in the roster body.');
    }

    // ------------------------------------------------------------------
    // POST /users
    // ------------------------------------------------------------------

    public function testPostUsersRejectsUnauthenticated(): void
    {
        $user_id = 'anon-add-' . $this->tok . '@owatest.example.com';

        $resp = $this->callEndpoint(
            'owa_addUserRestController',
            'addUserRestController.php',
            [
                'user_id'       => $user_id,
                'email_address' => $user_id,
                'real_name'     => 'Anon Add',
                'role'          => 'viewer',
            ]
        );

        $this->assertNotAuthenticated($resp, 'POST /users');
        $this->assertFalse($this->userExists($user_id),
            'An unauthenticated POST must not create a user.');
    }

    public function testPostUsersCreatesUser(): void
    {
        $this->authenticateAs('admin');
        $user_id = 'add-' . $this->tok . '@owatest.example.com';

        // Register cleanup by user_id up front so a mid-request throw can't
        // orphan the row (the controller persists before the response is built).
        $this->trackForCleanup('base.user', $user_id, 'user_id');

        $resp = $this->callEndpoint(
            'owa_addUserRestController',
            'addUserRestController.php',
            [
                'user_id'       => $user_id,
                'email_address' => $user_id,
                'real_name'     => 'OWA Test Add ' . $this->tok,
                'role'          => 'viewer',
            ]
        );

        $this->assertSame(201, $resp['status'], 'A valid POST /users should return 201.');
        $this->assertIsArray($resp['data']);
        $this->assertSame($user_id, $resp['data']['user_id'] ?? null,
            'The response should echo the created user_id.');

        // The add response DOES include api_key by design -- it is the newly
        // minted key the caller needs. It must never include the password hash.
        $this->assertArrayNotHasKey('password', $resp['data'],
            'The add-user response must not include the password hash.');

        $this->assertTrue($this->userExists($user_id), 'The user should have been persisted.');
    }

    public function testPostUsersRejectsInvalidRole(): void
    {
        $this->authenticateAs('admin');
        $user_id = 'badrole-' . $this->tok . '@owatest.example.com';

        $resp = $this->callEndpoint(
            'owa_addUserRestController',
            'addUserRestController.php',
            [
                'user_id'       => $user_id,
                'email_address' => $user_id,
                'real_name'     => 'Bad Role',
                'role'          => 'superking-' . $this->tok, // not a real role
            ]
        );

        $this->assertSame(422, $resp['status'],
            'An unknown role should fail inArray validation with 422.');
        $this->assertFalse($this->userExists($user_id),
            'A user with an invalid role must not be created.');
    }

    public function testPostUsersRejectsDuplicateUser(): void
    {
        $existing = $this->makeUser('viewer', 'dup');
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_addUserRestController',
            'addUserRestController.php',
            [
                'user_id'       => $existing['user_id'],
                'email_address' => $existing['user_id'],
                'real_name'     => 'Dup Attempt',
                'role'          => 'viewer',
            ]
        );

        $this->assertSame(422, $resp['status'],
            'A duplicate user_id / email should fail entityDoesNotExist with 422.');
    }

    // ------------------------------------------------------------------
    // DELETE /users
    // ------------------------------------------------------------------

    public function testDeleteUsersRejectsUnauthenticated(): void
    {
        $victim = $this->makeUser('viewer', 'victim');

        $resp = $this->callEndpoint(
            'owa_deleteUserRestController',
            'deleteUserRestController.php',
            ['user_id' => $victim['user_id']]
        );

        $this->assertNotAuthenticated($resp, 'DELETE /users');
        $this->assertTrue($this->userExists($victim['user_id']),
            'An unauthenticated DELETE must not remove the user.');
    }

    public function testDeleteUsersRemovesUser(): void
    {
        $victim = $this->makeUser('viewer', 'victim');
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_deleteUserRestController',
            'deleteUserRestController.php',
            ['user_id' => $victim['user_id']]
        );

        $this->assertSame(202, $resp['status'], 'A valid DELETE /users should return 202.');
        $this->assertFalse($this->userExists($victim['user_id']),
            'The user should have been deleted.');
    }

    public function testDeleteUsersRejectsSelfDeletion(): void
    {
        // The authenticated admin attempts to delete itself.
        $admin = $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_deleteUserRestController',
            'deleteUserRestController.php',
            ['user_id' => $admin['user_id']]
        );

        $this->assertSame(422, $resp['status'],
            'Deleting the current user should fail isNotCurrentUser validation with 422.');
        $this->assertTrue($this->userExists($admin['user_id']),
            'The current user must not be able to delete itself.');
    }
}
