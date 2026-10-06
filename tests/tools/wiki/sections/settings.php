<?php
/**
 * Settings reference tables, from the settings registry.
 *
 *   settings                every module's settings, one table per module
 *   settings-<module>       one module's settings, e.g. settings-base, settings-maxmind_geoip
 *   settings-<group>        the fieldsets in a fieldset group, in screen order, one table each,
 *                           e.g. settings-tracking_tag (Base's and every module's tag settings)
 *
 * A module name is tried before a group name; a name that is both is refused
 * rather than guessed at.
 *
 * Sources, all read from code rather than restated here:
 *
 *   - the declarations: Core\Module::settingsRegistry() (each module's settings.php)
 *   - the fieldsets: registerSettingsFieldSet(). They are registered by
 *     registerActions() and registerAdminPanels(), which a configless boot runs
 *     only for active modules and only when the admin nav is built, so both are
 *     called here for every present module. A module that is not loaded is
 *     instantiated without its constructor, which keeps its metrics, handlers and
 *     tracking properties out of the registries the other sections read.
 *   - the constants: Settings::applyConfigConstants(), whose source is the only
 *     place a constant is paired with a setting. A constant is recorded on the
 *     registry only when it is defined, so the pairs are read from the method's
 *     source instead.
 *
 * Labels and descriptions are the declaration's; a setting without one gets an
 * empty cell, never text written here. Left out of the tables: settings
 * declared `internal` (state OWA keeps for itself, per-request flags, URLs
 * derived at boot), and `is_active` and `schema_version`, which
 * Core\Module::mechanicalSettings() gives to every module.
 */

use OWA\Core\CoreAPI;
use OWA\Core\Module;
use OWA\Module\Base\Classes\Settings;

return function ( $arg = null ) {

    $registry = Module::settingsRegistry();
    $config   = CoreAPI::configSingleton();

    $registry['declared'] = array_diff_key( $registry['declared'], array_flip( OWA_WIKI_EXCLUDED_MODULES ) );

    // ---- fieldsets, from every present module -------------------------------

    $service = CoreAPI::serviceSingleton();
    $names   = array_keys( $registry['declared'] );

    foreach ( CoreAPI::getPresentModules() as $dir ) {

        $name = null;

        foreach ( $names as $n ) {
            if ( \OWA\Core\Lib::moduleDirName( $n ) === $dir ) {
                $name = $n;
            }
        }

        if ( $name === null ) {
            continue; // declares no settings
        }

        $m = $service->getModule( $name );

        if ( ! $m ) {

            $class = 'OWA\\Module\\' . $dir . '\\Module';
            $m     = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
            $m->name = $name;
            $m->path = OWA_MODULES_DIR . $dir . '/';
            $m->registerActions();
        }

        $m->registerAdminPanels();
    }

    $fieldsets = $config->registeredFieldSets();

    // ---- constants, from applyConfigConstants()'s source --------------------

    $method = new ReflectionMethod( Settings::class, 'applyConfigConstants' );
    $lines  = file( $method->getFileName() );
    $source = implode( '', array_slice( $lines, $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1 ) );

    $constants = array(); // module|key => OWA_*

    preg_match_all( "/setFromConfigConstant\(\s*'(\w+)',\s*'(\w+)',[^;]*?'(OWA_\w+)'\s*\)/", $source, $m, PREG_SET_ORDER );

    foreach ( $m as $hit ) {
        $constants[ $hit[1] . '|' . $hit[2] ] = $hit[3];
    }

    // The foreach( array( 'OWA_X' => 'key' ) ...) blocks, all of which write base.
    preg_match_all( "/'(OWA_\w+)'\s*=>\s*'(\w+)'/", $source, $m, PREG_SET_ORDER );

    foreach ( $m as $hit ) {
        $constants[ 'base|' . $hit[2] ] = $hit[1];
    }

    // ---- the rows ------------------------------------------------------------

    $mechanical = Module::mechanicalSettings();
    $fields     = $registry['fields'];

    // A constant can supply a key no module declares (data_dir, cache_dir).
    foreach ( $constants as $id => $const ) {
        if ( ! isset( $fields[ $id ] ) ) {
            $fields[ $id ] = array();
        }
    }

    $text = function ( $html ) {

        $s = (string) $html;
        $s = preg_replace( '#<br\s*/?>#i', ' ', $s );
        $s = preg_replace( '#</?(strong|b)>#i', '**', $s );
        $s = preg_replace( '#</?(em|i)>#i', '_', $s );
        $s = strip_tags( $s );
        $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = preg_replace( '/\s+/', ' ', $s );

        return str_replace( '|', '\\|', trim( $s ) );
    };

    $value = function ( $v ) {

        if ( is_bool( $v ) ) {
            return $v ? '`true`' : '`false`';
        }

        if ( $v === '' ) {
            return '(empty)';
        }

        if ( is_array( $v ) ) {
            return $v ? '`' . str_replace( '|', '\\|', json_encode( $v, JSON_UNESCAPED_SLASHES ) ) . '`' : '`[]`';
        }

        return '`' . str_replace( '|', '\\|', (string) $v ) . '`';
    };

    $row = function ( $id ) use ( $fields, $constants, $text, $value ) {

        $a = (array) $fields[ $id ];
        list( , $key ) = explode( '|', $id, 2 );

        $stored = ! empty( $a['storable'] ) || ! empty( $a['autoload'] );

        $type = array();

        if ( ! empty( $a['type'] ) ) {
            $type[] = $a['type'];
        }

        if ( isset( $a['min'] ) || isset( $a['max'] ) ) {
            $type[] = ( $a['min'] ?? '' ) . '–' . ( $a['max'] ?? '' );
        }

        if ( ! empty( $a['options'] ) ) {
            $type[] = 'one of `' . implode( '`, `', (array) $a['options'] ) . '`';
        }

        if ( ! empty( $a['blank'] ) ) {
            $type[] = 'blank: ' . $text( $a['blank'] );
        }

        if ( ! empty( $a['pattern_problem'] ) ) {
            $type[] = $text( $a['pattern_problem'] );
        }

        $desc = $text( $a['description'] ?? '' );
        $label = $text( $a['label'] ?? '' );

        if ( $label !== '' ) {
            $desc = '**' . $label . '**' . ( $desc !== '' ? ' — ' . $desc : '' );
        }

        return array(
            '`' . $key . '`',
            isset( $constants[ $id ] ) ? '`' . $constants[ $id ] . '`' : '',
            ( ! empty( $a['secret'] ) || ! array_key_exists( 'default', $a ) || $a['default'] === null )
                ? '' : $value( $a['default'] ),
            implode( '; ', $type ),
            $stored ? implode( ', ', (array) ( $a['scopes'] ?? array( 'install' ) ) ) : '',
            $stored ? 'database' : 'config only',
            $desc,
        );
    };

    $table = function ( array $ids ) use ( $row ) {

        $head = array( 'Setting', 'Constant', 'Default', 'Type', 'Scopes', 'Stored in', 'Description' );
        $rows = array_map( $row, $ids );

        // A column that is empty in every row says nothing about this table.
        $keep = array();

        foreach ( array_keys( $head ) as $i ) {
            foreach ( $rows as $r ) {
                if ( $r[ $i ] !== '' ) {
                    $keep[] = $i;
                    break;
                }
            }
        }

        $cells = function ( $r ) use ( $keep ) {
            $out = array();
            foreach ( $keep as $i ) {
                $out[] = $r[ $i ] === '' ? '—' : $r[ $i ];
            }
            return '| ' . implode( ' | ', $out ) . ' |';
        };

        $lines = array( $cells( $head ), '|' . str_repeat( '---|', count( $keep ) ) );

        foreach ( $rows as $r ) {
            $lines[] = $cells( $r );
        }

        return implode( "\n", $lines );
    };

    $byModule = array();

    foreach ( array_keys( $fields ) as $id ) {

        list( $module, $key ) = explode( '|', $id, 2 );

        if ( isset( $mechanical[ $key ] ) || ! empty( $fields[ $id ]['internal'] ) ) {
            continue;
        }

        $byModule[ $module ][] = $id;
    }

    ksort( $byModule );

    foreach ( $byModule as &$ids ) {
        sort( $ids );
    }
    unset( $ids );

    $groups = array();

    foreach ( $fieldsets as $fs ) {
        if ( ! empty( $fs['group'] ) ) {
            $groups[ $fs['group'] ][] = $fs;
        }
    }

    // ---- one module, every module, or a group -------------------------------

    if ( $arg !== null && isset( $byModule[ $arg ] ) && isset( $groups[ $arg ] ) ) {
        throw new RuntimeException( "settings-$arg names both a module and a fieldset group." );
    }

    if ( $arg !== null && isset( $byModule[ $arg ] ) ) {
        return $table( $byModule[ $arg ] );
    }

    if ( $arg !== null && isset( $groups[ $arg ] ) ) {

        $sets = $groups[ $arg ];

        // By declared order, then by module so Base's come first at equal order.
        usort( $sets, function ( $a, $b ) {
            return array( $a['module'] !== 'base', (int) ( $a['order'] ?? PHP_INT_MAX ) )
               <=> array( $b['module'] !== 'base', (int) ( $b['order'] ?? PHP_INT_MAX ) );
        } );

        $out = array();

        foreach ( $sets as $fs ) {

            $ids = array_values( array_filter( array_map( function ( $k ) use ( $fs ) {
                return $fs['module'] . '|' . $k;
            }, (array) $fs['settings'] ), function ( $id ) use ( $fields ) {
                return empty( $fields[ $id ]['internal'] );
            } ) );

            $block = '**' . $text( $fs['legend'] ?? $fs['id'] ) . '**';

            if ( ! empty( $fs['description'] ) ) {
                $block .= ' — ' . $text( $fs['description'] );
            }

            $out[] = $block . "\n\n" . $table( $ids );
        }

        return implode( "\n\n", $out );
    }

    if ( $arg !== null ) {
        throw new RuntimeException( sprintf( "settings-%s: no module or fieldset group by that name. Modules: %s. Groups: %s.",
            $arg, implode( ', ', array_keys( $byModule ) ), implode( ', ', array_keys( $groups ) ) ) );
    }

    $out = array();

    foreach ( $byModule as $module => $ids ) {
        $out[] = '### `' . $module . "`\n\n" . $table( $ids );
    }

    $out[] = 'Every module also stores `' . implode( '` and `', array_keys( $mechanical ) ) . '`, which the framework manages. '
           . 'Settings that hold OWA\'s own state, or values derived at boot, are not listed.';

    return implode( "\n\n", $out );
};
