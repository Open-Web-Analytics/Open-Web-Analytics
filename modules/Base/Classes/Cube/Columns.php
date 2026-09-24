<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Turns the cube column definitions into steps, and owns the one map from a
 * logical source to where it actually lives.
 *
 * A definition says `session.tagged_source`. This is the only place that knows
 * it arrives as `s.s_tagged_source` off a window function -- which is what
 * keeps SQL and join aliases out of the config, and what lets the window
 * project only the values something actually references.
 */
class Columns {

    /**
     * Values taken from the session's FIRST event: logical name => the alias
     * the windowed subquery gives it.
     *
     * The window emits only the ones a definition references, so removing the
     * last definition that uses one removes it from the sort as well.
     */
    const SESSION = array(
        'page_location'       => 'lp_location',
        'page_path'           => 'lp_path',
        'page_query'          => 'lp_query',
        'page_title'          => 'lp_title',
        'tagged_source'       => 's_tagged_source',
        'tagged_medium'       => 's_tagged_medium',
        'tagged_campaign'     => 's_tagged_campaign',
        'tagged_ad'           => 's_tagged_ad',
        'tagged_search_terms' => 's_tagged_search_terms',
        'referer_host'        => 's_referer_host',
    );

    /**
     * Tests a definition may name rather than spell.
     *
     * ACQUISITION, NOT THE ROW. This used to be `v.visitor_id IS NULL` -- the
     * LEFT JOIN found nothing -- which was only ever a proxy for "we never
     * captured this visitor's acquisition", and the proxy breaks as soon as
     * anything else writes to that row. A user property arriving for a visitor
     * whose acquisition is unknown creates the row, and the build would then
     * resolve acq_source to `direct` rather than the sentinel: an unknown
     * silently becoming a claim.
     *
     * acq_ts is written only when an acquisition was actually captured, so it
     * says what the test means. Equivalently: stamp the acq_* columns when
     * acq_ts IS NOT NULL, sentinel otherwise.
     */
    const TESTS = array(
        'acquisition.missing' => 'v.acq_ts IS NULL',
    );

    /** @var array logical session name => true, for the ones referenced */
    private $session_used = array();

    /** @var array the definitions */
    private $definitions;

    /**
     * @param array|null $definitions defaults to the shipped config
     */
    function __construct( $definitions = null ) {

        $this->definitions = $definitions === null
            ? (array) \OWA\Core\CoreAPI::loadConf( 'cube_columns.php', 'cube.columns' )
            : (array) $definitions;
    }

    /**
     * The steps, keyed by column, validated against the entity.
     *
     * @param string[] $expected the cube's derived columns
     * @return Step[]
     * @throws \RuntimeException on a column with no definition, or one naming a
     *                           column the cube does not have
     */
    public function steps( array $expected ) {

        $unknown = array_diff( array_keys( $this->definitions ), $expected );

        if ( $unknown ) {

            throw new \RuntimeException( sprintf(
                'cube_columns defines %s, which the cube does not have. Config says how a '
              . 'declared column is filled; it cannot add one.', implode( ', ', $unknown ) ) );
        }

        $missing = array_diff( $expected, array_keys( $this->definitions ) );

        if ( $missing ) {

            throw new \RuntimeException( sprintf(
                'cube_columns has no definition for %s. A column was added to the entity '
              . 'without saying how a build fills it.', implode( ', ', $missing ) ) );
        }

        $steps = array();

        foreach ( $expected as $column ) {

            $steps[ $column ] = $this->step( $column, (array) $this->definitions[ $column ] );
        }

        return $steps;
    }

    /**
     * Which session values the definitions referenced.
     *
     * @return array logical name => window alias
     */
    public function sessionSources() {

        $used = array();

        foreach ( array_keys( $this->session_used ) as $name ) {

            $used[ $name ] = self::SESSION[ $name ];
        }

        return $used;
    }

    /**
     * @param string $column
     * @param array  $definition
     * @return Step
     * @throws \RuntimeException
     */
    private function step( $column, array $definition ) {

        $kind = isset( $definition['kind'] ) ? (string) $definition['kind'] : '';

        switch ( $kind ) {

            case 'copy':
                return new CopyStep( $column,
                    $this->resolve( $definition['from'] ),
                    $this->join( $definition['from'] ),
                    ! empty( $definition['text'] ),
                    $this->test( $definition ) );

            case 'source':
                return new SourceStep( $column,
                    $this->resolve( $definition['tag'] ),
                    $this->resolve( $definition['host'] ),
                    $this->join( $definition['tag'] ),
                    $this->test( $definition ) );

            case 'medium':
                return new MediumStep( $column,
                    $this->resolve( $definition['tag'] ),
                    $this->resolve( $definition['host'] ),
                    $this->join( $definition['tag'] ),
                    $this->test( $definition ) );

            case 'is_exit':
                return new IsExitStep( $column );

            case 'new_vs_returning':
                return new NewVsReturningStep( $column );

            case 'literal':
                return new LiteralStep( $column, array( $this, 'literalValue' ) );

            case 'compute':
                return $this->computeStep( $column, $definition );
        }

        throw new \RuntimeException( sprintf(
            '%s names cube column kind "%s", which does not exist', $column, $kind ) );
    }

    /**
     * @param string $column
     * @param array  $definition
     * @return ComputeStep
     * @throws \RuntimeException
     */
    private function computeStep( $column, array $definition ) {

        $class = isset( $definition['class'] ) ? (string) $definition['class'] : '';

        if ( ! class_exists( $class ) ) {

            throw new \RuntimeException( sprintf(
                '%s names compute class %s, which does not exist', $column, $class ) );
        }

        $step = new $class( $column );

        if ( ! $step instanceof ComputeStep ) {

            throw new \RuntimeException( sprintf(
                '%s names %s, which is not a ComputeStep', $column, $class ) );
        }

        // Its fallback may read the window; reads() names RAW columns, which
        // the candidate query projects and the window never sees.
        foreach ( $step->sessionReads() as $name ) {

            if ( ! isset( self::SESSION[ $name ] ) ) {

                throw new \RuntimeException( sprintf(
                    '%s reads session.%s, which the cube does not carry', $column, $name ) );
            }

            $this->session_used[ $name ] = true;
        }

        return $step;
    }

    /** The only literal the cube has. @return int */
    public function literalValue( Context $context ) {

        return $context->built_at;
    }

    /**
     * @param array $definition
     * @return string|null
     */
    private function test( array $definition ) {

        if ( empty( $definition['absent'] ) ) {

            return null;
        }

        $name = (string) $definition['absent'];

        if ( ! isset( self::TESTS[ $name ] ) ) {

            throw new \RuntimeException( sprintf( 'unknown cube test "%s"', $name ) );
        }

        return self::TESTS[ $name ];
    }

    /**
     * A logical source to the SQL that reads it.
     *
     * @param string $source
     * @return string
     * @throws \RuntimeException
     */
    private function resolve( $source ) {

        list( $scope, $name ) = $this->split( $source );

        if ( $scope === 'session' ) {

            if ( ! isset( self::SESSION[ $name ] ) ) {

                throw new \RuntimeException( sprintf(
                    'session.%s is not a value the cube carries from a session\'s first event',
                    $name ) );
            }

            $this->session_used[ $name ] = true;

            return Context::SESSION . '.' . self::SESSION[ $name ];
        }

        return Context::VISITOR . '.' . $name;
    }

    /**
     * @param string $source
     * @return string the join alias it lives on
     */
    private function join( $source ) {

        list( $scope, ) = $this->split( $source );

        return $scope === 'session' ? Context::SESSION : Context::VISITOR;
    }

    /**
     * @param string $source
     * @return array (scope, name)
     * @throws \RuntimeException
     */
    private function split( $source ) {

        $parts = explode( '.', (string) $source, 2 );

        if ( count( $parts ) !== 2 || ! in_array( $parts[0], array( 'session', 'visitor' ), true ) ) {

            throw new \RuntimeException( sprintf(
                '"%s" is not a cube source. Name one as session.<value> or visitor.<column>.',
                $source ) );
        }

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $parts[1] ) ) {

            throw new \RuntimeException( sprintf( '"%s" is not a column name', $parts[1] ) );
        }

        return $parts;
    }
}

?>
