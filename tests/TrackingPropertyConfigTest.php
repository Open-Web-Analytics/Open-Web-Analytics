<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The tracking property config is the enumeration of the wire surface.
 *
 * As data in a file it can be malformed, missing an axis, or naming a callback
 * that does not exist -- and nothing else catches the last one until an event
 * arrives in production and the derivation quietly does not happen.
 *
 * THE FILE IS FLAT: every entry declares `set_by` and `from`, so how a value
 * gets set and what it depends on are stated, not implied by position.
 * TrackingPropertyFormatTest is where the axes are checked.
 */
final class TrackingPropertyConfigTest extends TestCase
{
    private const PATH = 'modules/Base/config/tracking_properties.json';

    private function raw(): array
    {
        $json = file_get_contents( \OWA\Core\Env::root() . '/' . self::PATH );

        $this->assertNotFalse( $json, self::PATH . ' is not readable.' );

        $config = json_decode( $json, true );

        $this->assertIsArray(
            $config, self::PATH . ' is not valid JSON: ' . json_last_error_msg() );

        return $config;
    }

    public function testEverySetterIsPresentAndPopulated(): void
    {
        $config = $this->raw();

        $counts = [];

        foreach ( $config as $name => $definition ) {

            $this->assertArrayHasKey( 'set_by', $definition,
                "$name does not say how its value gets set." );

            $counts[ $definition['set_by'] ] = ( $counts[ $definition['set_by'] ] ?? 0 ) + 1;
        }

        ksort( $counts );

        $this->assertSame( [ 'client', 'event', 'request' ], array_keys( $counts ),
            'All three setters must be in use, or the vocabulary has lost one.' );

        /*
         * A MINIMUM PER SETTER. The request bucket holds the genuine readings of
         * the REQUEST that reach a column -- the agent, the address, the language
         * and the edge clock -- and the headers and arrived URLs spam detection
         * reads (Classes\Spam), which nothing stores. The floor is the four that
         * reach a column.
         */
        $floors = [ 'client' => 30, 'event' => 15, 'request' => 4 ];

        foreach ( $floors as $set_by => $least ) {

            $this->assertGreaterThanOrEqual( $least, $counts[ $set_by ] ?? 0,
                "Only {$counts[$set_by]} properties are set by $set_by." );
        }
    }

    public function testEveryCallbackNamedInTheConfigExists(): void
    {
        $missing = [];
        $checked = 0;

        foreach ( $this->raw() as $name => $definition ) {

            /* registerCallbacks() skips empty(), so '' -- which one entry
               uses instead of array() -- is never called. Mirror that here
               rather than inventing a failure the pipeline cannot have. */
            if ( empty( $definition['callbacks'] ) ) {

                continue;
            }

            foreach ( (array) $definition['callbacks'] as $callback ) {

                $checked++;

                if ( ! is_callable( $callback ) ) {

                    $missing[] = "$name names $callback, which is not callable";
                }
            }
        }

        $this->assertGreaterThan(
            15, $checked, 'Almost no callbacks were found, so this test is not reading the config.'
            /*
             * A floor, not the count: what this test checks is that every
             * callback NAMED in the config exists.
             */ );

        $this->assertSame( [], $missing, implode( "\n  ", $missing ) );
    }


    public function testNotesAreDocumentationAndDoNotReachThePipeline(): void
    {
        $noted = 0;

        foreach ( $this->raw() as $definition ) {

            if ( isset( $definition['note'] ) ) {

                $noted++;
            }
        }

        $this->assertGreaterThan(
            0, $noted, 'No notes in the config, so this test proves nothing about stripping them.' );

        foreach ( [ 'requestProperties', 'clientProperties', 'serverProperties' ] as $method ) {

            foreach ( Helpers::$method() as $name => $definition ) {

                $this->assertArrayNotHasKey(
                    'note', $definition,
                    "$name carries its note into the registered definition; notes are for whoever "
                    . 'edits the file, not for the pipeline.' );

                $this->assertArrayNotHasKey(
                    'description', $definition,
                    "$name carries its description into the registered definition; descriptions "
                    . 'are for the wiki, not for the pipeline.' );

                $this->assertArrayNotHasKey(
                    'internal', $definition,
                    "$name carries `internal` into the registered definition; it is for the wiki, "
                    . 'not for the pipeline.' );
            }
        }
    }

    public function testTheConfigIsWhatGetsRegistered(): void
    {
        $config = $this->raw();

        $map = [ 'request' => 'requestProperties',
                      'client'  => 'clientProperties',
                      'event'   => 'serverProperties' ];

        foreach ( $map as $set_by => $method ) {

            $declared = [];

            foreach ( $config as $name => $definition ) {

                if ( $definition['set_by'] === $set_by ) {

                    $declared[] = $name;
                }
            }

            $this->assertSame(
                $declared, array_keys( Helpers::$method() ),
                "The $set_by properties, or their order, differ between the config and what the "
                . 'helper hands to the registry.' );
        }
    }
}
