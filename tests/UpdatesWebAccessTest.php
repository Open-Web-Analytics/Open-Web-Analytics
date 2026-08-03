<?php

require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * Probe subclass: inherits UpdatesApply's constructor (and therefore its
 * setRequiredCapability + setNonceRequired declarations) but replaces action()
 * so a passing gate never actually runs a schema migration against the test
 * database. We are testing the gates, not the migrations.
 */
final class UpdatesApplyGateProbe extends \OWA\Module\Base\Controller\UpdatesApply
{
    public static bool $reached = false;

    public static function reset(): void
    {
        self::$reached = false;
    }

    function action()
    {
        self::$reached = true;

        return ['view' => 'base.blank'];
    }
}

/**
 * The web update flow is reachable without signing in, deliberately.
 *
 * base.updatesApply declares no capability and no nonce. The schema has to be
 * brought forward before the rest of the application can be relied on, and the
 * authentication path is part of what may be waiting on it.
 *
 * It WAS gated, and that made the documented upgrade unusable for a signed-out
 * admin -- reported as #979. base.updates renders anonymously, so its Apply link
 * carried a nonce minted with no user_id; createNonce() binds to user_id, so the
 * nonce could never verify once they signed in. The request was turned away, the
 * browser returned to the login form, and correct credentials appeared to be
 * rejected.
 *
 * WordPress takes the same position for the equivalent step: wp-admin/upgrade.php
 * loads wp-load.php rather than admin.php and calls wp_upgrade() with no
 * capability check and no nonce. The control is that the work is idempotent and
 * does nothing unless the schema is actually behind.
 *
 * These assert the action is REACHED, which is what the earlier gating broke.
 * The probe replaces action() so nothing migrates the test database.
 */
final class UpdatesWebAccessTest extends RestControllerTestCase
{
    private const APPLY_CLASS = \OWA\Module\Base\Controller\UpdatesApply::class;

    protected function setUp(): void
    {
        parent::setUp();

        // The nonce check only engages for the web app; the REST base sets
        // 'rest_api', which takes a different branch entirely.
        owa_coreAPI::setSetting('base', 'request_mode', 'web_app');

        UpdatesApplyGateProbe::reset();
    }

    protected function tearDown(): void
    {
        owa_coreAPI::setSetting('base', 'request_mode', 'rest_api');

        parent::tearDown();
    }

    /**
     * Authenticate as a user with a caller-chosen label. The base
     * authenticateAs() hardcodes 'auth', so it cannot produce two distinct
     * accounts within one test -- which matters for the user-bound nonce.
     *
     * @return array{id:mixed, user_id:string}
     */
    private function authenticateAsDistinct(string $role, string $label): array
    {
        $fixture = $this->makeUser($role, $label);

        $entity = owa_coreAPI::entityFactory('base.user');
        $entity->load($fixture['id'], 'id');

        $cu = owa_coreAPI::getCurrentUser();
        $cu->loadNewUserByObject($entity);
        $cu->setAuthStatus(true);

        return $fixture;
    }

    /** Run the probe through the real doAction() pipeline. */
    private function runProbe(array $params): bool
    {
        $ctrl = new UpdatesApplyGateProbe($params);
        $ctrl->doAction();

        return UpdatesApplyGateProbe::$reached;
    }

    /**
     * The #979 case: a signed-out admin follows the documented upgrade and the
     * update runs. Gating this is what broke it.
     */
    public function testAnAnonymousRequestReachesTheUpdateAction(): void
    {
        $this->resetCurrentUser();

        $this->assertTrue(
            $this->runProbe(['do' => 'base.updatesApply']),
            'An anonymous request did not reach the update action. base.updatesApply '
            . 'is public deliberately -- see #979; gating it makes the documented '
            . 'upgrade impossible to complete for a signed-out admin.'
        );
    }

    /** No nonce is required, so the absence of one must not stop it either. */
    public function testNoNonceIsRequired(): void
    {
        $this->resetCurrentUser();

        $this->assertTrue(
            $this->runProbe(['do' => 'base.updatesApply']),
            'A request without a nonce was refused. The Apply link is rendered on a '
            . 'page that serves anonymous visitors, so a nonce minted there carries no '
            . 'user_id and cannot verify once the admin signs in.'
        );
    }

    /** Signing in first must not change the outcome. */
    public function testAnAuthenticatedRequestAlsoReachesTheUpdateAction(): void
    {
        $this->authenticateAsDistinct('admin', 'ungated');

        $this->assertTrue(
            $this->runProbe(['do' => 'base.updatesApply']),
            'An authenticated request did not reach the update action.'
        );
    }

}
