<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The settings catalogue: what is declared, what gets looked up, and what
 * renders.
 *
 * Three levels, each naming its contents rather than its container. A page
 * names its fieldsets, a fieldset names its settings, and a setting names
 * nothing -- so ordering is array order, and a setting appears on a screen
 * because something lists it rather than because it carries a label.
 *
 * The property that decides cost is `storable`. Most settings are static code
 * constants that will never have a stored value, and going to the database to
 * discover an absence the declaration already guarantees is the waste this
 * exists to avoid.
 */
final class SettingsRegistryTest extends TestCase
{
    private const MODULE = 'zz_registry_test';

    /** @var array<string,mixed> the singleton's registry state before this test */
    private array $snapshot = array();

    /**
     * These tests register probe fields and fieldsets into the SINGLETON,
     * because that is the object the code under test consults. Left behind,
     * they are then real entries as far as anything else is concerned --
     * fieldSetProblems() reported three of them to a later test in the same
     * process, which passed or failed on file order alone.
     *
     * Snapshot and restore rather than delete: there is no deregistration API,
     * and there should not be one just so a test can tidy up.
     */
    protected function setUp(): void
    {
        $this->snapshot = array();

        foreach ( array( 'registry', 'fieldsets' ) as $property ) {

            $p = new ReflectionProperty( \OWA\Module\Base\Classes\Settings::class, $property );
            $p->setAccessible( true );

            $this->snapshot[ $property ] = $p->getValue( $this->settings() );
        }
    }

    protected function tearDown(): void
    {
        foreach ( $this->snapshot as $property => $value ) {

            $p = new ReflectionProperty( \OWA\Module\Base\Classes\Settings::class, $property );
            $p->setAccessible( true );
            $p->setValue( $this->settings(), $value );
        }
    }

    private function settings()
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    private function requireDb(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'resolution is a query that does or does not happen' );
        }
    }

    /**
     * A static setting is never queried, however many times it is read.
     *
     * This is the whole reason `storable` exists rather than every registered
     * key being looked up: base alone declares over a hundred settings that
     * nothing can persist.
     */
    public function testAStaticSettingIsNeverQueried(): void
    {
        $this->requireDb();

        $c  = $this->settings();
        $db = \OWA\Core\CoreAPI::dbSingleton();

        for ( $i = 0; $i < 50; $i++ ) {
            $c->registerField( self::MODULE, 'static_' . $i, array( 'default' => $i ) );
        }

        $before = (int) $db->num_queries;

        for ( $i = 0; $i < 50; $i++ ) {
            $this->assertSame( $i, \OWA\Core\CoreAPI::getSetting( self::MODULE, 'static_' . $i ) );
        }

        $this->assertSame( 0, (int) $db->num_queries - $before,
            'a setting with no way to persist a value must not be looked up' );
    }

    /**
     * Storable settings resolve together, in one query, and only once.
     *
     * Per key would make registration cost a query per declaration. The batch
     * does not enumerate them either -- the rows not already in hand are the
     * answer, and there are only ever as many as someone actually stored.
     */
    public function testStorableSettingsResolveInOneQueryForAllOfThem(): void
    {
        $this->requireDb();

        $c  = $this->settings();
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( array( 'a', 'b', 'c', 'd' ) as $key ) {
            $c->registerField( self::MODULE, 'stored_' . $key,
                array( 'default' => 'default-' . $key, 'storable' => true ) );
        }

        $before = (int) $db->num_queries;
        \OWA\Core\CoreAPI::getSetting( self::MODULE, 'stored_a' );
        $first = (int) $db->num_queries - $before;

        $before = (int) $db->num_queries;
        foreach ( array( 'b', 'c', 'd' ) as $key ) {
            \OWA\Core\CoreAPI::getSetting( self::MODULE, 'stored_' . $key );
        }
        $rest = (int) $db->num_queries - $before;

        $this->assertSame( 1, $first, 'the first storable read resolves every pending key' );
        $this->assertSame( 0, $rest,  'so the rest are already in hand' );
    }

    /** A key with no row keeps its default, and is not asked about twice. */
    public function testAStorableSettingWithNoRowKeepsItsDefault(): void
    {
        $this->requireDb();

        $c  = $this->settings();
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $c->registerField( self::MODULE, 'never_stored',
            array( 'default' => 'the-default', 'storable' => true ) );

        $this->assertSame( 'the-default',
            \OWA\Core\CoreAPI::getSetting( self::MODULE, 'never_stored' ) );

        $before = (int) $db->num_queries;
        \OWA\Core\CoreAPI::getSetting( self::MODULE, 'never_stored' );

        $this->assertSame( 0, (int) $db->num_queries - $before,
            '"nothing is stored" is an answer; recording it is what stops the next read asking' );
    }

    /** autoload implies storable: declaring boot needs it means a value can exist. */
    public function testAutoloadImpliesStorable(): void
    {
        $c = $this->settings();

        $c->registerField( self::MODULE, 'eager_only', array( 'autoload' => true ) );

        $eager = $c->eagerSettings();

        $this->assertContains( 'eager_only', $eager[ self::MODULE ] ?? array(),
            'a setting boot must fetch is by definition one that can have a value' );
    }

    /** A declared falsy default is a default; an omitted one is not. */
    public function testAnOmittedDefaultIsNotTheSameAsAFalsyOne(): void
    {
        $c = $this->settings();

        $c->registerField( self::MODULE, 'has_false', array( 'default' => false ) );
        $c->registerField( self::MODULE, 'has_none',  array( 'autoload' => true ) );

        $this->assertArrayHasKey( 'has_false', $c->default_config[ self::MODULE ],
            'false is a legitimate default and must survive registration' );
        $this->assertFalse( $c->default_config[ self::MODULE ]['has_false'] );

        $this->assertArrayNotHasKey( 'has_none', $c->default_config[ self::MODULE ] ?? array(),
            'omitting the key is how a setting says it has no default -- which is how '
          . 'is_active and schema_version stay out of reach of the prune' );
    }

    /**
     * A fieldset that cannot work says so.
     *
     * Both of these fail silently otherwise: a fieldset naming a setting nobody
     * registered renders an empty row, and one naming a setting that is not
     * storable renders a form whose save discards the value.
     */
    public function testAFieldSetReportsSettingsItCannotRender(): void
    {
        $c = $this->settings();

        $c->registerField( self::MODULE, 'ok_field',
            array( 'default' => '', 'storable' => true, 'type' => 'text', 'label' => 'OK' ) );
        $c->registerField( self::MODULE, 'static_field', array( 'default' => 'x' ) );

        $c->registerFieldSet( array(
            'id'       => self::MODULE . '.set',
            'module'   => self::MODULE,
            'legend'   => 'A Set',
            'settings' => array( 'ok_field', 'static_field', 'no_such_field' ),
        ) );

        $problems = implode( "\n", $c->fieldSetProblems() );

        $this->assertStringNotContainsString( 'ok_field', $problems );
        $this->assertStringContainsString( 'static_field', $problems,
            'rendering a non-storable setting is a form that saves nothing' );
        $this->assertStringContainsString( 'no_such_field', $problems,
            'rendering an unregistered setting is an empty row' );
    }

    /** The fieldset keeps the order it was given. */
    public function testAFieldSetRendersInTheOrderItDeclares(): void
    {
        $c = $this->settings();

        $c->registerFieldSet( array(
            'id'       => self::MODULE . '.ordered',
            'module'   => self::MODULE,
            'settings' => array( 'third', 'first', 'second' ),
        ) );

        $this->assertSame( array( 'third', 'first', 'second' ),
            $c->registeredFieldSets()[ self::MODULE . '.ordered' ]['settings'],
            'order is array order; there are no order numbers to reconcile' );
    }

    /**
     * Nav placement: section, order and capability.
     *
     * All three were declared and ignored. Every registered page was forced
     * under Instance, appeared in whatever order its module registered, and was
     * shown to anyone with edit_settings whatever capability it asked for.
     */
    public function testRegisteredPagesAreBucketedOrderedAndCapabilityChecked(): void
    {
        $sections = \OWA\Core\Controller::settingsNavSections( array(
            'General' => array(
                array( 'do' => 'x.second', 'anchortext' => 'Second', 'group' => 'General', 'order' => 2 ),
                array( 'do' => 'x.first',  'anchortext' => 'First',  'group' => 'General', 'order' => 1,
                       'capability' => 'edit_users' ),
            ),
            'Property' => array(
                array( 'do' => 'x.prop', 'anchortext' => 'Per Property', 'section' => 'Property' ),
            ),
        ) );

        $this->assertSame( array( 'First', 'Second' ),
            array_column( $sections['Instance'], 'label' ),
            'order decides position, not the order modules happened to register' );

        $this->assertSame( 'edit_users', $sections['Instance'][0]['capability'],
            'a page asking for a stricter capability must keep it' );

        $this->assertArrayHasKey( 'Property', $sections,
            'a module must be able to put a settings page on a tier other than Instance' );
        $this->assertSame( 'Per Property', $sections['Property'][0]['label'] );
    }

    /**
     * A setting is stored at the levels it declared, and nowhere else.
     *
     * Refused at the write rather than filtered at the read: a row sitting at a
     * scope nothing inherits from is a value that looks saved and never takes
     * effect, which is worse than a refusal the caller can see.
     */
    public function testAWriteOutsideTheDeclaredScopesIsRefused(): void
    {
        $this->requireDb();

        $c = $this->settings();

        $c->registerField( self::MODULE, 'install_only',
            array( 'default' => 'd', 'storable' => true ) );

        $this->assertSame( array( 'install' ), $c->scopesFor( self::MODULE, 'install_only' ),
            'install-only unless a declaration says otherwise -- a setting that has not '
          . 'thought about the hierarchy must not silently acquire overrides' );

        $written = \OWA\Core\CoreAPI::setScopedSetting(
            'profile', 'zz-site', self::MODULE, 'install_only', 'nope' );

        $this->assertFalse( $written, 'the write is refused' );

        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', 'zz-site', self::MODULE, 'install_only' ),
            'and nothing is stored' );
    }

    /** A setting that declares wider scopes can be written at them. */
    public function testAWriteWithinTheDeclaredScopesIsStored(): void
    {
        $this->requireDb();

        $c = $this->settings();

        $c->registerField( self::MODULE, 'overridable', array(
            'default'  => 'd',
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
        ) );

        $this->assertTrue( (bool) \OWA\Core\CoreAPI::setScopedSetting(
            'profile', 'zz-site', self::MODULE, 'overridable', 'yes' ) );

        $this->assertSame( 'yes',
            \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', 'zz-site', self::MODULE, 'overridable' ) );

        $this->assertFalse( $c->mayWriteAtScope( self::MODULE, 'overridable', 'organization' ),
            'and a level it did NOT declare is still refused' );

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', 'zz-site', self::MODULE, 'overridable' );
    }

    /**
     * An unregistered setting is unconstrained, and that is load-bearing.
     *
     * Base has not declared yet, and eight of its settings live at profile
     * scope right now -- default_page, domain_aliases, query_string_filters,
     * p3p_policy, enableEcommerceReporting, goals, goal_groups and
     * v2_raw_collection. Reading "nothing declared it" as "install only" would
     * refuse every write the Observation Settings screen makes.
     */
    public function testAnUndeclaredSettingIsUnconstrained(): void
    {
        $this->requireDb();

        $c = $this->settings();

        $this->assertNull( $c->scopesFor( self::MODULE, 'never_declared' ),
            'null means UNKNOWN, not "no scopes"' );

        $this->assertTrue( $c->mayWriteAtScope( self::MODULE, 'never_declared', 'profile' ) );

        $this->assertTrue( (bool) \OWA\Core\CoreAPI::setScopedSetting(
            'profile', 'zz-site', self::MODULE, 'never_declared', 'still works' ) );

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', 'zz-site', self::MODULE, 'never_declared' );
    }

    /**
     * Nothing already stored at a scope may become unwritable there.
     *
     * The trap this guards is base's adoption. Seven base settings live at
     * profile scope right now and none of them is declared, so they pass the
     * check by being unknown. The day base ships a declaration file,
     * `default_page` without a `scopes` key becomes install-only and the
     * Observation Settings screen silently stops saving -- the form posts, the
     * write is refused, the page redirects, and the value is simply not there.
     *
     * Derived from what is actually stored rather than a list written here, so
     * it keeps covering whatever an installation has, including settings added
     * after this was written.
     */
    public function testNothingStoredAtAScopeBecomesUnwritableThere(): void
    {
        $this->requireDb();

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $rows = (array) $db->get_results( sprintf(
            "SELECT DISTINCT scope_type, module, name FROM %s WHERE scope_type <> 'install'",
            $entity->getTableName() ) );

        if ( ! $rows ) {
            $this->markTestSkipped( 'no scoped settings stored on this installation' );
        }

        $c = $this->settings();

        foreach ( $rows as $row ) {

            $this->assertTrue(
                $c->mayWriteAtScope( $row['module'], $row['name'], $row['scope_type'] ),
                sprintf( '%s.%s is stored at %s scope but declares %s -- the screen that '
                       . 'writes it would silently stop saving',
                    $row['module'], $row['name'], $row['scope_type'],
                    implode( ', ', (array) $c->scopesFor( $row['module'], $row['name'] ) ) ) );
        }
    }

    /**
     * A setting nothing would read back must not be written.
     *
     * A static setting is never queried -- that is what not declaring
     * `storable` means -- so persisting one writes a durable row nothing will
     * ever look at. Demonstrated across two processes before this existed: the
     * row was in the table, save() reported success, and getSetting answered
     * with the default. A write that succeeds and has no effect is worse than
     * one that fails.
     */
    public function testPersistingAStaticSettingIsRefused(): void
    {
        $c = $this->settings();

        $c->registerField( self::MODULE, 'static_one', array( 'default' => 'the-default' ) );

        $this->assertFalse( $c->mayPersistInstallWide( self::MODULE, 'static_one' ) );

        $c->persistSetting( self::MODULE, 'static_one', 'i-was-saved' );

        $this->assertArrayNotHasKey( 'static_one', $c->db_settings[ self::MODULE ] ?? array(),
            'nothing is queued for the next save' );
        $this->assertSame( 'the-default',
            \OWA\Core\CoreAPI::getSetting( self::MODULE, 'static_one' ),
            'and the in-memory value is untouched too' );
    }

    /** Nor may one be stored at a level its declaration excludes. */
    public function testPersistingInstallWideIsRefusedWhenInstallIsNotADeclaredScope(): void
    {
        $c = $this->settings();

        $c->registerField( self::MODULE, 'profile_only', array(
            'default'  => 'd',
            'storable' => true,
            'scopes'   => array( 'profile' ),
        ) );

        $this->assertFalse( $c->mayPersistInstallWide( self::MODULE, 'profile_only' ),
            'storing it install-wide would put it at a level nothing resolves from' );

        $c->persistSetting( self::MODULE, 'profile_only', 'nope' );

        $this->assertArrayNotHasKey( 'profile_only', $c->db_settings[ self::MODULE ] ?? array() );
    }

    /** Storable, install-scoped and unregistered settings all still persist. */
    public function testOrdinaryInstallWideWritesStillWork(): void
    {
        $c = $this->settings();

        $c->registerField( self::MODULE, 'normal', array( 'default' => 'd', 'storable' => true ) );

        $c->persistSetting( self::MODULE, 'normal', 'stored' );
        $this->assertSame( 'stored', $c->db_settings[ self::MODULE ]['normal'] ?? null );

        // Unregistered: base has not declared, and OptionsUpdate writes its
        // keys through this path.
        $this->assertTrue( $c->mayPersistInstallWide( self::MODULE, 'nobody_declared_me' ) );

        // Mechanical settings are storable via autoload, so module activation
        // and the schema-version stamp keep working.
        $this->assertTrue( $c->mayPersistInstallWide( 'maxmind_geoip', 'is_active' ) );
        $this->assertTrue( $c->mayPersistInstallWide( 'maxmind_geoip', 'schema_version' ) );

        unset( $c->db_settings[ self::MODULE ] );
    }

    /**
     * A value set in memory wins, and costs no query.
     *
     * get() consults the pending map BEFORE it reads the array, so a key not
     * yet resolved would be fetched on the next read and the stored row would
     * overwrite what was just set. persistSetting() sets before it queues the
     * write, so this was live: saving a new MaxMind licence key and
     * redisplaying it returned the OLD key, and re-saving what was displayed
     * would have discarded the new one.
     */
    public function testAValueSetInMemoryIsNotOverwrittenByALaterResolve(): void
    {
        $this->requireDb();

        $c  = $this->settings();
        $db = \OWA\Core\CoreAPI::dbSingleton();

        // Storable and not eager, so it starts out pending and unresolved.
        $c->registerField( self::MODULE, 'pending_key',
            array( 'default' => 'the-default', 'storable' => true ) );

        $before = (int) $db->num_queries;

        $c->set( self::MODULE, 'pending_key', 'set-in-memory' );

        $this->assertSame( 'set-in-memory',
            \OWA\Core\CoreAPI::getSetting( self::MODULE, 'pending_key' ),
            'the stored value must not replace what was just set' );

        $this->assertSame( 0, (int) $db->num_queries - $before,
            'and a key already answered from memory needs no trip to the store' );
    }

    /** persistSetting() sets first, so the same holds for it. */
    public function testPersistSettingMakesTheNewValueReadableImmediately(): void
    {
        $this->requireDb();

        $c = $this->settings();

        $c->registerField( self::MODULE, 'persist_key',
            array( 'default' => 'the-default', 'storable' => true ) );

        $c->persistSetting( self::MODULE, 'persist_key', 'written' );

        $this->assertSame( 'written',
            \OWA\Core\CoreAPI::getSetting( self::MODULE, 'persist_key' ),
            'a screen that saves and then redisplays must see what it saved' );

        unset( $c->db_settings[ self::MODULE ] );
    }

    /**
     * A module that has not opted in keeps having ALL its rows loaded at boot.
     *
     * This is the adoption path, and the reason registration could land without
     * converting anything. The boot query ends with `module NOT IN (declared)`,
     * so a module with no settings.php behaves exactly as it did before any of
     * this existed -- its stored overrides arrive eagerly, in the one query,
     * and nothing about it is lazy.
     *
     * If this breaks, every un-migrated module silently drops to a per-key
     * lookup, which is slower in a way nothing would report.
     */
    public function testAnUndeclaredModulesOverridesAreLoadedAtBoot(): void
    {
        $this->requireDb();

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        /*
         * The row is CREATED here rather than looked for.
         *
         * Every module in this repository declares now, so on a healthy install
         * there is no undeclared module left to observe -- and a test that
         * skips when the clause is unexercised is exactly the test that stops
         * noticing when the clause breaks. A third-party module is the case
         * this protects, so the test fabricates one.
         */
        $module = 'third_party_probe_' . substr( md5( uniqid( '', true ) ), 0, 8 );

        $this->assertArrayNotHasKey( $module,
            (array) ( \OWA\Core\Module::settingsRegistry()['declared'] ?? array() ) );

        $db->query( sprintf(
            "INSERT INTO %s ( id, scope_type, scope_id, module, name, value )"
          . " VALUES ( %d, 'install', '1', '%s', 'some_option', '%s' )",
            $entity->getTableName(),
            crc32( $module ),
            $db->prepare( $module ),
            $db->prepare( serialize( 'stored-by-a-module-that-never-declared' ) ) ) );

        try {

            \OWA\Core\CoreAPI::configSingleton()->load( 1 );

            $before = (int) $db->num_queries;

            $value = \OWA\Core\CoreAPI::getSetting( $module, 'some_option' );

            $this->assertSame( 0, (int) $db->num_queries - $before,
                'reading a stored setting of a module that has not opted in must cost '
              . 'nothing: boot already fetched it' );

            $this->assertSame( 'stored-by-a-module-that-never-declared', $value,
                'the wholesale clause must actually deliver the value, not just avoid a query' );

        } finally {

            $db->query( sprintf( "DELETE FROM %s WHERE module = '%s'",
                $entity->getTableName(), $db->prepare( $module ) ) );

            \OWA\Core\CoreAPI::configSingleton()->load( 1 );
        }
    }

    /**
     * Core ADDS the mechanical settings, it does not merely decorate them.
     *
     * An earlier version only applied them to keys a module's file already
     * listed, so a module that did not mention is_active had none in the
     * registry -- and every installation then reported its own stored
     * is_active and schema_version as values nothing declares. Caught by
     * declarationProblems() on its first run.
     */
    public function testCoreAddsTheMechanicalSettingsToEveryDeclaredModule(): void
    {
        $registry = \OWA\Core\Module::settingsRegistry();

        foreach ( array_keys( (array) $registry['declared'] ) as $module ) {

            foreach ( array_keys( \OWA\Core\Module::mechanicalSettings() ) as $key ) {

                $this->assertArrayHasKey( $module . '|' . $key, $registry['fields'],
                    sprintf( 'core must give %s an %s whether or not its file mentions one',
                        $module, $key ) );

                $this->assertNotEmpty( $registry['fields'][ $module . '|' . $key ]['autoload'],
                    'and it stays eager: a module cannot make its own activation lazy' );

                $this->assertArrayNotHasKey( 'default',
                    $registry['fields'][ $module . '|' . $key ],
                    'with NO default, or the prune could drop it and the module would '
                  . 'look uninstalled' );
            }
        }
    }

    /**
     * A stored value the declaration has orphaned is reported, not masked.
     *
     * Two ways for a declared module to have a row outside its declaration,
     * and both mean the value is ignored: the key is absent from the
     * declaration, or it is declared without `storable` and so never queried.
     * The read path used to quietly query for the first case, at one query per
     * key per request, which hid the condition someone needed to be told about.
     */
    public function testAnOrphanedStoredValueIsReported(): void
    {
        $this->requireDb();

        $declared = array_keys(
            (array) ( \OWA\Core\Module::settingsRegistry()['declared'] ?? array() ) );

        if ( ! $declared ) {
            $this->markTestSkipped( 'no module ships a declaration on this installation' );
        }

        $module = $declared[0];

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );
        $table  = $entity->getTableName();

        $id = $db->prepare( (string) $entity->makeId( 'install', '1', $module, 'zz_orphan' ) );

        $db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $table, $id ) );
        $db->query( sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)"
          . " VALUES ('%s', 'install', '1', '%s', 'zz_orphan', '%s', 1, '0')",
            $table, $id, $db->prepare( $module ), $db->prepare( serialize( 'stored' ) ) ) );

        $problems = implode( "\n", $this->settings()->declarationProblems() );

        $db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $table, $id ) );

        $this->assertStringContainsString( 'zz_orphan', $problems );
        $this->assertStringContainsString( 'ignored', $problems,
            'the message has to say the value has no effect, which is the part '
          . 'nobody would otherwise discover' );
    }

    /** And the read does NOT go looking for it. */
    public function testAnUndeclaredKeyOfADeclaredModuleIsNotQueriedFor(): void
    {
        $this->requireDb();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $declared = array_keys(
            (array) ( \OWA\Core\Module::settingsRegistry()['declared'] ?? array() ) );

        if ( ! $declared ) {
            $this->markTestSkipped( 'no module ships a declaration on this installation' );
        }

        $before = (int) $db->num_queries;

        $this->assertFalse(
            \OWA\Core\CoreAPI::getSetting( $declared[0], 'zz_not_in_the_declaration' ) );

        $this->assertSame( 0, (int) $db->num_queries - $before,
            'a declaration is the complete list of what a module stores; there is no '
          . 'fallback lookup masking an incomplete one' );
    }

    /** 'General' is what group has always defaulted to, and it means Instance. */
    public function testTheLegacyGeneralGroupStillMeansTheInstanceMenu(): void
    {
        $sections = \OWA\Core\Controller::settingsNavSections( array(
            'General' => array( array( 'do' => 'x.a', 'anchortext' => 'A', 'group' => 'General' ) ),
        ) );

        $this->assertArrayHasKey( 'Instance', $sections );
        $this->assertArrayNotHasKey( 'General', $sections,
            'existing registrations must not need editing to keep working' );
    }
}
