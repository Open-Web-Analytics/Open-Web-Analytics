<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Write the English string catalogue, conf/strings/en.php, from the code.
 *
 *   cmd=strings-extract            write it
 *   cmd=strings-extract check=1    say whether it is current, write nothing
 *
 * Every CoreAPI::t() call with a literal string becomes an entry. A call whose
 * text is not a literal cannot be catalogued and is reported. See Core\Strings.
 */
class StringsExtractCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Writes the English string catalogue, conf/strings/en.php, from the CoreAPI::t() calls in the code, and reports calls whose text is not a literal.',
            'arguments'   => array(
                'check=1' => 'Report whether the catalogue is current, and write nothing.',
            ),
        );
    }

    function action() {

        $map    = \OWA\Core\Strings::extract( \OWA\Core\Strings::sourceRoots(), $problems );
        $file   = \OWA\Core\Strings::path( \OWA\Core\Strings::SOURCE_LOCALE );
        $source = \OWA\Core\Strings::catalogueSource( $map );

        foreach ( $problems as $where => $why ) {

            $this->e->notice( sprintf( 'Not catalogued: %s: %s.', $where, $why ) );
        }

        $current = is_readable( $file ) && file_get_contents( $file ) === $source;

        if ( $this->getParam( 'check' ) ) {

            $this->e->notice( $current
                ? sprintf( '%s is current: %d strings.', $file, count( $map ) )
                : sprintf( '%s is out of date. Run cmd=strings-extract.', $file ) );

            return;
        }

        if ( $current ) {

            $this->e->notice( sprintf( '%s is current: %d strings.', $file, count( $map ) ) );

            return;
        }

        if ( ! is_dir( dirname( $file ) ) && ! mkdir( dirname( $file ), 0775, true ) ) {

            $this->e->notice( sprintf( 'Could not create %s.', dirname( $file ) ) );

            return;
        }

        if ( file_put_contents( $file, $source ) === false ) {

            $this->e->notice( sprintf( 'Could not write %s.', $file ) );

            return;
        }

        $this->e->notice( sprintf( 'Wrote %s: %d strings.', $file, count( $map ) ) );
    }
}
