<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The tracking property config is the enumeration of the wire surface.
 *
 * It used to be 440 lines of PHP literal, where a typo was a parse error and
 * the file could not be wrong in an interesting way. As data in a file it can
 * be: malformed, missing an axis, or naming a callback that does not exist.
 * Nothing catches the last one until an event arrives in production and the
 * derivation quietly does not happen.
 *
 * THE FILE IS FLAT. It grouped properties under request / client / server, which
 * made "how does this value get set" implicit in an entry's POSITION -- and the
 * position was also doing duty as the dependency order, since a callback reads
 * whatever is already on the event. Every entry declares `set_by` and `from`
 * now, so the two stop being one fact, and TrackingPropertyFormatTest is where
 * the axes are checked.
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

    public function testEverySetterIsPresentAndPopulated(): void
    {
        $config = $this->raw();

        $counts = array();

        foreach ( $config as $name => $definition ) {

            $this->assertArrayHasKey( 'set_by', $definition,
                "$name does not say how its value gets set." );

            $counts[ $definition['set_by'] ] = ( $counts[ $definition['set_by'] ] ?? 0 ) + 1;
        }

        ksort( $counts );

        $this->assertSame( array( 'client', 'event', 'request' ), array_keys( $counts ),
            'All three setters must be in use, or the vocabulary has lost one.' );

        /*
         * A MINIMUM PER SETTER. It was `> 5` for all three, which the request
         * bucket no longer satisfies: HTTP_HOST went (always this install's own
         * host), and user_name and user_email moved to `client`, which is what
         * they always were -- the tracker has a setter for user_name, and
         * declaring it request-set made an environmental property of it, so
         * admitRequestParams() refused the value the beacon carried.
         *
         * Five is what is left, and every one is a genuine reading of the
         * REQUEST: the agent, the address, the language, the visitor's network
         * host and the edge clock.
         */
        $floors = array( 'client' => 30, 'event' => 15, 'request' => 5 );

        foreach ( $floors as $set_by => $least ) {

            $this->assertGreaterThanOrEqual( $least, $counts[ $set_by ] ?? 0,
                "Only {$counts[$set_by]} properties are set by $set_by." );
        }
    }

    public function testEveryCallbackNamedInTheConfigExists(): void
    {
        $missing = array();
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
             * The floor was 30 while the v1 date parts, the five attribution
             * readings and the v1 handler inputs still had callbacks. Cutting
             * those took the real count to 25 without changing what this test
             * checks, which is that every callback NAMED in the config exists.
             * Then to 19, when timestampDefault and microtimeDefault went with
             * their properties -- two spellings of the instant `ts` already
             * carries, one reaching no column and the other nothing at all.
             */ );

        $this->assertSame( array(), $missing, implode( "\n  ", $missing ) );
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
                      'event'   => 'serverProperties' );

        foreach ( $map as $set_by => $method ) {

            $declared = array();

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
