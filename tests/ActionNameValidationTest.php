<?php

use PHPUnit\Framework\TestCase;

/**
 * The action name reaches a filesystem path, so it must be a bare identifier.
 *
 * owa_do / owa_action arrives on the request and, when the action is NOT in the
 * action registry, is split on '.' and both halves are used to build the path
 * that is then require_once'd -- moduleRequireOnce() builds
 * modules/<dir>/<file>.php and Lib::factory() builds <dir>/owa_<file><suffix>.php.
 *
 * These assert the positive match in moduleFactory(), which requires each half
 * to be a bare identifier.
 *
 * The cases below are simply strings that are not bare identifiers -- quotes,
 * comment sequences, separators, whitespace, a null byte, a missing segment.
 * None of them can name a class or a file, so all of them must be refused before
 * either is constructed.
 */
final class ActionNameValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** Action names that must be refused before they touch a path. */
    public static function hostileActions(): array
    {
        return [
            'quote and comment'         => ["base.reportContent' ORDER BY 1-- -"],
            'parens and punctuation'    => ['base.report CONCAT(1,2) name'],
            'inline comment sequence'   => ["base.')/**/AND/**/('a'='a"],
            'traversal: parent'         => ['base.../../../../etc/passwd'],
            'traversal: encoded-ish'    => ['base.....//....//etc/passwd'],
            'traversal: absolute'       => ['base./etc/passwd'],
            'null byte'                 => ["base.reportContent\0.png"],
            'separator in module'       => ['../base.reportContent'],
            'whitespace'                => ['base.report Content'],
            'empty action segment'      => ['base.'],
        ];
    }

    /**
     * @dataProvider hostileActions
     */
    public function testHostileActionNamesAreRefused(string $action): void
    {
        $this->expectException(\Exception::class);

        \OWA\Core\CoreAPI::moduleFactory($action, 'Controller', array());
    }

    public function testAnActionWithNoSeparatorIsRefused(): void
    {
        // explode() yields a single element, so the action half is null. Before
        // the guard this produced an "Undefined array key 1" warning and then
        // fataled deeper in the factory.
        $this->expectException(\Exception::class);

        \OWA\Core\CoreAPI::moduleFactory('reportContent', 'Controller', array());
    }

    public function testALegitimateActionStillResolves(): void
    {
        // The guard must not break the legacy resolution path itself: this is
        // still how third-party module actions load.
        // Any action still implemented by a controller will do; dashboard used
        // to be one and is a report definition now.
        $controller = \OWA\Core\CoreAPI::moduleFactory('base.reportDomstreams', 'Controller', array());

        $this->assertIsObject($controller);
        $this->assertSame('base', $controller->module);
    }

    public function testUnderscoresAndDigitsAreAccepted(): void
    {
        // Real action names use both, so the character class must allow them.
        $this->assertSame(1, preg_match('/^[a-zA-Z0-9_]+$/', 'report_content2'));
        $this->assertSame(0, preg_match('/^[a-zA-Z0-9_]+$/', 'report/content'));
        $this->assertSame(0, preg_match('/^[a-zA-Z0-9_]+$/', 'report.content'));
    }
}
