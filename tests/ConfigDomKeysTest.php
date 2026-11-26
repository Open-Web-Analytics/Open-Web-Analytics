<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/PageConfig.php';

/**
 * Every key config_dom.php writes onto OWA.config is one the reporting
 * JavaScript reads, under the same name.
 */
final class ConfigDomKeysTest extends TestCase
{
    public function testEveryKeyWrittenIsRead(): void
    {
        $root = \OWA\Core\Env::root();
        $keys = array_keys(owa_test_page_config()['config']);

        $this->assertNotEmpty($keys, 'no keys found, so this proves nothing');

        $js = '';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/modules/Base/src'));

        foreach ($files as $file) {
            if ($file->getExtension() === 'js') {
                $js .= file_get_contents($file->getPathname());
            }
        }

        $unread = [];

        foreach ($keys as $key) {
            $q = preg_quote($key, '/');
            if (! preg_match('/config\.' . $q . '\b|config\[\s*[\'"]' . $q . '[\'"]\s*\]|getSetting\(\s*[\'"]' . $q . '[\'"]/', $js)) {
                $unread[] = $key;
            }
        }

        $this->assertSame([], $unread, 'OWA.config keys config_dom.php writes and nothing reads');
    }
}
