<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * Update012 is the first update that needed the update mechanism to actually
 * work, so it is the first one worth testing directly.
 *
 * WHY THIS EXISTS
 * ---------------
 * UpdateDiscoveryTest proves an update can be FOUND and sequenced. It says
 * nothing about whether the update DOES the right thing. Update012 rewrites the
 * persisted configuration of every install that runs it, and the two claims it
 * rests on are both testable:
 *
 *   1. retargeting a dangling '.tpl' name to '.php' points it at a file that
 *      exists, instead of at one the migration deleted;
 *   2. pruning a persisted value that duplicates the code default is
 *      BEHAVIOUR-PRESERVING -- get() falls back to the very default the stored
 *      value was restating.
 *
 * Claim 2 is the one that matters. It is what makes the update safe to run
 * unattended on installs nobody is watching, and it is asserted here by
 * comparing effective get() values before and after, not by inspecting the row.
 *
 * These tests drive Update012::repair() rather than up(). up() ends in
 * $config->save(); repair() does the work and returns what it did, leaving the
 * caller to persist. That split exists precisely so this file can exercise the
 * logic against the SHARED config singleton without writing to the real
 * owa_configuration row -- see SettingsSingletonSnapshot for what leaking that
 * state has cost, twice.
 */
final class Update012Test extends TestCase
{
    use SettingsSingletonSnapshot;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
    }

    private function repair(): array
    {
        return \OWA\Module\Base\Update\Update012::repair( $this->settings() );
    }

    // ---- 1. dangling .tpl names -------------------------------------------

    public function testDanglingTplNameIsRetargetedToPhp(): void
    {
        $c = $this->settings();
        $c->db_settings = [ 'base' => [ 'some_template' => 'wrapper_default.tpl' ] ];

        $result = $this->repair();

        $this->assertSame(
            'wrapper_default.php',
            $c->db_settings['base']['some_template'],
            'a persisted .tpl name must be rewritten to the .php file the migration renamed it to'
        );
        $this->assertCount( 1, $result['retargeted'] );
        $this->assertStringContainsString( 'base.some_template', $result['retargeted'][0] );
    }

    /**
     * The retarget is a blind suffix rewrite, so it must be narrow. Anything
     * that merely CONTAINS 'tpl', or ends in some other extension, is not a
     * dangling template name and must be left exactly as stored.
     *
     * @dataProvider nonTemplateValues
     */
    public function testValuesThatDoNotEndInTplAreUntouched( $value, string $why ): void
    {
        $c = $this->settings();
        $c->db_settings = [ 'base' => [ 'k' => $value ] ];

        $result = $this->repair();

        $this->assertSame( $value, $c->db_settings['base']['k'], $why );
        $this->assertSame( [], $result['retargeted'] );
    }

    public static function nonTemplateValues(): array
    {
        return [
            'already migrated'   => [ 'wrapper_default.php', 'a .php name is already correct' ],
            'tpl in the middle'  => [ 'my.tpl.backup', 'only a trailing .tpl is a dangling template name' ],
            'tpl as a directory' => [ 'tpl/wrapper.php', 'a "tpl" path segment is not an extension' ],
            'bare word tpl'      => [ 'tpl', 'too short to carry the extension' ],
            'uppercase'          => [ 'WRAPPER.TPL', 'the migration produced lowercase names only' ],
        ];
    }

    /**
     * db_settings holds whatever a module persisted, including non-strings and
     * -- because nothing validates the shape -- module entries that are not
     * arrays at all. The scan must skip those rather than iterate them.
     *
     * The diagnostic is asserted directly instead of being left to PHPUnit: a
     * bare `foreach` over a string raises a PHP warning, not an exception, so
     * the update would complete and report success while PHPUnit's own
     * failOnWarning does not reliably fail on warnings raised inside
     * application code. Without this handler, deleting the guard is a silent
     * pass -- which is exactly what happened when this test was first written.
     */
    public function testNonArrayModuleEntriesAreSkippedWithoutWarning(): void
    {
        $c = $this->settings();
        $c->db_settings = [
            'base' => [
                'an_array' => [ 'nested' => 'x.tpl' ],
                'a_bool'   => true,
                'a_null'   => null,
                'an_int'   => 12,
            ],
            'not_even_an_array' => 'scalar',
        ];

        $diagnostics = [];

        set_error_handler( static function ( $no, $str ) use ( &$diagnostics ) {
            $diagnostics[] = $str;
            return true;
        } );

        try {
            $result = $this->repair();
        } finally {
            restore_error_handler();
        }

        $this->assertSame( [], $diagnostics,
            'the scan raised a PHP diagnostic on a malformed db_settings entry; '
            . 'it must skip what it cannot iterate' );

        $this->assertSame( [], $result['retargeted'] );
        $this->assertSame( [ 'nested' => 'x.tpl' ], $c->db_settings['base']['an_array'],
            'a .tpl nested inside a persisted array is out of scope, not a crash' );
        $this->assertSame( 'scalar', $c->db_settings['not_even_an_array'],
            'a module whose settings are not an array must be skipped, not indexed into' );
    }

    // ---- 2. redundant copies of code defaults -----------------------------

    /**
     * The core safety claim: pruning changes no effective value.
     *
     * Asserted through get(), which is what every caller in the codebase uses,
     * rather than by inspecting db_settings -- the point is not that the key
     * disappeared, it is that nothing could tell.
     */
    public function testPruningDoesNotChangeAnyEffectiveValue(): void
    {
        $c = $this->settings();

        $c->default_config = [ 'base' => [
            'resolve_hosts' => false,
            'log_robots'    => false,
            'notice_email'  => '',
            'real_setting'  => 'default_value',
        ] ];

        // How these look in the wild: the old GUI submitted form strings, so a
        // redundant copy of `false` is stored as '' or NULL, not as false.
        $c->db_settings = [ 'base' => [
            'resolve_hosts' => '',
            'log_robots'    => null,
            'notice_email'  => '',
            'real_setting'  => 'customised',
        ] ];

        $before = [];
        foreach ( array_keys( $c->default_config['base'] ) as $k ) {
            $before[ $k ] = $c->get( 'base', $k );
        }

        $result = $this->repair();

        foreach ( $before as $k => $was ) {
            $this->assertSame( $was, $c->get( 'base', $k ),
                sprintf( 'effective value of base.%s changed across the prune', $k ) );
        }

        $this->assertNotEmpty( $result['removed'], 'the redundant copies should have been pruned' );
        $this->assertSame( 'customised', $c->db_settings['base']['real_setting'],
            'a genuine customisation must survive' );
    }

    public function testGenuineCustomisationsAreKept(): void
    {
        $c = $this->settings();
        $c->default_config = [ 'base' => [ 'query_string_filters' => 'fbclid' ] ];
        $c->db_settings    = [ 'base' => [ 'query_string_filters' => 'foo, bar, foo1' ] ];

        $result = $this->repair();

        $this->assertSame( 'foo, bar, foo1', $c->db_settings['base']['query_string_filters'] );
        $this->assertSame( [], $result['removed'] );
    }

    /**
     * schema_version and install_complete have NO code default -- they are
     * database state, not configuration. If the prune ever dropped them the
     * install would look uninstalled and re-run every update from 1.
     *
     * This is the single most damaging thing this update could do, so it is
     * asserted against a db_settings set containing nothing else worth keeping.
     */
    public function testStructuralKeysWithNoDefaultAreNeverRemoved(): void
    {
        $c = $this->settings();
        $c->default_config = [ 'base' => [ 'resolve_hosts' => false ] ];
        $c->db_settings    = [ 'base' => [
            'schema_version'   => 12,
            'install_complete' => true,
            'configuration_id' => 1,
            'resolve_hosts'    => '',
        ] ];

        $this->repair();

        $this->assertSame( 12,   $c->db_settings['base']['schema_version'] );
        $this->assertTrue(       $c->db_settings['base']['install_complete'] );
        $this->assertSame( 1,    $c->db_settings['base']['configuration_id'] );
        $this->assertArrayNotHasKey( 'resolve_hosts', $c->db_settings['base'],
            'the one key that DID duplicate a default should still be gone' );
    }

    /**
     * A module the prune knows no defaults for is untouched. Third-party
     * modules persist settings this codebase has never seen; "no default" must
     * mean "leave it alone", not "matches nothing, therefore delete".
     */
    public function testSettingsOfUnknownModulesAreLeftAlone(): void
    {
        $c = $this->settings();
        $c->default_config = [ 'base' => [ 'resolve_hosts' => false ] ];
        $c->db_settings    = [ 'some_third_party_module' => [
            'resolve_hosts' => '',      // same key name as a base default
            'its_own_key'   => 'value',
        ] ];

        $result = $this->repair();

        $this->assertSame( [ 'resolve_hosts' => '', 'its_own_key' => 'value' ],
            $c->db_settings['some_third_party_module'],
            'a key name colliding with a base default must not be pruned from another module' );
        $this->assertSame( [], $result['removed'] );
    }

    // ---- 3. running it twice ----------------------------------------------

    /**
     * Updates get re-run: a failed save, a restored database, an admin clicking
     * twice. The second run must find nothing left to do rather than compound
     * the first (e.g. rewriting 'x.php' to 'x.pph', or reporting removals again).
     */
    public function testRepairIsIdempotent(): void
    {
        $c = $this->settings();
        $c->default_config = [ 'base' => [
            'resolve_hosts' => false,
            'wrapper'       => 'wrapper_default.php',
        ] ];
        $c->db_settings = [ 'base' => [
            'resolve_hosts' => '',
            'wrapper'       => 'custom_wrapper.tpl',
        ] ];

        $first = $this->repair();
        $this->assertNotEmpty( $first['retargeted'] );
        $this->assertNotEmpty( $first['removed'] );

        $after_first = $c->db_settings;

        $second = $this->repair();

        $this->assertSame( [], $second['retargeted'], 'nothing left to retarget on a second run' );
        $this->assertSame( [], $second['removed'],    'nothing left to prune on a second run' );
        $this->assertSame( $after_first, $c->db_settings, 'a second run must be a no-op' );
    }

    /**
     * A clean install reports an empty result, which is what up() turns into
     * "nothing to repair" -- and, importantly, into skipping the save().
     */
    public function testAlreadyCleanInstallReportsNothing(): void
    {
        $c = $this->settings();
        $c->default_config = [ 'base' => [ 'resolve_hosts' => false ] ];
        $c->db_settings    = [ 'base' => [ 'schema_version' => 12, 'resolve_hosts' => true ] ];

        $result = $this->repair();

        $this->assertSame( [], $result['retargeted'] );
        $this->assertSame( [], $result['removed'] );
    }

    // ---- 4. the two repairs do not interfere ------------------------------

    /**
     * Retargeting can turn a stored value into one that now EQUALS the code
     * default -- 'wrapper_default.tpl' becomes 'wrapper_default.php', which is
     * the default. This is the exact case the .tpl migration could not reach.
     *
     * The redundant copy must not survive, and it does not. Note WHICH stage
     * drops it: not the prune, but persistSetting()'s own default-equivalence
     * guard, which the retarget writes through. The prune therefore finds
     * nothing left and reports no removal -- so assert the end state, not the
     * stage. (The retarget still reports it, which slightly overstates what
     * happened: the value was dropped rather than rewritten. Cosmetic, and it
     * only ever appears in an update notice.)
     */
    public function testARetargetThatLandsOnTheDefaultDoesNotStayPersisted(): void
    {
        $c = $this->settings();
        $c->default_config = [ 'base' => [ 'report_wrapper' => 'wrapper_default.php' ] ];
        $c->db_settings    = [ 'base' => [ 'report_wrapper' => 'wrapper_default.tpl' ] ];

        $result = $this->repair();

        $this->assertCount( 1, $result['retargeted'] );
        $this->assertArrayNotHasKey( 'report_wrapper', $c->db_settings['base'],
            'a value that now merely restates the default must not remain persisted' );
        $this->assertSame( 'wrapper_default.php', $c->get( 'base', 'report_wrapper' ),
            'and the effective value is still correct, supplied by the code default' );
    }

    /**
     * The counterpart: a retarget that lands on a value DIFFERENT from the
     * default is a genuine customisation and must be stored, not dropped by the
     * guard above.
     */
    public function testARetargetThatLandsOffTheDefaultIsPersisted(): void
    {
        $c = $this->settings();
        $c->default_config = [ 'base' => [ 'report_wrapper' => 'wrapper_default.php' ] ];
        $c->db_settings    = [ 'base' => [ 'report_wrapper' => 'my_custom_wrapper.tpl' ] ];

        $this->repair();

        $this->assertSame( 'my_custom_wrapper.php', $c->db_settings['base']['report_wrapper'] );
        $this->assertSame( 'my_custom_wrapper.php', $c->get( 'base', 'report_wrapper' ) );
    }

    // ---- 5. reversibility --------------------------------------------------

    /**
     * down() cannot restore what was removed, and does not pretend to. It must
     * still succeed, or a rollback would leave the schema version stranded.
     */
    public function testDownSucceedsWithoutRestoringAnything(): void
    {
        $update = owa_coreAPI::updateFactory( 'base', 'Update012' );

        $c = $this->settings();
        $c->db_settings = [ 'base' => [ 'resolve_hosts' => true ] ];

        $this->assertTrue( $update->down() );
        $this->assertSame( [ 'base' => [ 'resolve_hosts' => true ] ], $c->db_settings,
            'down() must not mutate configuration' );
    }
}
