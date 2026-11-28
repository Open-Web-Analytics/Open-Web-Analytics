<?php
namespace OWA\Core;

/**
 * A controller's declared parameters (Controller::params()): each one's type,
 * its typed value, and why a request's value is refused.
 *
 * A declaration is keyed by the parameter's name. A key may carry the dashes
 * it is written with, `--dry-run`; the name is the key without them. Its
 * entries:
 *
 *   type         one of TYPES
 *   description  what it does
 *   required     true: it must be given, with a value
 *   default      the value when it is not given (false for a flag, else null)
 *   value        how its value is written in the reference, such as <path>
 *   min, max     integer and duration: the range
 *   options      select: the values it takes, a list, or value => label
 *   list         string and select: a comma-separated list, read as an array
 *   pattern      string: a regular expression the value matches
 *   pattern_problem  string: what it says when the value does not
 *   ago          date: a period back from today (90days, 18m, 2years) as well
 *   not_before   date: the name of a date parameter this one may not precede
 *   live, stream profile: the Profile is not archived; observes this stream
 *   retired      a message: the parameter is gone, and naming it is refused
 *                with this. It is left out of the reference.
 *
 * Each type is checked with Core\Validation classes (validators()). A
 * parameter given with no value, `name=` or `--name` for a type that is not
 * a flag, is refused.
 */
class Params {

    /** The types a parameter can be. */
    const TYPES = [
        'integer', 'duration', 'flag', 'select', 'string', 'date', 'email',
        'property', 'profile', 'module', 'user',
    ];

    /** What a flag takes: on, then off. */
    const FLAG_ON  = [ '1', 'true', 'yes' ];
    const FLAG_OFF = [ '0', 'false', 'no' ];

    /** The value placeholder of each type in the reference, when the declaration gives none. */
    const PLACEHOLDERS = [
        'integer'  => '<n>',
        'duration' => '<seconds>',
        'string'   => '<value>',
        'date'     => '<yyyymmdd>',
        'email'    => '<email>',
        'property' => '<id>',
        'profile'  => '<id>',
        'module'   => '<name>',
        'user'     => '<user id>',
    ];

    /**
     * The declarations by name, each with `key`, the name as declared.
     * Throws for a declaration that is not one.
     *
     * @param  array<string, mixed> $declared name or --name => declaration
     * @return array<string, array<string, mixed>>
     */
    public static function declarations( array $declared ) {

        $out = [];

        foreach ( $declared as $key => $d ) {

            $name = self::name( (string) $key );

            if ( ! is_array( $d ) || ( ! isset( $d['retired'] ) && ! in_array( $d['type'] ?? null, self::TYPES, true ) ) ) {

                throw new \LogicException( sprintf( 'Parameter %s: a declaration needs a type, one of %s.', $key, implode( ', ', self::TYPES ) ) );
            }

            if ( ( $d['type'] ?? null ) === 'select' && empty( $d['options'] ) ) {

                throw new \LogicException( sprintf( 'Parameter %s: a select declares its options.', $key ) );
            }

            $out[ $name ] = [ 'key' => (string) $key ] + $d;
        }

        return $out;
    }

    /**
     * A declaration's key as the parameter is read: without dashes or an `=`.
     *
     * @param  string $key
     * @return string
     */
    public static function name( $key ) {

        return ltrim( explode( '=', (string) $key, 2 )[0], '-' );
    }

    /**
     * Each declared parameter's value, as its type: the default when it is
     * not given, and when its value is not one the type takes. Reads nothing
     * from the database; problems() checks the rest.
     *
     * @param  array<string, mixed> $declared as params() gives them
     * @param  array<mixed>         $raw      the request's parameters
     * @return array<string, mixed> name => value
     */
    public static function values( array $declared, array $raw ) {

        $out = [];

        foreach ( self::declarations( $declared ) as $name => $d ) {

            if ( isset( $d['retired'] ) ) {

                continue;
            }

            $value = array_key_exists( $name, $raw ) ? self::convert( $d, $raw[ $name ] ) : null;

            $out[ $name ] = $value ?? self::defaultOf( $d );
        }

        return $out;
    }

    /**
     * Why the request's parameters are refused: name => message, each naming
     * the parameter. Empty when every declared one is acceptable. Parameters
     * that are not declared are not looked at.
     *
     * @param  array<string, mixed> $declared as params() gives them
     * @param  array<mixed>         $raw
     * @return array<string, string>
     */
    public static function problems( array $declared, array $raw ) {

        $declared = self::declarations( $declared );
        $problems = [];

        foreach ( $declared as $name => $d ) {

            $given = array_key_exists( $name, $raw );

            if ( isset( $d['retired'] ) ) {

                if ( $given ) {

                    $problems[ $name ] = (string) $d['retired'];
                }

                continue;
            }

            if ( ! $given ) {

                if ( ! empty( $d['required'] ) ) {

                    $problems[ $name ] = self::run( 'required', null, [], self::written( $d ) );
                }

                continue;
            }

            $value = $raw[ $name ];
            $as    = self::given( $d, $value );

            $empty = $value === '' || $value === true || $value === null
                || ( ! empty( $d['list'] ) && is_scalar( $value ) && ! self::items( $d, (string) $value ) );

            if ( $d['type'] !== 'flag' && $empty ) {

                $problems[ $name ] = isset( $d['pattern_problem'] )
                    ? $as . ': ' . $d['pattern_problem']
                    : sprintf( \OWA\Core\CoreAPI::t( '%s needs a value.' ), $as );

                continue;
            }

            foreach ( self::validators( $d, $value, $raw, $declared ) as [ $validation, $checked, $conf ] ) {

                $msg = self::run( $validation, $checked, $conf, $name );

                if ( $msg !== null ) {

                    $problems[ $name ] = $as . ': ' . $msg;

                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * How a parameter is written: --dry-run, months-ahead=<n>.
     *
     * @param  array<string, mixed> $d a declaration from declarations()
     * @return string
     */
    public static function written( array $d ) {

        $name   = self::name( $d['key'] );
        $dashes = str_starts_with( $d['key'], '--' ) ? '--' : '';

        if ( ( $d['type'] ?? '' ) === 'flag' ) {

            return '--' . $name;
        }

        if ( isset( $d['value'] ) ) {

            $placeholder = (string) $d['value'];

        } elseif ( ( $d['type'] ?? '' ) === 'select' ) {

            $placeholder = '<' . implode( '|', self::options( $d ) ) . '>';

        } elseif ( ( $d['type'] ?? '' ) === 'date' && ! empty( $d['ago'] ) ) {

            $placeholder = '<cutoff>';

        } else {

            $placeholder = self::PLACEHOLDERS[ $d['type'] ?? 'string' ] ?? '<value>';
        }

        if ( ! empty( $d['list'] ) ) {

            $placeholder .= '[,' . $placeholder . '...]';
        }

        return $dashes . $name . '=' . $placeholder;
    }

    /**
     * The values a select takes.
     *
     * @param  array<string, mixed> $d
     * @return list<string>
     */
    public static function options( array $d ) {

        $options = (array) ( $d['options'] ?? [] );

        return array_map( 'strval', array_is_list( $options ) ? $options : array_keys( $options ) );
    }

    /**
     * The value when the parameter is not given.
     *
     * @param  array<string, mixed> $d
     * @return mixed
     */
    public static function defaultOf( array $d ) {

        if ( array_key_exists( 'default', $d ) ) {

            return $d['default'];
        }

        return ( $d['type'] ?? '' ) === 'flag' ? false : null;
    }

    /**
     * The validations a given value is put through, in order: [ validation,
     * the value it checks, its configuration ]. The first to fail is the
     * parameter's problem.
     *
     * @param  array<string, mixed>                $d
     * @param  mixed                               $value
     * @param  array<mixed>                        $raw      every parameter, for not_before
     * @param  array<string, array<string, mixed>> $declared
     * @return list<array{string, mixed, array<string, mixed>}>
     */
    protected static function validators( array $d, $value, array $raw, array $declared ) {

        $v = is_scalar( $value ) ? (string) $value : '';

        switch ( $d['type'] ) {

            case 'flag':

                return [ [ 'inArray', is_bool( $value ) ? ( $value ? '1' : '0' ) : strtolower( $v ),
                    [ 'possible_values' => array_merge( self::FLAG_ON, self::FLAG_OFF ) ] ] ];

            case 'integer':
            case 'duration':

                return [ [ 'integerRange', is_int( $value ) ? $value : $v,
                    [ 'min' => $d['min'] ?? null, 'max' => $d['max'] ?? null ] ] ];

            case 'select':

                $checks = [];

                foreach ( self::items( $d, $v ) as $item ) {

                    $checks[] = [ 'inArray', $item, [ 'possible_values' => self::options( $d ) ] ];
                }

                return $checks;

            case 'string':

                if ( ! is_scalar( $value ) ) {

                    return [ [ 'required', null, [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Not a value this takes.' ) ] ] ];
                }

                if ( ! isset( $d['pattern'] ) ) {

                    return [];
                }

                $conf = [ 'pattern' => $d['pattern'] ];

                if ( isset( $d['pattern_problem'] ) ) {

                    $conf['errorMsg'] = (string) $d['pattern_problem'];
                }

                return array_map( static fn ( $item ) => [ 'pattern', $item, $conf ], self::items( $d, $v ) );

            case 'date':

                $checks = [ [ 'date', $v, [ 'ago' => ! empty( $d['ago'] ) ] ] ];
                $other  = $d['not_before'] ?? null;

                if ( $other !== null && isset( $raw[ $other ], $declared[ $other ] ) ) {

                    $start = \OWA\Core\Validation\Date::resolve( $raw[ $other ], ! empty( $declared[ $other ]['ago'] ) );

                    if ( $start !== null ) {

                        $checks[] = [ 'dateRange', [ 'startDate' => $start,
                            'endDate' => (string) \OWA\Core\Validation\Date::resolve( $v, ! empty( $d['ago'] ) ) ], [] ];
                    }
                }

                return $checks;

            case 'email':

                return [ [ 'emailAddress', $v, [] ] ];

            case 'user':

                return [
                    [ 'userName', $v, [] ],
                    [ 'entityExists', $v, [ 'entity' => 'base.user', 'column' => 'user_id',
                        'errorMsg' => sprintf( \OWA\Core\CoreAPI::t( 'There is no user %s.' ), $v ) ] ],
                ];

            case 'property':

                return [ [ 'entityExists', $v, [ 'entity' => 'base.property', 'column' => 'id',
                    'errorMsg' => sprintf( \OWA\Core\CoreAPI::t( 'There is no Property %s.' ), $v ) ] ] ];

            case 'profile':

                return [
                    [ 'entityExists', $v, [ 'entity' => 'base.site', 'column' => 'site_id',
                        'errorMsg' => sprintf( \OWA\Core\CoreAPI::t( 'There is no Profile %s.' ), $v ) ] ],
                    [ 'profileState', $v, [ 'live' => ! empty( $d['live'] ), 'stream' => $d['stream'] ?? null ] ],
                ];

            case 'module':

                return [ [ 'moduleExists', $v, [] ] ];
        }

        return [];
    }

    /**
     * Run one validation: its message when the value fails, else null. One
     * that throws, such as a lookup given a value it cannot look up, fails.
     *
     * @param  string               $validation as CoreAPI::validationFactory() takes it
     * @param  mixed                $value
     * @param  array<string, mixed> $conf
     * @param  string               $name
     * @return string|null
     */
    protected static function run( $validation, $value, array $conf, $name ) {

        $obj = \OWA\Core\CoreAPI::validationFactory( $validation, $conf );
        $obj->setValues( $value );
        $obj->setName( $name );

        try {

            $obj->validate();

        } catch ( \Exception $e ) {

            $obj->hasError();
        }

        if ( $obj->isValid() ) {

            return null;
        }

        $msg = $obj->getErrorMsg();

        return is_string( $msg ) && $msg !== '' ? $msg : (string) \OWA\Core\CoreAPI::t( 'Not a value this takes.' );
    }

    /**
     * A value as the type reads it, or null when it is not one.
     *
     * @param  array<string, mixed> $d
     * @param  mixed                $value
     * @return mixed
     */
    protected static function convert( array $d, $value ) {

        $type = $d['type'];

        if ( $type === 'flag' ) {

            $v = is_bool( $value ) ? ( $value ? '1' : '0' ) : ( is_scalar( $value ) ? strtolower( (string) $value ) : '' );

            return in_array( $v, self::FLAG_ON, true ) ? true : ( in_array( $v, self::FLAG_OFF, true ) ? false : null );
        }

        if ( ! is_scalar( $value ) || $value === '' || is_bool( $value ) ) {

            return null;
        }

        $v = (string) $value;

        switch ( $type ) {

            case 'integer':
            case 'duration':

                if ( ! preg_match( '/^-?\d+$/', $v ) ) {

                    return null;
                }

                $n = (int) $v;

                return ( isset( $d['min'] ) && $n < $d['min'] ) || ( isset( $d['max'] ) && $n > $d['max'] ) ? null : $n;

            case 'select':

                $items = self::items( $d, $v );

                return array_diff( $items, self::options( $d ) ) ? null : ( empty( $d['list'] ) ? $items[0] : $items );

            case 'string':

                $items = self::items( $d, $v );

                if ( isset( $d['pattern'] ) ) {

                    foreach ( $items as $item ) {

                        if ( preg_match( (string) $d['pattern'], $item ) !== 1 ) {

                            return null;
                        }
                    }
                }

                return empty( $d['list'] ) ? $v : $items;

            case 'date':

                return \OWA\Core\Validation\Date::resolve( $v, ! empty( $d['ago'] ) );
        }

        return $v;
    }

    /**
     * A list parameter's items, without empty ones; otherwise the value alone.
     *
     * @param  array<string, mixed> $d
     * @param  string               $v
     * @return list<string>
     */
    protected static function items( array $d, $v ) {

        if ( empty( $d['list'] ) ) {

            return [ $v ];
        }

        return array_values( array_filter( array_map( 'trim', explode( ',', $v ) ), static fn ( $item ) => $item !== '' ) );
    }

    /**
     * A parameter as it was given: --all=maybe, months-ahead=abc.
     *
     * @param  array<string, mixed> $d
     * @param  mixed                $value
     * @return string
     */
    protected static function given( array $d, $value ) {

        $dashes = str_starts_with( $d['key'], '--' ) || $d['type'] === 'flag' ? '--' : '';
        $name   = self::name( $d['key'] );

        if ( $value === true ) {

            return '--' . $name;
        }

        return $dashes . $name . '=' . ( is_scalar( $value ) ? (string) $value : '' );
    }
}
