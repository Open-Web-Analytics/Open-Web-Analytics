<?php

use OWA\Core\TemplateEngine;
use OWA\Core\ViewScope;
use PHPUnit\Framework\TestCase;

/**
 * Locks the template render contract introduced when OWA's own templates moved
 * off the extract() bare-variable convention onto an explicit $view scope.
 *
 * TWO CONTRACTS ARE PINNED HERE, and they pull in opposite directions:
 *
 *  1. The DEPRECATED bare-variable path must keep working. Template resolves
 *     templates from four roots (base, module, module-local, theme), so bare
 *     vars and $this are the contract for third-party module templates,
 *     site-owner templates/local/ overrides and custom themes -- none of which
 *     OWA ships or can migrate. Breaking extract() breaks those silently, with
 *     the same fatal-in-foreach the $view work exists to eliminate. Removal is
 *     a v2.0 task; until then this half of the test is the guard.
 *
 *  2. The NEW $view path must be strict about a key that was never set, and
 *     otherwise indistinguishable from an extracted local. That second half
 *     matters more than it looks: __isset has to match native isset() exactly
 *     (false for null, false for missing, NEVER throwing) because 54 isset()
 *     and 32 empty() call sites across the templates depend on it. A __isset
 *     that threw, or that reported true for a null value, would turn those into
 *     500s or silently flip their branches.
 *
 * Read together they say: migrated and un-migrated templates observe the same
 * values, and the only behavioral difference is that reading a never-set var
 * through $view raises instead of yielding false.
 *
 * The suite drives the real TemplateEngine::fetch() against temp template
 * files rather than unit-testing ViewScope in isolation -- fetch() is where
 * extract(), the $view construction order, and the include all interact, and
 * that interaction is the part that can regress.
 */
final class ViewScopeCompatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa_viewscope_' . getmypid() . '_' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    /** Render template source through the real fetch(), with the given view vars. */
    private function render(string $source, array $vars = []): string
    {
        $file = $this->dir . '/t_' . uniqid() . '.php';
        file_put_contents($file, $source);

        $t = new TemplateEngine();
        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }
        $t->file = $file;

        return $t->fetch();
    }

    // ---------------------------------------------------------------- contract 1
    // The deprecated bare-variable path (third-party / local / theme templates).

    public function testExtractStillPopulatesBareVariables(): void
    {
        $out = $this->render('<?php echo $headline; ?>', ['headline' => 'Hello']);

        $this->assertSame('Hello', $out, 'extract() must keep populating bare vars for un-migrated templates');
    }

    public function testBareVariableForeachStillWorks(): void
    {
        $out = $this->render('<?php foreach ($tabs as $tab) { echo $tab; } ?>', ['tabs' => ['a', 'b']]);

        $this->assertSame('ab', $out);
    }

    public function testThisStillResolvesToTheTemplateInsideATemplate(): void
    {
        // Templates are included from inside fetch(), so $this is in scope. 69 of
        // OWA's own templates used it before the migration and third-party ones
        // still do -- including property reads like $this->config.
        $out = $this->render('<?php echo get_class($this) . ":" . $this->vars["x"]; ?>', ['x' => 'ok']);

        $this->assertSame(TemplateEngine::class . ':ok', $out);
    }

    /**
     * fetch() renames its own locals because extract() defaults to
     * EXTR_OVERWRITE: a payload key called 'file' would otherwise overwrite the
     * include path mid-render and a 'contents' key would clobber the captured
     * output. Nothing sets those keys today, which is exactly why a regression
     * here would go unnoticed.
     */
    public function testPayloadKeyCannotClobberFetchInternals(): void
    {
        $out = $this->render(
            '<?php echo "rendered:" . $file . "|" . $contents; ?>',
            ['file' => 'PAYLOAD_FILE', 'contents' => 'PAYLOAD_CONTENTS']
        );

        $this->assertSame('rendered:PAYLOAD_FILE|PAYLOAD_CONTENTS', $out);
    }

    // ---------------------------------------------------------------- contract 2
    // The $view scope.

    public function testViewReadsAViewVar(): void
    {
        $out = $this->render('<?php echo $view->headline; ?>', ['headline' => 'Hello']);

        $this->assertSame('Hello', $out);
    }

    public function testViewSupportsArrayIndexingAndInterpolation(): void
    {
        $out = $this->render(
            '<?php echo "id={$view->site[\'site_id\']}"; ?>',
            ['site' => ['site_id' => 'abc123']]
        );

        $this->assertSame('id=abc123', $out);
    }

    /**
     * array_key_exists, not isset: a var deliberately set to null must read back
     * as null, exactly as an extracted local would -- not throw.
     */
    public function testViewReturnsNullForAVarSetToNull(): void
    {
        $out = $this->render('<?php var_export($view->maybe); ?>', ['maybe' => null]);

        $this->assertSame('NULL', $out);
    }

    public function testViewThrowsForAVarThatWasNeverSet(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessageMatches('/\$view->tabs/');

        $this->render('<?php echo $view->tabs; ?>');
    }

    /**
     * The whole point of the strictness: the pre-migration failure was a FATAL
     * inside foreach ("must be of type array|object, bool given") raised far from
     * the controller that forgot the key. Now it names the key.
     */
    public function testForeachOverANeverSetVarThrowsNamingTheKey(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessageMatches('/never set/');

        $this->render('<?php foreach ($view->tabs as $t) { echo $t; } ?>');
    }

    // --- isset()/empty() must be indistinguishable from an extracted local ---

    /** @dataProvider issetCases */
    public function testIssetOnViewMatchesIssetOnAnExtractedLocal(array $vars, string $expected): void
    {
        $bare = $this->render('<?php var_export(isset($probe)); ?>', $vars);
        $view = $this->render('<?php var_export(isset($view->probe)); ?>', $vars);

        $this->assertSame($expected, $view, 'isset($view->probe) should match native isset()');
        $this->assertSame($bare, $view, 'isset() must agree between the bare and $view paths');
    }

    /** @dataProvider issetCases */
    public function testEmptyOnViewMatchesEmptyOnAnExtractedLocal(array $vars, string $unused): void
    {
        $bare = $this->render('<?php var_export(empty($probe)); ?>', $vars);
        $view = $this->render('<?php var_export(empty($view->probe)); ?>', $vars);

        $this->assertSame($bare, $view, 'empty() must agree between the bare and $view paths');
    }

    public static function issetCases(): array
    {
        return [
            'set to a value' => [['probe' => 'x'], 'true'],
            'set to null'    => [['probe' => null], 'false'],
            'never set'      => [[], 'false'],
        ];
    }

    /**
     * isset()/empty() on a never-set var must NOT throw -- they are the guard
     * expression templates use precisely because a key may be absent.
     */
    public function testIssetOnANeverSetVarDoesNotThrow(): void
    {
        $out = $this->render('<?php var_export(isset($view->nope)); echo "|reached"; ?>');

        $this->assertSame('false|reached', $out);
    }

    // --- helper delegation, construction order, write protection ---

    public function testViewDelegatesMethodCallsToTheTemplate(): void
    {
        // set_template() is a real TemplateEngine method; reaching it through
        // $view proves __call forwards to the underlying template object.
        $out = $this->render('<?php $view->set_template("x.php"); echo $view->owaTemplate()->file; ?>');

        $this->assertStringEndsWith('x.php', $out);
    }

    /**
     * $view is built AFTER extract() so a payload key called 'view' cannot
     * replace the scope object and silently break every migrated template in
     * the file.
     */
    public function testPayloadKeyNamedViewCannotClobberTheScopeObject(): void
    {
        $out = $this->render('<?php echo get_class($view); ?>', ['view' => 'PAYLOAD']);

        $this->assertSame(ViewScope::class, $out);
    }

    /**
     * View data resolves from the template's vars ONLY -- no fallback to a real
     * property on the Template. The two are different things: a template's
     * $this->config is the Template's own config, not a view var of the same
     * name, and letting __get fall through to properties let a view var shadow
     * the property. That conflation shipped a broken installer once (an emptied
     * db_supported_types loop rendering <select> with no options), so the
     * absence of the fallback is pinned deliberately.
     */
    public function testViewDoesNotFallBackToTemplateProperties(): void
    {
        $this->expectException(OutOfBoundsException::class);

        // template_dir is a declared property on TemplateEngine, never a view var.
        $this->render('<?php echo $view->template_dir; ?>');
    }

    /**
     * A template that throws must not leak fetch()'s output buffer.
     *
     * Found by this suite on its first run: making __get throw turned a
     * previously-fatal condition into a catchable exception that unwinds out of
     * include(), so the ob_end_clean() after it never ran. Renders nest, so every
     * swallowed template error left output captured in a buffer nobody closed and
     * a later ob_get_contents() could return unrelated markup. fetch() now wraps
     * the include in try/finally; this asserts the buffer level is restored.
     */
    public function testAThrowingTemplateDoesNotLeakTheOutputBuffer(): void
    {
        $before = ob_get_level();

        try {
            $this->render('<?php echo $view->never_set_anywhere; ?>');
            $this->fail('expected the never-set read to throw');
        } catch (OutOfBoundsException) {
            // expected
        }

        $this->assertSame($before, ob_get_level(), 'fetch() must not leave an output buffer open when a template throws');
    }

    public function testAssigningThroughViewIsRejected(): void
    {
        $this->expectException(LogicException::class);

        $this->render('<?php $view->headline = "no"; ?>', ['headline' => 'yes']);
    }

    /**
     * Both paths must observe the SAME values in the same render -- this is what
     * makes the migration safe to do file-by-file rather than all at once, and
     * what let 28 templates stay on bare vars without behaving differently.
     */
    public function testBareAndViewPathsAgreeInTheSameRender(): void
    {
        $out = $this->render(
            '<?php echo ($headline === $view->headline) ? "agree" : "differ"; ?>',
            ['headline' => 'Hello']
        );

        $this->assertSame('agree', $out);
    }
}
