<?php
/**
 * What a Profile's tracking bundle can turn on: the tag setting, the tracker
 * command it queues or starts, the label the Tracking Tag screen's Status
 * names it by, and whether the tracker starts it without a bundle.
 *
 * Sources, all read from code:
 *
 *   - Base's settings and commands: TrackerBundle::HIT_SETTINGS and
 *     FEATURE_SETTINGS, which config() builds the bundle from.
 *   - A module's: its Module class's FEATURE_SETTINGS, the same shape.
 *   - The labels: TrackerBundle::FEATURE_LABELS through the
 *     tracker_feature_labels filter, as trackedEvents() reads them.
 *   - The default list: TrackerDefaults, from modules/Base/src/tracker/defaults.json.
 *   - Each setting's own label: the settings registry.
 *
 * A labelled command or a default feature with no setting here is an error.
 */

use OWA\Core\CoreAPI;
use OWA\Core\Module;
use OWA\Module\Base\Classes\TrackerBundle;
use OWA\Module\Base\Classes\TrackerDefaults;

return function () {

    $fields = Module::settingsRegistry()['fields'];
    $labels = (array) CoreAPI::filter( 'tracker_feature_labels', TrackerBundle::FEATURE_LABELS );

    // module => [ setting => command ], Base first.
    $sources = array( 'base' => TrackerBundle::HIT_SETTINGS + TrackerBundle::FEATURE_SETTINGS );
    $names   = array_keys( Module::settingsRegistry()['declared'] );
    $display = array();

    foreach ( CoreAPI::getPresentModules() as $dir ) {

        $class = 'OWA\\Module\\' . $dir . '\\Module';

        if ( ! class_exists( $class ) || ! defined( $class . '::FEATURE_SETTINGS' ) ) {
            continue;
        }

        foreach ( $names as $name ) {

            if ( \OWA\Core\Lib::moduleDirName( $name ) === $dir
                 && $name !== 'base' && ! in_array( $name, OWA_WIKI_EXCLUDED_MODULES, true ) ) {

                $sources[ $name ] = (array) constant( $class . '::FEATURE_SETTINGS' );
                $display[ $name ] = $dir;
            }
        }
    }

    $out  = "| Setting | Command | Status label | In the default `features` list |\n|---|---|---|---|\n";
    $seen = array();

    foreach ( $sources as $module => $settings ) {

        foreach ( $settings as $setting => $command ) {

            $label = trim( (string) ( $fields[ $module . '|' . $setting ]['label'] ?? '' ) );
            $where = $module === 'base' ? '' : ', ' . $display[ $module ] . ' module';

            $out .= sprintf( "| %s | `%s` | %s | %s |\n",
                ( $label !== '' ? '**' . $label . '** ' : '' ) . '(`' . $setting . '`' . $where . ')',
                $command,
                (string) ( $labels[ $command ] ?? $command ),
                isset( TrackerBundle::HIT_SETTINGS[ $setting ] ) && $module === 'base'
                    ? '—' : ( TrackerDefaults::starts( $command ) ? 'yes' : 'no' ) );

            $seen[] = $command;
        }
    }

    $missing = array_diff( array_merge( array_keys( $labels ), TrackerDefaults::get( 'features' ) ), $seen );

    if ( $missing ) {
        throw new RuntimeException( 'No tag setting turns on: ' . implode( ', ', array_unique( $missing ) )
            . '. Declare it in TrackerBundle::FEATURE_SETTINGS or the module\'s FEATURE_SETTINGS.' );
    }

    return $out . "\nA row marked — in the last column is a hit the tag queues, not a feature the tracker starts.\n";
};
