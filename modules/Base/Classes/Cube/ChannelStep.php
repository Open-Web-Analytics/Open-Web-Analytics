<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * channel, and acq_channel: what kind of traffic a session is, read from the
 * row's own source, medium and campaign.
 *
 * WHAT MEDIUM CANNOT SAY. Medium is what the link said, or the one fact about
 * the referrer; the channel is what kind of traffic that makes, from a fixed
 * list. It needs the source as well -- an untagged Facebook visit is medium
 * `referral` and channel Organic Social; google / cpc is Paid Search and
 * facebook / cpc is Paid Social -- and it folds a site's own spellings
 * together: email, e-mail and e_mail are all Email.
 *
 * FROM THE ROW'S STORED VALUES, in a statement run after the build
 * statement (Step::after()). So a channel can never disagree with the source,
 * medium and campaign beside it.
 *
 * THE RULES ARE CONFIGURATION, conf/channels.php: an ordered list, first
 * match winning. An install replaces the file to change them, and a rebuild
 * re-applies them to history.
 */
class ChannelStep extends Step {

    const TYPE_LENGTH = 32;

    /** @var string[] the sibling columns: source, medium, campaign */
    private $from;

    /**
     * @param string $column
     * @param string $source   the row's source column
     * @param string $medium   the row's medium column
     * @param string $campaign the row's campaign column
     */
    function __construct( $column, $source, $medium, $campaign ) {

        parent::__construct( $column );

        $this->from = array( 'source' => (string) $source, 'medium' => (string) $medium,
            'campaign' => (string) $campaign );
    }

    /**
     * What the INSERT writes; after() replaces it. Empty rather than a channel
     * name, so a build that skipped after() is visible as rows no rule wrote.
     */
    public function execute( Context $context ) {

        return "''";
    }

    public function after( Context $context, $staging ) {

        return sprintf( 'UPDATE %s SET %s = %s', $staging, $this->column, $this->rules( $context ) );
    }

    /**
     * The rules as one CASE over the row's columns.
     *
     * A row whose source is the sentinel -- an acquisition never captured --
     * keeps the sentinel: a channel of Unassigned would claim a reading.
     *
     * @param  array[]|null $definitions the rules; null reads the configured ones
     * @throws \RuntimeException on a rule the compiler cannot read
     */
    public function rules( Context $context, ?array $definitions = null ) {

        $fields = array(
            'source'   => sprintf( 'LOWER(%s)', $this->from['source'] ),
            'medium'   => sprintf( 'LOWER(%s)', $this->from['medium'] ),
            'campaign' => sprintf( "LOWER(COALESCE(%s, ''))", $this->from['campaign'] ),
        );

        $sentinel = $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED );
        $case     = sprintf( 'CASE WHEN %s = %s THEN %s', $this->from['source'], $sentinel, $sentinel );

        foreach ( self::validate( $definitions ?? self::definitions() ) as $i => $rule ) {

            $case .= sprintf( ' WHEN %s THEN %s',
                self::group( $rule, $fields, $context, sprintf( 'rule %d (%s)', $i + 1, $rule['channel'] ) ),
                $context->literal( $rule['channel'] ) );
        }

        return $case . " ELSE 'Unassigned' END";
    }

    /**
     * The configured rules: conf/channels.php, or the data directory's file of
     * the same name in its place.
     *
     * REPLACED, NOT MERGED, unlike the site lists: the order is the rule set,
     * and a merge could only append.
     *
     * @param  string $data_dir
     * @return array[]
     * @throws \RuntimeException
     */
    public static function definitions( $data_dir = OWA_DATA_DIR ) {

        $file = file_exists( $data_dir . 'channels.php' ) ? $data_dir . 'channels.php' : OWA_CONF_DIR . 'channels.php';

        return self::validate( include $file );
    }

    /**
     * @param  mixed $rules
     * @return array[]
     * @throws \RuntimeException
     */
    private static function validate( $rules ) {

        if ( ! is_array( $rules ) || ! $rules ) {

            throw new \RuntimeException( 'the channel rules are not a list of rules' );
        }

        foreach ( array_values( $rules ) as $i => $rule ) {

            $name = is_array( $rule ) ? (string) ( $rule['channel'] ?? '' ) : '';

            if ( $name === '' || strlen( $name ) > self::TYPE_LENGTH || $name === 'Unassigned' ) {

                throw new \RuntimeException( sprintf(
                    'channel rule %d needs a name of 1 to %d characters, and not Unassigned, which is '
                  . 'what matching none of them means', $i + 1, self::TYPE_LENGTH ) );
            }

            if ( ! isset( $rule['any'] ) && ! isset( $rule['all'] ) ) {

                throw new \RuntimeException( sprintf( 'channel rule %d (%s) has no any or all', $i + 1, $name ) );
            }
        }

        return array_values( $rules );
    }

    /**
     * The channel names, in the order the rules test them, then Unassigned.
     *
     * @param  array[]|null $definitions the rules; null reads the configured ones
     */
    public static function channels( ?array $definitions = null ) {

        $out = array();

        foreach ( self::validate( $definitions ?? self::definitions() ) as $rule ) {

            $out[ $rule['channel'] ] = true;
        }

        return array_merge( array_keys( $out ), array( 'Unassigned' ) );
    }

    /** An any/all group as one parenthesised test. */
    private static function group( array $group, array $fields, Context $context, $where ) {

        $join  = isset( $group['all'] ) ? ' AND ' : ' OR ';
        $items = (array) ( isset( $group['all'] ) ? $group['all'] : $group['any'] );

        if ( ! $items ) {

            throw new \RuntimeException( sprintf( '%s has an empty condition list', $where ) );
        }

        $tests = array();

        foreach ( $items as $item ) {

            $tests[] = ( isset( $item['any'] ) || isset( $item['all'] ) )
                ? self::group( $item, $fields, $context, $where )
                : self::condition( (array) $item, $fields, $context, $where );
        }

        return '(' . implode( $join, $tests ) . ')';
    }

    /** One [ field, operator, value ] as SQL. */
    private static function condition( array $condition, array $fields, Context $context, $where ) {

        list( $field, $operator, $value ) = array_pad( array_values( $condition ), 3, null );

        if ( ! isset( $fields[ $field ] ) ) {

            throw new \RuntimeException( sprintf( '%s tests "%s"; a rule can test %s',
                $where, $field, implode( ', ', array_keys( $fields ) ) ) );
        }

        $expr  = $fields[ $field ];
        $lower = function ( $v ) { return strtolower( (string) $v ); };
        $like  = function ( $v ) use ( $lower ) { return addcslashes( $lower( $v ), '\\%_' ); };

        switch ( $operator ) {

            case 'equals':
                return sprintf( '%s = %s', $expr, $context->literal( $lower( $value ) ) );

            case 'one_of':
                return sprintf( '%s IN (%s)', $expr, implode( ', ',
                    array_map( function ( $v ) use ( $context, $lower ) { return $context->literal( $lower( $v ) ); },
                        (array) $value ) ) );

            case 'contains':
                return sprintf( '%s LIKE %s', $expr, $context->literal( '%' . $like( $value ) . '%' ) );

            case 'starts_with':
                return sprintf( '%s LIKE %s', $expr, $context->literal( $like( $value ) . '%' ) );

            case 'ends_with':
                return sprintf( '%s LIKE %s', $expr, $context->literal( '%' . $like( $value ) ) );

            case 'regex':
                return sprintf( '%s %s %s', $expr, OWA_SQL_REGEXP, $context->literal( (string) $value ) );

            case 'in_list':
                if ( ! isset( SiteLists::FILES[ $value ] ) ) {

                    throw new \RuntimeException( sprintf( '%s names site list "%s"; the lists are %s',
                        $where, $value, implode( ', ', array_keys( SiteLists::FILES ) ) ) );
                }

                return SiteLists::matches( $expr, $value, $context );
        }

        throw new \RuntimeException( sprintf( '%s uses operator "%s"; the operators are equals, one_of, '
          . 'contains, starts_with, ends_with, regex and in_list', $where, $operator ) );
    }
}

?>
