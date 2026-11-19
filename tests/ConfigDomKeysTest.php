<?php

use PHPUnit\Framework\TestCase;

/**
 * Every key config_dom.php writes onto OWA.config is one the reporting
 * JavaScript reads, under the same name.
 */
final class ConfigDomKeysTest extends TestCase
{
    public function testEveryKeyWrittenIsRead(): void
    {
        $root = \OWA\Core\Env::root();
        $dom  = (string) file_get_contents($root . '/modules/Base/templates/config_dom.php');

        preg_match_all('/^OWA\.config\.(\w+)\s*=/m', $dom, $m);

        $this->assertNotEmpty($m[1], 'no keys found, so this proves nothing');

        $js = '';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/modules/Base/src'));

        foreach ($files as $file) {
            if ($file->getExtension() === 'js') {
                $js .= file_get_contents($file->getPathname());
            }
        }

        $unread = [];

        foreach ($m[1] as $key) {
            $q = preg_quote($key, '/');
            if (! preg_match('/config\.' . $q . '\b|config\[\s*[\'"]' . $q . '[\'"]\s*\]|getSetting\(\s*[\'"]' . $q . '[\'"]/', $js)) {
                $unread[] = $key;
            }
        }

        $this->assertSame([], $unread, 'OWA.config keys config_dom.php writes and nothing reads');
    }
}
