<?php

/*
 * The channel rules: what kind of traffic a session is, from its source,
 * medium and campaign (Classes\Cube\ChannelStep).
 *
 * ORDERED, FIRST MATCH WINS. A session matching no rule is Unassigned. AI Agent
 * sits before the organic rules, so an assistant's referral is never Referral
 * and gemini.google.com is never Organic Search.
 *
 * TO CHANGE THEM, put a file of the same name in the data directory. It
 * REPLACES this one rather than merging with it, because order is the point:
 * a merged list would append a new rule after every existing one. A changed
 * rule reaches history when the cube is rebuilt (cmd=cube-rebuild).
 *
 * A rule is [ 'channel' => name, 'any' | 'all' => conditions ]. A condition is
 * [ field, operator, value ] -- or a nested [ 'any' | 'all' => conditions ].
 *
 *   field      source, medium, campaign           compared lowercased
 *   operator   equals, one_of, contains, starts_with, ends_with, regex,
 *              in_list (a site list: search, social, ai, video, shopping)
 */

// A paid medium: cpc, ppc, cpm, retargeting, paid-anything.
$paid = array( 'medium', 'regex', '^(.*cp.*|ppc|retargeting|paid.*)$' );

// A shopping campaign: shop or shopping in its name.
$shop_campaign = array( 'campaign', 'regex', '^(.*(([^a-df-z]|^)shop|shopping).*)$' );

$email = array( 'email', 'e-mail', 'e_mail', 'e mail' );

return array(
	array( 'channel' => 'Direct', 'all' => array(
		array( 'source', 'equals', '(direct)' ),
		array( 'medium', 'one_of', array( '(not set)', '(none)' ) ),
	) ),
	array( 'channel' => 'Cross-network', 'any' => array(
		array( 'campaign', 'contains', 'cross-network' ),
	) ),
	array( 'channel' => 'Paid Shopping', 'all' => array(
		array( 'any' => array( array( 'source', 'in_list', 'shopping' ), $shop_campaign ) ),
		$paid,
	) ),
	array( 'channel' => 'Paid Search', 'all' => array( array( 'source', 'in_list', 'search' ), $paid ) ),
	array( 'channel' => 'Paid Social', 'all' => array( array( 'source', 'in_list', 'social' ), $paid ) ),
	array( 'channel' => 'Paid Video', 'all' => array( array( 'source', 'in_list', 'video' ), $paid ) ),
	array( 'channel' => 'Display', 'any' => array(
		array( 'medium', 'one_of', array( 'display', 'banner', 'expandable', 'interstitial', 'cpm' ) ),
	) ),
	array( 'channel' => 'Paid Other', 'any' => array( $paid ) ),
	array( 'channel' => 'AI Agent', 'any' => array(
		array( 'medium', 'one_of', array( 'ai-agent', 'ai-assistant' ) ),
		array( 'source', 'in_list', 'ai' ),
	) ),
	array( 'channel' => 'Organic Shopping', 'any' => array( array( 'source', 'in_list', 'shopping' ), $shop_campaign ) ),
	array( 'channel' => 'Organic Social', 'any' => array(
		array( 'source', 'in_list', 'social' ),
		array( 'medium', 'one_of', array( 'social', 'social-network', 'social-media', 'sm', 'social network', 'social media' ) ),
	) ),
	array( 'channel' => 'Organic Video', 'any' => array(
		array( 'source', 'in_list', 'video' ),
		array( 'medium', 'contains', 'video' ),
	) ),
	array( 'channel' => 'Organic Search', 'any' => array(
		array( 'source', 'in_list', 'search' ),
		array( 'medium', 'equals', 'organic' ),
	) ),
	array( 'channel' => 'Referral', 'any' => array(
		array( 'medium', 'one_of', array( 'referral', 'app', 'link' ) ),
	) ),
	array( 'channel' => 'Email', 'any' => array(
		array( 'source', 'one_of', $email ),
		array( 'medium', 'one_of', $email ),
	) ),
	array( 'channel' => 'Affiliates', 'any' => array( array( 'medium', 'equals', 'affiliate' ) ) ),
	array( 'channel' => 'Audio', 'any' => array( array( 'medium', 'equals', 'audio' ) ) ),
	array( 'channel' => 'SMS', 'any' => array(
		array( 'source', 'equals', 'sms' ),
		array( 'medium', 'equals', 'sms' ),
	) ),
	array( 'channel' => 'Mobile Push Notifications', 'any' => array(
		array( 'medium', 'ends_with', 'push' ),
		array( 'medium', 'contains', 'mobile' ),
		array( 'medium', 'contains', 'notification' ),
		array( 'source', 'equals', 'firebase' ),
	) ),
);

?>
