<?php

use PHPUnit\Framework\TestCase;

/**
 * public/ is granted by a .htaccess of its own, not only by the root's <If>.
 *
 * The root .htaccess denies everything and grants public/ back with an <If>
 * expression. A server that reads Require but not <If> -- reported on shared
 * hosting running LiteSpeed (#1401) -- denied every stylesheet and script, so
 * the pages loaded with no styles. public/ is otherwise build output and
 * ignored, so this file has to stay tracked and in the release tarball.
 */
final class PublicHtaccessTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testPublicGrantsItselfWithoutAnExpression(): void
    {
        $file = self::ROOT . '/public/.htaccess';

        $this->assertFileExists($file);

        $rules = preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($file));

        $this->assertMatchesRegularExpression('/<IfModule mod_authz_core\.c>\s*Require all granted\s*<\/IfModule>/', $rules);
        $this->assertStringNotContainsString('<If ', $rules);
    }

    public function testItIsTrackedThoughTheRestOfPublicIsNot(): void
    {
        if (!is_dir(self::ROOT . '/.git') && !is_file(self::ROOT . '/.git')) {
            $this->markTestSkipped('not a git checkout');
        }

        $ignored = function (string $path): bool {
            exec('git -C ' . escapeshellarg(self::ROOT) . ' check-ignore -q ' . escapeshellarg($path), $out, $code);
            return $code === 0;
        };

        $this->assertFalse($ignored('public/.htaccess'), 'public/.htaccess must be committed');
        $this->assertTrue($ignored('public/base/css/owa.css'), 'the built assets stay ignored');
    }

    public function testTheReleaseTarballKeepsIt(): void
    {
        $workflow = (string) file_get_contents(self::ROOT . '/.github/workflows/main.yml');

        $this->assertDoesNotMatchRegularExpression("#--exclude='\\./public#", $workflow);
        $this->assertDoesNotMatchRegularExpression("#--exclude='[^']*htaccess#", $workflow);
    }
}
