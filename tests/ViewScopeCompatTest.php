<?php

use OWA\Core\TemplateEngine;
use OWA\Core\ViewScope;
use PHPUnit\Framework\TestCase;

/**
 * Locks the template render contract: a template reads its data through $view,
 * and through nothing else.
 *
 * TWO CONTRACTS ARE PINNED HERE:
 *
 *  1. THE BARE-VARIABLE PATH IS GONE (v2.0). fetch() no longer extract()s the
 *     view vars, and it includes the template from a static closure, so a
 *     template sees neither a bare $headline nor $this. A template written for
 *     1.x fails on its first such read instead of rendering with values
 *     missing.
 *
 *  2. THE $view PATH is strict about a key that was never set, and otherwise
 *     behaves as the extracted locals did. __isset has to match native isset()
 *     exactly (false for null, false for missing, NEVER throwing) because the
 *     isset() and empty() call sites across the templates depend on it. A
 *     __isset that threw, or that reported true for a null value, would turn
 *     those into 500s or silently flip their branches.
 *
 * The suite drives the real TemplateEngine::fetch() against temp template
 * files rather than unit-testing ViewScope in isolation -- fetch() is where
 * the $view construction and the include interact, and that interaction is
 * the part that can regress.
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
    // No bare variables, no $this.

    public function testAViewVarIsNotABareVariable(): void
    {
        $out = $this->render('<?php var_export(isset($headline)); ?>', ['headline' => 'Hello']);

        $this->assertSame('false', $out, 'view vars are not extracted into the template scope');
    }

    public function testThisIsNotInScopeInsideATemplate(): void
    {
        $this->assertSame('false', $this->render('<?php var_export(isset($this)); ?>'));

        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/\$this/');

        $this->render('<?php echo $this->vars["x"]; ?>', ['x' => 'ok']);
    }

    /**
     * The template sees fetch()'s include path and $view, and none of fetch()'s
     * other locals, whatever the payload is called.
     */
    public function testATemplateSeesOnlyViewAndTheIncludePath(): void
    {
        $out = $this->render(
            '<?php $v = get_defined_vars(); ksort($v); echo implode(",", array_keys($v)); ?>',
            ['file' => 'PAYLOAD_FILE', 'contents' => 'PAYLOAD_CONTENTS']
        );

        $this->assertSame('__owa_template_file,view', $out);
    }

    /** A partial included by a template shares its scope: $view and its locals. */
    public function testAPartialSeesViewAndTheIncludersLocals(): void
    {
        $partial = $this->dir . '/partial_' . uniqid() . '.php';
        file_put_contents($partial, '<?php echo $row . ":" . $view->headline; ?>');

        $out = $this->render('<?php foreach (["a", "b"] as $row) { include ' . var_export($partial, true) . '; } ?>',
            ['headline' => 'H']);

        $this->assertSame('a:Hb:H', $out);
    }

    /** A template that has no $this still reaches the Template's own properties. */
    public function testATemplateReachesTheTemplatesPropertiesThroughView(): void
    {
        $out = $this->render('<?php echo get_class($view->owaTemplate()) . ":" . $view->owaTemplate()->vars["x"]; ?>',
            ['x' => 'ok']);

        $this->assertSame(TemplateEngine::class . ':ok', $out);
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

    // --- isset()/empty() match native isset()/empty() on a local ---

    /** @dataProvider issetCases */
    public function testIssetOnViewMatchesNativeIsset(array $vars, string $isset, string $empty): void
    {
        $this->assertSame($isset, $this->render('<?php var_export(isset($view->probe)); ?>', $vars));
    }

    /** @dataProvider issetCases */
    public function testEmptyOnViewMatchesNativeEmpty(array $vars, string $isset, string $empty): void
    {
        $this->assertSame($empty, $this->render('<?php var_export(empty($view->probe)); ?>', $vars));
    }

    public static function issetCases(): array
    {
        return [
            'set to a value' => [['probe' => 'x'], 'true', 'false'],
            'set to a falsy value' => [['probe' => '0'], 'true', 'true'],
            'set to null'    => [['probe' => null], 'false', 'true'],
            'never set'      => [[], 'false', 'true'],
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
     * A payload key called 'view' cannot replace the scope object and silently
     * break every template in the file.
     */
    public function testPayloadKeyNamedViewCannotClobberTheScopeObject(): void
    {
        $out = $this->render('<?php echo get_class($view); ?>', ['view' => 'PAYLOAD']);

        $this->assertSame(ViewScope::class, $out);
    }

    /**
     * View data resolves from the template's vars ONLY -- no fallback to a real
     * property on the Template. The two are different things: the Template's
     * own config (reached through $view->owaTemplate()) is not a view var of
     * the same name, and letting __get fall through to properties let a view var shadow
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

}
