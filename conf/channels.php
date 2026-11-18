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
$paid = [ 'medium', 'regex', '^(.*cp.*|ppc|retargeting|paid.*)$' ];

// A shopping campaign: shop or shopping in its name.
$shop_campaign = [ 'campaign', 'regex', '^(.*(([^a-df-z]|^)shop|shopping).*)$' ];

$email = [ 'email', 'e-mail', 'e_mail', 'e mail' ];

// A social source: a site on the social list, or a tag a network adds to its
// own links -- Instagram's utm_source=ig, and Threads' ig_text_<where>.
$social = [ 'any' => [
    [ 'source', 'in_list', 'social' ],
    [ 'source', 'equals', 'ig' ],
    [ 'source', 'starts_with', 'ig_text_' ],
] ];

return [
    [ 'channel' => 'Direct', 'all' => [
        [ 'source', 'equals', '(direct)' ],
        [ 'medium', 'one_of', [ '(not set)', '(none)' ] ],
    ] ],
    [ 'channel' => 'Cross-network', 'any' => [
        [ 'campaign', 'contains', 'cross-network' ],
    ] ],
    [ 'channel' => 'Paid Shopping', 'all' => [
        [ 'any' => [ [ 'source', 'in_list', 'shopping' ], $shop_campaign ] ],
        $paid,
    ] ],
    [ 'channel' => 'Paid Search', 'all' => [ [ 'source', 'in_list', 'search' ], $paid ] ],
    [ 'channel' => 'Paid Social', 'all' => [ $social, $paid ] ],
    [ 'channel' => 'Paid Video', 'all' => [ [ 'source', 'in_list', 'video' ], $paid ] ],
    [ 'channel' => 'Display', 'any' => [
        [ 'medium', 'one_of', [ 'display', 'banner', 'expandable', 'interstitial', 'cpm' ] ],
    ] ],
    [ 'channel' => 'Paid Other', 'any' => [ $paid ] ],
    [ 'channel' => 'AI Agent', 'any' => [
        [ 'medium', 'one_of', [ 'ai-agent', 'ai-assistant' ] ],
        [ 'source', 'in_list', 'ai' ],
    ] ],
    [ 'channel' => 'Organic Shopping', 'any' => [ [ 'source', 'in_list', 'shopping' ], $shop_campaign ] ],
    [ 'channel' => 'Organic Social', 'any' => [
        $social,
        [ 'medium', 'one_of', [ 'social', 'social-network', 'social-media', 'sm', 'social network', 'social media' ] ],
    ] ],
    [ 'channel' => 'Organic Video', 'any' => [
        [ 'source', 'in_list', 'video' ],
        [ 'medium', 'contains', 'video' ],
    ] ],
    [ 'channel' => 'Organic Search', 'any' => [
        [ 'source', 'in_list', 'search' ],
        [ 'medium', 'equals', 'organic' ],
    ] ],
    [ 'channel' => 'Referral', 'any' => [
        [ 'medium', 'one_of', [ 'referral', 'app', 'link' ] ],
    ] ],
    [ 'channel' => 'Email', 'any' => [
        [ 'source', 'one_of', $email ],
        [ 'medium', 'one_of', $email ],
    ] ],
    [ 'channel' => 'Affiliates', 'any' => [ [ 'medium', 'equals', 'affiliate' ] ] ],
    [ 'channel' => 'Audio', 'any' => [ [ 'medium', 'equals', 'audio' ] ] ],
    [ 'channel' => 'SMS', 'any' => [
        [ 'source', 'equals', 'sms' ],
        [ 'medium', 'equals', 'sms' ],
    ] ],
    [ 'channel' => 'Mobile Push Notifications', 'any' => [
        [ 'medium', 'ends_with', 'push' ],
        [ 'medium', 'contains', 'mobile' ],
        [ 'medium', 'contains', 'notification' ],
        [ 'source', 'equals', 'firebase' ],
    ] ],
];
