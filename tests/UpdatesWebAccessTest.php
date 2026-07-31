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
 * Access control on the web update flow.
 *
 * base.updatesApply mutates the schema. It is guarded by two independent
 * checks in Core\Controller::doAction(), in this order:
 *
 *   1. line 222 -- checkCapabilityAndAuthenticateUser('edit_modules')
 *   2. line 230 -- the nonce check, only when request_mode === 'web_app'
 *
 * Before this was fixed it had NEITHER, so an unauthenticated request could
 * run pending module updates.
 *
 * The ordering is deliberate and asserted below: an anonymous visitor is
 * stopped by the capability gate, not by a confusing nonce failure.
 *
 * Its sibling base.updates is intentionally ungated -- updateAction() redirects
 * there when an out-of-date schema is detected, before any capability check, so
 * requiring auth would hide the notice behind a login wall. That is asserted
 * here too, so nobody "hardens" it by mistake.
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

    public function testAnonymousRequestCannotApplyUpdates(): void
    {
        $this->resetCurrentUser();

        $this->assertFalse(
            $this->runProbe(['do' => 'base.updatesApply']),
            'An unauthenticated request reached the update action. '
            . 'base.updatesApply must require the edit_modules capability.'
        );
    }

    public function testNonAdminRoleCannotApplyUpdates(): void
    {
        // 'viewer' holds install_schema/view_site_list/view_reports -- notably
        // NOT edit_modules.
        $this->authenticateAs('viewer');

        $this->assertFalse(
            $this->runProbe(['do' => 'base.updatesApply']),
            'A viewer reached the update action; edit_modules is admin-only.'
        );
    }

    public function testAdminWithoutANonceCannotApplyUpdates(): void
    {
        $this->authenticateAs('admin');

        $this->assertFalse(
            $this->runProbe(['do' => 'base.updatesApply']),
            'An admin applied updates with no nonce. This is the CSRF path: a '
            . 'crafted link would make a logged-in admin migrate the schema.'
        );
    }

    public function testAdminWithAnInvalidNonceCannotApplyUpdates(): void
    {
        $this->authenticateAs('admin');

        $this->assertFalse(
            $this->runProbe([
                'do'    => 'base.updatesApply',
                'nonce' => 'not-a-real-nonce',
            ]),
            'A forged nonce was accepted.'
        );
    }

    public function testAdminWithAValidNonceCanApplyUpdates(): void
    {
        $this->authenticateAs('admin');

        // Must be minted AFTER authenticating: createNonce() binds to user_id.
        $nonce = owa_coreAPI::createNonce('base.updatesApply');

        $this->assertTrue(
            $this->runProbe([
                'do'    => 'base.updatesApply',
                'nonce' => $nonce,
            ]),
            'A properly authenticated admin with a valid nonce was blocked. '
            . 'The gates are too strict and the update flow is broken.'
        );
    }

    /**
     * The nonce is user-bound, which is what makes it a CSRF defence rather
     * than a shared secret. A nonce minted for one user must not work for
     * another.
     */
    public function testANonceMintedForAnotherUserIsRejected(): void
    {
        // NB: the base authenticateAs() always uses the label 'auth', so calling
        // it twice re-creates the SAME user_id and the nonces match trivially.
        // These must be two genuinely different accounts.
        $this->authenticateAsDistinct('admin', 'nonce-owner');
        $otherUsersNonce = owa_coreAPI::createNonce('base.updatesApply');

        $this->resetCurrentUser();
        $this->authenticateAsDistinct('admin', 'nonce-thief');

        $this->assertFalse(
            $this->runProbe([
                'do'    => 'base.updatesApply',
                'nonce' => $otherUsersNonce,
            ]),
            "Another user's nonce was accepted; the nonce is not user-bound."
        );
    }

    /**
     * Guards the deliberate asymmetry. base.updates is the target of the
     * pre-auth schema-out-of-date interception -- see
     * Core\Controller::updateAction(), which does
     * setRedirectAction('base.updates') BEFORE the capability check runs.
     */
    public function testUpdatesNoticeStaysReachableWithoutAuthentication(): void
    {
        $this->resetCurrentUser();

        $data = $this->runControllerData(
            \OWA\Module\Base\Controller\Updates::class,
            '/' . OWA_BASE_MODULE_DIR . 'Controller/Updates.php',
            ['do' => 'base.updates']
        );

        $this->assertSame(
            'base.updates',
            $data['view'] ?? null,
            'base.updates no longer renders for an unauthenticated request. '
            . 'The update interception redirects here before any capability '
            . 'check, so gating it hides the notice behind a login wall.'
        );
    }

    /**
     * Belt and braces: the declarations themselves, independent of the
     * pipeline above.
     */
    public function testUpdatesApplyDeclaresBothGuards(): void
    {
        $src = file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/UpdatesApply.php'
        );

        $this->assertStringContainsString(
            "setRequiredCapability('edit_modules')",
            $src,
            'UpdatesApply must require edit_modules. Note install_schema would '
            . 'NOT work -- the "everyone" role holds it, so isEveryoneCapable() '
            . 'short-circuits the check straight back off.'
        );
        $this->assertStringContainsString(
            'setNonceRequired()',
            $src,
            'UpdatesApply must require a nonce (CSRF).'
        );
    }

    /**
     * The nonce is only useful if the link that drives the flow carries one.
     * makeLink()'s 5th argument is $add_nonce.
     */
    public function testApplyUpdatesLinkCarriesANonce(): void
    {
        $tpl = file_get_contents(
            dirname(__DIR__) . '/modules/Base/templates/updates.php'
        );

        $this->assertMatchesRegularExpression(
            '/makeLink\(\s*array\(\s*[\'"]do[\'"]\s*=>\s*[\'"]base\.updatesApply[\'"]\s*\)\s*,[^)]*true\s*\)/',
            $tpl,
            'The "Apply updates" link must pass $add_nonce (makeLink 5th arg), '
            . 'otherwise UpdatesApply::setNonceRequired() makes the flow '
            . 'permanently fail.'
        );
    }
}
