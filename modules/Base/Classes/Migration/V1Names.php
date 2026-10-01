<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * 1.14's metric and dimension names, and what each is in v2.
 *
 * Every name 1.14 registered is a key (read from the 1.14.0 tag). The value is
 * the v2 name, or null where v2 has no equivalent. There is no alias layer:
 * names resolve exactly, so a saved report is rewritten once, by the migration
 * (PLAN.html 2.21).
 *
 * REDEFINED names keep or take a v2 name whose meaning differs; the rewrite
 * logs them so nobody reads the new numbers as the old.
 */
class V1Names {

    const METRICS = array(
        'pageViews'               => 'pageViews',
        'visits'                  => 'sessions',
        'uniqueVisitors'          => 'totalUsers',
        'visitors'                => 'totalUsers',
        'newVisitors'             => 'newUsers',
        'pagesPerVisit'           => 'pageViewsPerSession',
        'revenuePerVisit'         => 'revenuePerSession',
        'visitDuration'           => 'averageEngagementTimePerSession',
        'bounces'                 => 'bouncedSessions',
        'bounceRate'              => 'bounceRate',
        'goalCompletionsAll'      => 'goalConversions',
        'goalConversionRateAll'   => 'goalConversionRatePerSession',
        'transactions'            => 'transactions',
        'transactionRevenue'      => 'transactionRevenue',
        'taxRevenue'              => 'taxRevenue',
        'shippingRevenue'         => 'shippingRevenue',
        'revenuePerTransaction'   => 'revenuePerTransaction',
        'ecommerceConversionRate' => 'ecommerceConversionRate',
        'repeatVisitors'          => null,
        'uniquePageViews'         => null,
        'actions'                 => null,
        'uniqueActions'           => null,
        'actionsValue'            => null,
        'feedRequests'            => null,
        'feedReaders'             => null,
        'feedSubscriptions'       => null,
        'goalStartsAll'           => null,
        'goalValueAll'            => null,
        'goalAbandonRateAll'      => null,
        'lineItemQuantity'        => null,
        'lineItemRevenue'         => null,
        'uniqueLineItems'         => null,
        'domClicks'               => null,
    );

    const DIMENSIONS = array(
        'date'                  => 'date',
        'day'                   => 'day',
        'month'                 => 'month',
        'year'                  => 'year',
        'dayofweek'             => 'dayOfWeek',
        'dayofyear'             => 'dayOfYear',
        'weekofyear'            => 'weekOfYear',
        'siteId'                => 'siteId',
        'sessionId'             => 'sessionId',
        'visitorId'             => 'clientId',
        'userName'              => 'userId',
        'browserType'           => 'browserType',
        'browserVersion'        => 'browserVersion',
        'osType'                => 'osType',
        'city'                  => 'city',
        'country'               => 'country',
        'countryCode'           => 'countryCode',
        'stateRegion'           => 'stateRegion',
        'language'              => 'language',
        'ipAddress'             => 'ipAddress',
        'clickX'                => 'clickX',
        'clickY'                => 'clickY',
        'domElementId'          => 'domElementId',
        'domElementTag'         => 'domElementTag',
        'transactionId'         => 'transactionId',
        'pageTitle'             => 'pageTitle',
        // v1's page path was the URI, query included.
        'pagePath'              => 'pagePathPlusQuery',
        'pageUrl'               => 'pageLocation',
        'entryPagePath'         => 'landingPagePlusQuery',
        'entryPageUrl'          => 'landingPageLocation',
        'entryPageTitle'        => 'landingPageTitle',
        'source'                => 'sessionSource',
        'medium'                => 'sessionMedium',
        'campaign'              => 'sessionCampaign',
        'ad'                    => 'sessionAd',
        'referralSearchTerms'   => 'sessionSearchTerms',
        'referralPageUrl'       => 'pageReferrer',
        'referralWebSite'       => 'referrerHost',
        'priorVisitCount'       => 'priorSessionCount',
        'isNewVisitor'          => 'newVsReturning',
        'isRepeatVisitor'       => 'newVsReturning',
        // v1: the VISITOR's network host. v2's hostName is the page's host.
        'hostName'              => null,
        'actionGroup'           => null,
        'actionLabel'           => null,
        'actionName'            => null,
        'adType'                => null,
        'customVarName'         => null,
        'customVarValue'        => null,
        'daysSinceFirstVisit'   => null,
        'daysSinceLastVisit'    => null,
        'daysToTransaction'     => null,
        'distinctItemsInVisit'  => null,
        'domElementClass'       => null,
        'domElementName'        => null,
        'domElementText'        => null,
        'domElementValue'       => null,
        'entryPageType'         => null,
        'exitPagePath'          => null,
        'exitPageTitle'         => null,
        'exitPageType'          => null,
        'exitPageUrl'           => null,
        'feedType'              => null,
        'goalStartsInVisit'     => null,
        'goalValueInVisit'      => null,
        'goalsInVisit'          => null,
        'isSearchEngine'        => null,
        'itemQuantityInVisit'   => null,
        'itemRevenueInVisit'    => null,
        'latestAttributions'    => null,
        'latitude'              => null,
        'longitude'             => null,
        'pageType'              => null,
        'pagesViewsInVisit'     => null,
        'priorPagePath'         => null,
        'priorPageTitle'        => null,
        'priorPageType'         => null,
        'priorPageUrl'          => null,
        'productCategory'       => null,
        'productName'           => null,
        'productSku'            => null,
        'referralLinkText'      => null,
        'referralPageTitle'     => null,
        'revenueInVisit'        => null,
        'shippingRevenueInVisit' => null,
        'siteDomain'            => null,
        'siteName'              => null,
        'taxRevenueInVisit'     => null,
        'timestamp'             => null,
        'transactionGateway'    => null,
        'transactionOriginator' => null,
        'transactionsInVisit'   => null,
        'userEmail'             => null,
        'visitsToTransaction'   => null,
    );

    /** Kept or mapped, with a different meaning. */
    const REDEFINED = array(
        'bounceRate'    => 'now the share of sessions that were not engaged',
        'bounces'       => 'now sessions that were not engaged',
        'visitDuration' => 'now average engagement time, in milliseconds',
        'pagePath'      => 'kept as pagePathPlusQuery, which includes the query string as v1 did',
    );

    /**
     * A constraint on a merged dimension, by value. isNewVisitor==1 is
     * newVsReturning==New.
     */
    const VALUES = array(
        'isNewVisitor'    => array( '1' => 'New', '0' => 'Returning', 'true' => 'New', 'false' => 'Returning' ),
        'isRepeatVisitor' => array( '1' => 'Returning', '0' => 'New', 'true' => 'Returning', 'false' => 'New' ),
    );

    /**
     * A v1 medium constraint whose meaning moved in v2, as [ dimension, value ].
     *
     * v1 put its classification in medium; v2's medium is organic,
     * referral, (none) -- and puts the classification in the channel. So a v1
     * report filtered on medium==organic-search filters on the Organic Search
     * channel here rather than on a medium value nothing holds any more. A
     * tagged medium (email, cpc) means the same in both and is left alone.
     */
    const MEDIUM_VALUES = array(
        'organic-search' => array( 'sessionChannel', 'Organic Search' ),
        'social-network' => array( 'sessionChannel', 'Organic Social' ),
        'referral'       => array( 'sessionChannel', 'Referral' ),
        'direct'         => array( 'sessionChannel', 'Direct' ),
    );

    /**
     * @param  string $name a 1.14 metric
     * @return array  [ 'known' => bool, 'to' => string|null ]
     */
    public static function metric( $name ) {

        $name = (string) $name;

        if ( array_key_exists( $name, self::METRICS ) ) {

            return array( 'known' => true, 'to' => self::METRICS[ $name ] );
        }

        // goal1Completions .. goal15Value: the numbered goal slots are gone.
        if ( preg_match( '/^goal\d+(Completions|Starts|Value)$/', $name ) ) {

            return array( 'known' => true, 'to' => null );
        }

        return array( 'known' => false, 'to' => $name );
    }

    /**
     * @param  string $name a 1.14 dimension
     * @return array  [ 'known' => bool, 'to' => string|null ]
     */
    public static function dimension( $name ) {

        $name = (string) $name;

        if ( array_key_exists( $name, self::DIMENSIONS ) ) {

            return array( 'known' => true, 'to' => self::DIMENSIONS[ $name ] );
        }

        // customVarName1 .. customVarValueN: the numbered slots.
        if ( preg_match( '/^customVar(Name|Value)\d+$/', $name ) ) {

            return array( 'known' => true, 'to' => null );
        }

        return array( 'known' => false, 'to' => $name );
    }
}

?>
