<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Write the English string catalogue, conf/strings/en.php, from the code, and
 * the list of its keys the reporting JavaScript uses, conf/strings/script-keys.php.
 *
 *   cmd=strings-extract            write them
 *   cmd=strings-extract check=1    say whether they are current, write nothing
 *
 * Every CoreAPI::t() and CoreAPI::tn() call with literal strings becomes an
 * entry, and so does every OWA.l() and OWA.ln() call in a module's src/
 * JavaScript. A call whose text is not a literal cannot be catalogued and is
 * reported. See Core\Strings.
 */
class StringsExtractCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Writes the English string catalogue, conf/strings/en.php, from the CoreAPI::t() and CoreAPI::tn() calls in the code and the OWA.l() and OWA.ln() calls in the reporting JavaScript. Also writes conf/strings/script-keys.php, the keys the JavaScript uses, and reports calls whose text is not a literal.',
            'arguments'   => array(
                'check=1' => 'Report whether the catalogue and the key list are current, and write nothing.',
            ),
        );
    }

    function action() {

        $map   = \OWA\Core\Strings::extract( \OWA\Core\Strings::sourceRoots(), $problems, $script_keys );
        $files = array(
            \OWA\Core\Strings::path( \OWA\Core\Strings::SOURCE_LOCALE ) => array( \OWA\Core\Strings::catalogueSource( $map ), count( $map ), 'strings' ),
            \OWA\Core\Strings::scriptKeysPath()                         => array( \OWA\Core\Strings::scriptKeysSource( $script_keys ), count( $script_keys ), 'keys' ),
        );

        foreach ( $problems as $where => $why ) {

            $this->e->notice( sprintf( 'Not catalogued: %s: %s.', $where, $why ) );
        }

        foreach ( $files as $file => list( $source, $count, $what ) ) {

            if ( is_readable( $file ) && file_get_contents( $file ) === $source ) {

                $this->e->notice( sprintf( '%s is current: %d %s.', $file, $count, $what ) );
                continue;
            }

            if ( $this->getParam( 'check' ) ) {

                $this->e->notice( sprintf( '%s is out of date. Run cmd=strings-extract.', $file ) );
                continue;
            }

            if ( ! is_dir( dirname( $file ) ) && ! mkdir( dirname( $file ), 0775, true ) ) {

                $this->e->notice( sprintf( 'Could not create %s.', dirname( $file ) ) );

                return;
            }

            if ( file_put_contents( $file, $source ) === false ) {

                $this->e->notice( sprintf( 'Could not write %s.', $file ) );

                return;
            }

            $this->e->notice( sprintf( 'Wrote %s: %d %s.', $file, $count, $what ) );
        }
    }
}
