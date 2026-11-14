<?php

namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What kind of request this process is answering, as the entry point that
 * booted it declares (the request_type setting):
 *
 *   tracking  log.php
 *   ui        index.php, and any caller that names no type
 *   rest      api/index.php
 *   cli       cli.php
 *   install   install.php
 *
 * A server's role (ServerRole) is the set of these it serves.
 */
final class RequestType {

    const TRACKING = 'tracking';
    const UI       = 'ui';
    const REST     = 'rest';
    const CLI      = 'cli';
    const INSTALL  = 'install';

    const ALL = array( self::TRACKING, self::UI, self::REST, self::CLI, self::INSTALL );

    /** The request type a caller that names none is given. */
    const DEFAULT_TYPE = self::UI;

    /**
     * This request's type.
     *
     * @return string
     */
    public static function current() {

        $type = (string) CoreAPI::getSetting( 'base', 'request_type' );

        return $type !== '' ? $type : self::DEFAULT_TYPE;
    }

    /**
     * Whether this request is the one named.
     *
     * @param string $type
     * @return bool
     */
    public static function is( $type ) {

        return self::current() === $type;
    }

    /**
     * Whether this is a browser request to an OWA page: anything but the REST
     * API and the CLI. These answer with screens and redirects, sign in with
     * cookies, and check a nonce on a state-changing action.
     *
     * @return bool
     */
    public static function isWebApp() {

        return ! in_array( self::current(), array( self::REST, self::CLI ), true );
    }
}
