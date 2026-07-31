<?php

/**
 * Snapshot and restore the ENTIRE mutable state of the shared config singleton.
 *
 * WHY THIS EXISTS
 * ---------------
 * There is one Settings instance per process and the settings tests drive it
 * directly, so anything they leave behind is inherited by every test that runs
 * after them -- and by Settings::__destruct(), which calls save() whenever
 * is_dirty is set and will happily write the leftovers to the real
 * owa_configuration row at script shutdown.
 *
 * Two rounds of that lesson are baked in here:
 *
 *   1. An early version snapshotted nothing and rewrote query_string_filters
 *      and schema_version in the dev database.
 *   2. A later version snapshotted db_settings/default_config/is_dirty and
 *      looked sufficient -- but persistSetting() calls set(), which writes the
 *      merged effective values that get() actually reads. That leaked a broken
 *      report_wrapper into an unrelated REST controller test, which then died
 *      on include('') three files away. The symptom pointed nowhere near the
 *      cause.
 *   3. Fixing (2) by copying the `config` PROPERTY still failed, and looked
 *      like it had worked: `config` is an OBJECT, so assigning it back restores
 *      the same reference whose interior was already mutated. An identity check
 *      passes while get() keeps returning the leaked value. The effective
 *      settings live at $config->get('settings') and must be restored THROUGH
 *      the object.
 *
 * So: capture every mutable property, and reach inside the one that is a
 * container. The list is deliberately exhaustive and cheap -- these are small
 * arrays and scalars.
 */
trait SettingsSingletonSnapshot
{
    /** @var array<string, mixed> */
    private $settings_snapshot = [];

    /**
     * Every non-static property Settings mutates at runtime. `config` must be
     * in here: it is the merged array get() reads, and set() writes it.
     */
    private static $snapshot_properties = [
        'config',
        'default_config',
        'db_settings',
        'fetched_from_db',
        'config_id',
        'config_from_db',
        'config_file_loaded',
        'is_dirty',
    ];

    /** @var array|null the effective settings held INSIDE the config container */
    private $effective_snapshot = null;

    protected function snapshotSettings(): void
    {
        $c = $this->settings();

        $this->settings_snapshot = [];

        foreach ( self::$snapshot_properties as $prop ) {
            $this->settings_snapshot[ $prop ] = $c->$prop;
        }

        // The one that is not a plain value. Copying the property only copies
        // the reference; the array it holds is what get()/set() operate on.
        $this->effective_snapshot = $c->config ? $c->config->get( 'settings' ) : null;
    }

    protected function restoreSettings(): void
    {
        $c = $this->settings();

        foreach ( self::$snapshot_properties as $prop ) {

            // is_dirty last: restoring the arrays above does not reset it, and
            // a stale is_dirty is precisely what triggers the shutdown write.
            if ( $prop === 'is_dirty' ) {
                continue;
            }

            $c->$prop = $this->settings_snapshot[ $prop ];
        }

        if ( $c->config && $this->effective_snapshot !== null ) {
            $c->config->set( 'settings', $this->effective_snapshot );
        }

        $c->is_dirty = $this->settings_snapshot['is_dirty'];
    }

    protected function settings(): object
    {
        return owa_coreAPI::configSingleton();
    }
}
