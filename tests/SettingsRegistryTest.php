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
