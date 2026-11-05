<?php
namespace OWA\Module\Base\Classes\Spam;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One spam rule: a name, the rule set it belongs to, its points, and a test.
 *
 * A beacon's score is the sum of the points of the rules it meets (Score), the
 * way a mail server scores a message. A rule says one thing about the beacon;
 * deciding whether that is enough to block it is the threshold's job, so a rule
 * that is often wrong for real visitors gets few points rather than none.
 *
 * TO ADD A RULE: a class under Classes\Spam\Rules extending this one, with NAME
 * (upper case, as it appears in owa_event_raw.spam_rules and owa_spam_block),
 * SET and POINTS, and test(); then list it under its set in RuleSets::SETS, or
 * add it through the spam_rule_sets filter from a module.
 *
 * test() is handed the event after the property stage, so the derived
 * properties are on it (geolocation, the parsed agent, the page's host), and
 * the headers of the beacon request as Score::headers() reads them off the
 * event: lower-case names, '' for a header the request did not carry.
 */
abstract class Rule {

    /** The rule's name. */
    const NAME = '';

    /** The rule set it belongs to (RuleSets). */
    const SET = '';

    /** What meeting it adds to the score: one decimal place, and may be negative. */
    const POINTS = 0.0;

    public function name() {

        return (string) static::NAME;
    }

    public function set() {

        return (string) static::SET;
    }

    public function points() {

        return round( (float) static::POINTS, 1 );
    }

    /**
     * Whether the beacon meets this rule.
     *
     * @param  object $event    the incoming tracking event
     * @param  array  $headers  header name => value, as Score::headers()
     * @return bool
     */
    abstract public function test( $event, array $headers );

    /** What the beacon's user agent claims to be. */
    protected function client( array $headers ) {

        return new Client( (string) ( $headers['user-agent'] ?? '' ) );
    }
}

?>
