<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The tracking property config is the enumeration of the wire surface.
 *
 * It used to be 440 lines of PHP literal, where a typo was a parse error and
 * the file could not be wrong in an interesting way. As data in a file it can
 * be: malformed, missing a scope, or naming a callback that does not exist.
 * Nothing catches the last one until an event arrives in production and the
 * derivation quietly does not happen.
 */
final class TrackingPropertyConfigTest extends TestCase
{
    private const PATH = 'modules/Base/config/tracking_properties.json';

    private function raw(): array
    {
        $json = file_get_contents( OWA_DIR . self::PATH );

        $this->assertNotFalse( $json, self::PATH . ' is not readable.' );

        $config = json_decode( $json, true );

        $this->assertIsArray(
            $config, self::PATH . ' is not valid JSON: ' . json_last_error_msg() );

        return $config;
    }

    public function testEveryScopeIsPresentAndPopulated(): void
    {
        $config = $this->raw();

        $this->assertSame(
            array( 'request', 'client', 'server' ), array_keys( $config ),
            'The scopes, and their order, are the order the pipeline applies them in.' );

        foreach ( $config as $scope => $properties ) {

            $this->assertNotEmpty( $properties, "The $scope scope is empty." );
        }
    }

    public function testEveryCallbackNamedInTheConfigExists(): void
    {
        $missing = array();
        $checked = 0;

        foreach ( $this->raw() as $scope => $properties ) {

            foreach ( $properties as $name => $definition ) {

                /* registerCallbacks() skips empty(), so '' -- which one entry
                   uses instead of array() -- is never called. Mirror that here
                   rather than inventing a failure the pipeline cannot have. */
                if ( empty( $definition['callbacks'] ) ) {

                    continue;
                }

                foreach ( (array) $definition['callbacks'] as $callback ) {

                    $checked++;

                    if ( ! is_callable( $callback ) ) {

                        $missing[] = "$scope.$name names $callback, which is not callable";
                    }
                }
            }
        }

        $this->assertGreaterThan(
            30, $checked, 'Almost no callbacks were found, so this test is not reading the config.' );

        $this->assertSame( array(), $missing, implode( "\n  ", $missing ) );
    }


    public function testNotesAreDocumentationAndDoNotReachThePipeline(): void
    {
        $noted = 0;

        foreach ( $this->raw() as $properties ) {

            foreach ( $properties as $definition ) {

                if ( isset( $definition['note'] ) ) {

                    $noted++;
                }
            }
        }

        $this->assertGreaterThan(
            0, $noted, 'No notes in the config, so this test proves nothing about stripping them.' );

        foreach ( array( 'requestProperties', 'clientProperties', 'serverProperties' ) as $method ) {

            foreach ( Helpers::$method() as $name => $definition ) {

                $this->assertArrayNotHasKey(
                    'note', $definition,
                    "$name carries its note into the registered definition; notes are for whoever "
                    . 'edits the file, not for the pipeline.' );
            }
        }
    }

    public function testTheConfigIsWhatGetsRegistered(): void
    {
        $config = $this->raw();

        $map = array( 'request' => 'requestProperties',
                      'client'  => 'clientProperties',
                      'server'  => 'serverProperties' );

        foreach ( $map as $scope => $method ) {

            $this->assertSame(
                array_keys( $config[ $scope ] ), array_keys( Helpers::$method() ),
                "The $scope properties, or their order, differ between the config and what the "
                . 'helper hands to the registry.' );
        }
    }
}
