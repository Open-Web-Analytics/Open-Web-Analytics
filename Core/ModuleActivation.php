<?php

namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Modules switched on by their own configuration.
 *
 * A module's settings.php may declare the owa-config.php constants that
 * activate it, and the queue type it gives the tracking intake:
 *
 *     'activated_by'      => array( 'OWA_SQS_QUEUE_URL' ),
 *     'intake_queue_type' => 'sqs',
 *
 * While one of those constants is set (defined and not empty) the module is
 * active on any server, with or without a database: its is_active is governed
 * by the constant, so no stored row is read and the Modules screen cannot turn
 * it off. A module that declares an intake queue type and is activated this
 * way is a queue backend for the tracking intake (TrackerIngest::backend()).
 *
 * Read from the declarations on disk, because activation is decided before any
 * module object exists (Module::settingsRegistry()).
 */
final class ModuleActivation {

    /**
     * Every module's declaration, module name => activated_by, intake_queue_type.
     *
     * @return array
     */
    public static function declarations() {

        return (array) ( Module::settingsRegistry()['activation'] ?? array() );
    }

    /**
     * The first of the constants that is set, or null.
     *
     * @param string[]   $constants
     * @param array|null $defined the OWA_ constants, name => value; this process's when null
     * @return string|null
     */
    public static function setConstant( array $constants, $defined = null ) {

        foreach ( $constants as $constant ) {

            $value = $defined === null
                ? ( defined( $constant ) ? constant( $constant ) : null )
                : ( $defined[ $constant ] ?? null );

            if ( $value !== null && $value !== '' && $value !== false && $value !== array() ) {

                return (string) $constant;
            }
        }

        return null;
    }

    /**
     * The modules a constant activates, module name => the constant.
     *
     * @param array|null $defined
     * @param array|null $declarations
     * @return array
     */
    public static function activeByConstant( $defined = null, $declarations = null ) {

        $out = array();

        foreach ( $declarations ?? self::declarations() as $module => $decl ) {

            $constant = self::setConstant( (array) ( $decl['activated_by'] ?? array() ), $defined );

            if ( $constant !== null ) {

                $out[ $module ] = $constant;
            }
        }

        return $out;
    }

    /**
     * The intake queue backends configured, queue type => the constant that
     * configured it. More than one is a configuration error
     * (ServerRole::configProblems()).
     *
     * @param array|null $defined
     * @param array|null $declarations
     * @return array
     */
    public static function queueBackends( $defined = null, $declarations = null ) {

        $declarations = $declarations ?? self::declarations();
        $out          = array();

        foreach ( self::activeByConstant( $defined, $declarations ) as $module => $constant ) {

            $type = (string) ( $declarations[ $module ]['intake_queue_type'] ?? '' );

            if ( $type !== '' ) {

                $out[ $type ] = $constant;
            }
        }

        return $out;
    }

    /**
     * Every constant that would configure a queue backend, for a message
     * saying what to set.
     *
     * @param array|null $declarations
     * @return string[]
     */
    public static function queueBackendConstants( $declarations = null ) {

        $out = array();

        foreach ( $declarations ?? self::declarations() as $decl ) {

            if ( (string) ( $decl['intake_queue_type'] ?? '' ) !== '' ) {

                $out = array_merge( $out, (array) ( $decl['activated_by'] ?? array() ) );
            }
        }

        return array_values( array_unique( $out ) );
    }
}
