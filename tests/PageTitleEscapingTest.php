<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A page's title is printed escaped in <title>: a custom report's or a
 * visualization's name is its page title, and is whatever its author typed.
 */
final class PageTitleEscapingTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function wrappers(): array
    {
        return [
            'the admin wrapper'  => ['wrapper_default.php'],
            'the public wrapper' => ['wrapper_public.php'],
        ];
    }

    /** @dataProvider wrappers */
    public function testATitleWithMarkupIsEscaped(string $wrapper): void
    {
        $name = '</title><script>alert(1)</script>';

        $t = new \OWA\Core\Template();
        $t->set_template($wrapper);
        $t->set('page_title', $name);
        $t->set('body', '');

        $html = (string) $t->fetch();

        $this->assertSame(1, preg_match('~<title>(.*?)</title>~s', $html, $m), 'one <title>');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString($name, html_entity_decode($m[1], ENT_QUOTES));
    }
}
