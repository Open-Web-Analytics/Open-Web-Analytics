<?php

use PHPUnit\Framework\TestCase;

/**
 * A state-changing action is not resumed across a login.
 *
 * notAuthenticatedAction() put the whole current URL into 'go' so the request
 * could be replayed after login. For a nonce-guarded action that cannot work:
 * createNonce() mixes in the current user_id, so a nonce minted while logged out
 * never verifies once logged in. The replay failed the nonce check, which routes
 * back to the same handler, and the user saw the login form again -- indis-
 * tinguishable from having typed the wrong password.
 *
 * Re-minting the nonce after login would be worse than the bug: a crafted link
 * could name any action and have the server issue a valid nonce for it.
 *
 * So 'go' is set only for actions that are not nonce-guarded.
 */
final class LoginRedirectNonceTest extends TestCase
{
    /**
     * What a controller NAME has to carry to be a write.
     *
     * Shared by both checks below, which need the same answer for opposite
     * reasons: one requires a nonce-guarded controller to look like a write,
     * the other requires a report SCREEN not to be nonce-guarded -- and a
     * controller that writes to a report is not a report screen.
     */
    private const WRITE_VERBS =
        '/(Add|Edit|Save|Delete|Dismiss|Update|Apply|Activate|Deactivate|Install|Mark)/';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * get_current_url() builds 'go' from the request, which does not exist under
     * CLI -- without this the redirect is empty for every case and the assertions
     * below would pass for the wrong reason.
     */
    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST']   = 'owa.example.test';
        $_SERVER['REQUEST_URI'] = '/owa/index.php?owa_do=base.sitesDelete&owa_nonce=abc123';

        // resolveRedirectUrl() compares against this when a redirect target is
        // resolved, so the fixture host has to be the installation's own.
        \OWA\Core\CoreAPI::setSetting('base', 'public_url', 'https://owa.example.test/owa/');

        // notAuthenticatedAction() answers a REST request with a 401 body and
        // never sets 'go' at all. request_mode is global, so a REST test running
        // earlier in the suite would otherwise send these down that branch and
        // make the assertions meaningless.
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'admin_web');
    }

    /** Run notAuthenticatedAction() on a controller and report whether 'go' was set. */
    private function setsGo(bool $nonceRequired): bool
    {
        $controller = new \OWA\Module\Base\Controller\Sites([]);

        if ($nonceRequired) {
            $controller->setNonceRequired();
        }

        $controller->notAuthenticatedAction();

        // set() writes to the controller's data (what the view is handed);
        // get() reads request params. Asserting via get() would report NULL for
        // every case and pass this whole file for the wrong reason.
        $prop = new ReflectionProperty($controller, 'data');
        $prop->setAccessible(true);
        $data = (array) $prop->getValue($controller);

        return isset($data['go']) && $data['go'] !== '';
    }

    /** The reported failure: the replayed request could never have verified. */
    public function testAStateChangingActionIsNotResumedAfterLogin()
    {
        $this->assertFalse(
            $this->setsGo(true),
            'a nonce-guarded action must not be replayed after login'
        );
    }

    /** Deep-linking to a report while logged out must still work. */
    public function testAReadOnlyActionIsStillResumed()
    {
        $this->assertTrue(
            $this->setsGo(false),
            'a read-only destination should still be resumed after login'
        );
    }

    /**
     * The behaviour keys off is_nonce_required, so that flag has to keep meaning
     * "state-changing write". Every controller setting it should be one, and no
     * report or view controller should.
     *
     * The list is write VERBS on purpose. `Read` is deliberately absent: it is
     * the word that signals read-only, and admitting it would blunt the check
     * this whole test exists to make. A controller that writes a read FLAG has
     * to say so in its name -- NotificationMarkReadRest, not
     * NotificationReadRest -- which is what `Mark` covers.
     *
     * `Save` was added with the custom report builder. It is a write verb in
     * the same sense as the rest -- nothing named Save is read-only -- so it
     * does not blunt anything.
     */
    public function testOnlyWriteControllersRequireANonce()
    {
        $withNonce = [];

        foreach (glob(__DIR__ . '/../modules/*/Controller/*.php') as $file) {
            if (strpos((string) file_get_contents($file), 'setNonceRequired') !== false) {
                $withNonce[] = basename($file, '.php');
            }
        }

        $this->assertNotEmpty($withNonce, 'no nonce-guarded controllers found at all');

        foreach ($withNonce as $name) {
            $this->assertMatchesRegularExpression(
                self::WRITE_VERBS,
                $name,
                sprintf(
                    '%s requires a nonce but does not look like a write. If it is read-only '
                    . 'it will silently lose its post-login redirect.',
                    $name
                )
            );
        }
    }

    /**
     * A nonce-guarded controller declares a capability, so the authentication
     * gate turns an anonymous request away before the nonce is ever examined.
     *
     * The gate only authenticates when a capability is set and not granted to
     * everyone, so a controller that requires a nonce but declares no capability
     * is reachable unauthenticated, with the nonce as the only thing in front of
     * it. That is intended for exactly two: the installer runs before any user
     * exists, and requiring authentication there would deadlock the bootstrap.
     * Anything else acquiring that shape is a mistake, not a decision.
     */
    public function testNonceGuardedControllersDeclareACapability()
    {
        $bootstrapExempt = ['InstallBase', 'InstallConfig'];

        foreach (glob(__DIR__ . '/../modules/*/Controller/*.php') as $file) {
            $source = (string) file_get_contents($file);

            if (strpos($source, 'setNonceRequired') === false) {
                continue;
            }

            $name = basename($file, '.php');

            if (in_array($name, $bootstrapExempt, true)) {
                $this->assertStringNotContainsString(
                    'setRequiredCapability',
                    $source,
                    sprintf(
                        '%s is exempt because it runs before any user exists. Now that it '
                        . 'declares a capability, drop the exemption.',
                        $name
                    )
                );
                continue;
            }

            $this->assertStringContainsString(
                'setRequiredCapability',
                $source,
                sprintf(
                    '%s requires a nonce but declares no capability, so the authentication '
                    . 'gate is skipped and it can be reached without signing in.',
                    $name
                )
            );
        }
    }

    /** No report/view controller may require a nonce, or it loses 'go' silently. */
    public function testNoReportControllerRequiresANonce()
    {
        foreach (glob(__DIR__ . '/../modules/*/Controller/*.php') as $file) {
            $name = basename($file, '.php');

            if (!preg_match('/(Report|Dashboard|View)/', $name)) {
                continue;
            }

            /*
             * ...unless it is a WRITE that acts on one. CustomReportSave and
             * CustomReportDelete carry "Report" because that is what they write
             * to, not because they display anything; the check above is about
             * screens that SHOW a report, and those have no business requiring
             * a nonce.
             */
            if (preg_match(self::WRITE_VERBS, $name)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'setNonceRequired',
                (string) file_get_contents($file),
                sprintf('%s is a read-only screen and must not require a nonce', $name)
            );
        }
    }
}
