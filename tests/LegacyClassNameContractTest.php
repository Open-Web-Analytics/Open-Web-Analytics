<?php

use PHPUnit\Framework\TestCase;

/**
 * "Every legacy class name still resolves" contract test — net (c) of the
 * namespace-migration safety net (Phase 6, stage 0).
 *
 * WHY THIS EXISTS
 * ---------------
 * The migration's backward-compat promise is: every `owa_*` class name that
 * exists today keeps resolving for a full major-version deprecation window,
 * whether it is still a directly-declared class or has become a forward
 * `class_alias` pointing at its new namespaced name. Third-party plugins,
 * integrations, and serialized state reference these names; silently dropping
 * one is a breaking change.
 *
 * This test freezes the complete set of legacy class/interface/trait names
 * (captured from the untouched tree into tests/fixtures/legacy_class_names.json
 * BEFORE any rename) and asserts that after a full framework boot every one
 * still resolves. At stage 0 it is a tautology. The moment a rename lands
 * without its forward alias, this test goes red — which is the whole point:
 * net (a) reads its expectation from the (renamed) file and net (b) only covers
 * registered dotted-ids, so ONLY this frozen snapshot catches a dropped alias.
 *
 * Maintenance contract: this fixture is APPEND-mostly. A name may be added when
 * new classes ship. A name may only be REMOVED when its deprecation window has
 * elapsed and the alias is intentionally retired — never to make the test pass.
 */
final class LegacyClassNameContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Full boot so directly-declared classes are loadable on demand and any
        // eager forward-alias file (owa_compat_aliases.php, once it exists) has
        // run.
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    public function testEveryLegacyClassNameStillResolves(): void
    {
        $names = $this->legacyNames();

        $this->assertGreaterThan(
            400,
            count($names),
            'Legacy class-name snapshot looks truncated; expected the full '
            . '~406-name set frozen at stage 0.'
        );

        $missing = [];
        foreach ($names as $name) {
            // class_exists() with autoload enabled resolves both a
            // still-declared class AND a forward class_alias. That is exactly
            // the two ways a legacy name is allowed to keep working.
            if (
                !class_exists($name)
                && !interface_exists($name)
                && !trait_exists($name)
            ) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Legacy class name(s) no longer resolve — a rename dropped its "
            . "forward class_alias (backward-compat break):\n"
            . implode("\n", $missing)
        );
    }

    /** @return string[] */
    private function legacyNames(): array
    {
        $path = __DIR__ . '/fixtures/legacy_class_names.json';
        $this->assertFileExists($path, 'Legacy class-name snapshot fixture is missing.');

        $names = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($names, 'Legacy class-name snapshot is not valid JSON.');

        return $names;
    }
}
