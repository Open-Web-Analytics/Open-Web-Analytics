<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * campaign, and acq_campaign.
 *
 *   tagged with a campaign        the campaign, as collected
 *   tagged, but with no campaign  NULL -- labelled (not set)
 *   untagged                      a placeholder named for how it arrived:
 *                                 (direct), (organic), (ai-agent), (referral)
 *
 * THE PLACEHOLDERS put every untagged visit in a campaign row named for how it
 * came, rather than one (not set) row holding most of the site's traffic.
 *
 * THEY MIRROR THE MEDIUM, from the same expression MediumStep uses for an
 * untagged visit, so a campaign row and its medium row count the same
 * sessions. A tagged session with no campaign is (not set), not (referral):
 * filing it there would make the (referral) row disagree with the referral
 * medium row, and (not set) shows a site a link missing its utm_campaign.
 */
class CampaignStep extends Step {

    const PLACEHOLDERS = array(
        '(none)'       => '(direct)',
        'organic'      => '(organic)',
        'ai-agent'     => '(ai-agent)',
    );

    const FALLBACK = '(referral)';

    /** @var string */
    private $campaign;

    /** @var string[] the other tags, any of which makes the visit tagged */
    private $tags;

    /** @var MediumStep */
    private $medium;

    /** @var string */
    private $join;

    /** @var string|null */
    private $absent;

    /**
     * @param string      $column
     * @param string      $campaign the tagged campaign
     * @param string[]    $tags     the visit's other tags
     * @param MediumStep  $medium   the same visit's medium, for the placeholder
     * @param string      $join
     * @param string|null $absent
     */
    function __construct( $column, $campaign, array $tags, MediumStep $medium, $join, $absent = null ) {

        parent::__construct( $column );

        $this->campaign = (string) $campaign;
        $this->tags     = array_map( 'strval', $tags );
        $this->medium   = $medium;
        $this->join     = (string) $join;
        $this->absent   = $absent;
    }

    public function requires() {

        return array( $this->join );
    }

    public function execute( Context $context ) {

        $tagged = array();

        foreach ( $this->tags as $tag ) {

            $tagged[] = sprintf( "NULLIF(TRIM(%s), '') IS NOT NULL", $tag );
        }

        $placeholder = sprintf( 'CASE %s', $this->medium->untagged( $context ) );

        foreach ( self::PLACEHOLDERS as $medium => $label ) {

            $placeholder .= sprintf( ' WHEN %s THEN %s', $context->literal( $medium ), $context->literal( $label ) );
        }

        $placeholder .= sprintf( ' ELSE %s END', $context->literal( self::FALLBACK ) );

        $campaign = $context->text( $this->campaign );

        $resolved = sprintf( 'CASE WHEN %1$s IS NOT NULL THEN %1$s WHEN %2$s THEN NULL ELSE %3$s END',
            $campaign, $tagged ? implode( ' OR ', $tagged ) : '1 = 0', $placeholder );

        if ( $this->absent === null ) {

            return $resolved;
        }

        return sprintf( 'CASE WHEN %s THEN %s ELSE %s END',
            $this->absent, $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ), $resolved );
    }
}

?>
