<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The user-agent parser recognises the agent as a crawler: device family Spider.
 *
 * Browscap::parserSaysSpider(), moved here. A second opinion beside
 * UA_ROBOT_TOKEN rather than a replacement: measured against real crawler
 * strings the two disagree in both directions. The token list catches
 * Bytespider, which presents itself as Android Chrome and which the parser calls
 * a phone; the parser catches WhatsApp's fetcher, which carries no token a list
 * would think to include.
 *
 * The answer comes from the regexes file (Browscap::regexesFile()), so a newer
 * file in owa-data/ua-parser/ changes what this catches.
 */
class UaParserSpider extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'UA_PARSER_SPIDER';
    const SET    = 'user_agent';
    const POINTS = 5.0;

    public function test( $event, array $headers ) {

        return strtolower( $this->client( $headers )->deviceFamily() ) === 'spider';
    }
}

?>
