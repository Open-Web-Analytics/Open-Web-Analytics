<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The install wizard keeps its password field, and its admin step says when
 * the page is not served over HTTPS (Lib::isHttps()): shown, not blocking.
 */
final class InstallHttpWarningTest extends TestCase
{
    /** The wizard's admin step warns, without blocking, when its page is not served over HTTPS. */
    public function testTheWizardWarnsOfAPasswordSentWithoutHttps(): void
    {
        $saved = $_SERVER;

        try {
            unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_PORT']);
            $_SERVER['SERVER_PORT'] = 80;
            $http = $this->renderDefaultsStep();

            $_SERVER['HTTPS'] = 'on';
            $https = $this->renderDefaultsStep();
        } finally {
            $_SERVER = $saved;
        }

        $warning = 'This page is not served over HTTPS. The password you enter travels unencrypted.';
        $this->assertStringContainsString('data-ui="install:http-warning">' . $warning . '</span>', $http);
        $this->assertStringContainsString('name="password"', $http, 'the field stays');
        $this->assertStringNotContainsString($warning, $https);
        $this->assertStringContainsString('name="password"', $https);
    }

    private function renderDefaultsStep(): string
    {
        $t = new \OWA\Core\Template('base');
        $t->set('defaults', []);
        $t->set_template('install_defaults_entry.php');

        return $t->fetch();
    }
}
