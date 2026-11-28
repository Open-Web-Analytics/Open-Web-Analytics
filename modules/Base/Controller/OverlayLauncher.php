<?php
namespace OWA\Module\Base\Controller;

/**
 * Overlay Launcher Controller
 */

class OverlayLauncher extends \OWA\Core\Controller {

    /**
     * What a heatmap's data request groups clicks by.
     *
     * The element anchor and the point inside it are what the overlay draws a
     * click by. A click with no anchor is drawn by its page coordinate, and only
     * when viewportWidth says its window was as wide as the reader's. Shared
     * with the report widget's heatmap link, which builds the same request.
     */
    const HEATMAP_DIMENSIONS = 'elementAnchor,anchorX,anchorY,clickX,clickY,viewportWidth';

    public function action() {

        // A row's heatmap: one exact page, or a path and every query string
        // of it. See openPage().
        if ( (string) $this->get('pagePathPlusQuery') !== '' ) {

            $this->openPage( (string) $this->get('pagePathPlusQuery') );

            return;
        }

        if ( (string) $this->get('pagePath') !== '' ) {

            $this->openPage( (string) $this->get('pagePath'), true );
        }
    }

    /**
     * The heatmap of one page -- a path and its query string -- opened on the
     * page itself; or BY PATH, the clicks of every query string of the path,
     * opened on the path with none, as a Pages row groups them. A path's
     * query strings are mostly a link's tracking (a subscriber id, a
     * campaign) on one page.
     *
     * NOTHING TO LOOK UP. The url is the Profile's domain with the path and the
     * query string; the clicks drawn are constrained to that same
     * pagePathPlusQuery for the report's period.
     *
     * The data link is minted here, at the click, because a row only exists in
     * the browser: the report could not have minted one per row.
     *
     * Only for a Property the user can see.
     *
     * @param string $value  pagePathPlusQuery, or pagePath when $byPath
     * @param bool   $byPath every query string of the path
     * @return void
     */
    private function openPage( $value, $byPath = false ) {

        $propertyId = (string) $this->get('propertyId');

        if ( $propertyId === ''
             || ! \OWA\Core\CoreAPI::getCurrentUser()->isCapableOnProperty( 'view_reports', $propertyId ) ) {

            return;
        }

        $mark  = $byPath ? false : strpos( $value, '?' );
        $path  = $mark === false ? $value : substr( $value, 0, $mark );
        $query = $mark === false ? '' : substr( $value, $mark + 1 );

        if ( $path === '' || $path[0] !== '/'
             || ( $byPath && strpos( $value, '?' ) !== false ) ) {

            return;
        }

        $period = $this->periodParams();
        $base   = $this->baseUrlFor( $propertyId, $path, $byPath ? null : $query, $period );

        if ( $base === '' ) {

            return;
        }

        $url = $base . $path . ( $query !== '' ? '?' . $query : '' );

        $template = new \OWA\Core\Template();

        $api = $template->makeOverlayApiLink( [
            'do'             => 'reports',
            'module'         => 'base',
            'version'        => 'v1',
            'propertyId'     => $propertyId,
            'metrics'        => 'eventCount',
            'dimensions'     => self::HEATMAP_DIMENSIONS,
            'constraints'    => ( $byPath ? 'pagePath==' : 'pagePathPlusQuery==' )
                . urlencode( \OWA\Module\Base\Classes\ResultSetManager::constraintValue( $value ) )
                . ',eventName==click',
            'resultsPerPage' => 1000,
            'format'         => 'json',
        ] + $period, 'constraints' );

        $overlay = base64_encode( $template->makeParamString(
            [ 'action' => 'loadHeatmap', 'api_url' => $api ], false, 'json' ) );

        $url .= '#owa_overlay.' . rawurlencode( $overlay );

        // The tracked site is another host, vouched for: it is the Profile's own.
        $this->redirectBrowserToUrl( $url, [ (string) parse_url( $url, PHP_URL_HOST ) ] );
        $this->set( 'url', $url );
    }

    /**
     * The report's period as the request carries it: a named period or a range.
     *
     * @return array<string, string> period, or startDate and endDate
     */
    private function periodParams() {

        if ( $this->get('startDate') && $this->get('endDate') ) {

            return [ 'startDate' => (string) $this->get('startDate'), 'endDate' => (string) $this->get('endDate') ];
        }

        return [ 'period' => (string) ( $this->get('period') ?: 'last_thirty_days' ) ];
    }

    /**
     * scheme://host[:port] to open the page on.
     *
     * The Profile is the Property's only one, or the one with the most clicks
     * on the page in the period -- over the Profiles and the dates, which the
     * cube indexes.
     *
     * The scheme and host are those of the page as recorded: the most clicked
     * page_location of that Profile's clicks on the page that is http or https
     * and on its domain (ProfileDomains::originOf()). A Profile's domain carries no scheme,
     * and the page may be served on a subdomain of it. With no such click,
     * the domain itself, over https. An archived or app Profile is passed over.
     *
     * @param string      $propertyId
     * @param string      $path
     * @param string|null $query null for every query string of the path
     * @param array<string, string> $period periodParams()'s
     * @return string '' when no Profile has a domain
     */
    private function baseUrlFor( $propertyId, $path, $query, array $period ) {

        $site_ids = \OWA\Module\Base\Classes\Cube\Cubes::siteIds( $propertyId );

        $tp = new \OWA\Module\Base\Classes\TimePeriod();
        $tp->set( isset( $period['period'] ) ? $period['period'] : 'date_range',
            isset( $period['period'] ) ? [] : $period );

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $propertyId );
        $cube  = $db->tableExists( $table );

        // The page's clicks in the period, for the Profiles named: [ SQL, bindings ].
        $where = function ( array $ids ) use ( $tp, $path, $query ) {

            $params   = array_map( 'strval', array_values( $ids ) );
            $params[] = (string) $path;

            if ( $query !== null && $query !== '' ) {

                $params[] = (string) $query;
            }

            return [ sprintf(
                "site_id IN (%s) AND event_type = 'click' AND yyyymmdd BETWEEN %d AND %d AND page_path = ? AND %s",
                \OWA\Core\Db::placeholders( count( $ids ) ),
                (int) $tp->startDate->get( 'yyyymmdd' ), (int) $tp->endDate->get( 'yyyymmdd' ),
                $query === null ? '1 = 1'
                    : ( $query === '' ? "(page_query IS NULL OR page_query = '')" : 'page_query = ?' ) ), $params ];
        };

        if ( $cube && count( $site_ids ) > 1 ) {

            list( $sql, $params ) = $where( $site_ids );

            $row = $db->get_row( sprintf(
                'SELECT site_id FROM %s WHERE %s GROUP BY site_id ORDER BY COUNT(*) DESC LIMIT 1',
                $table, $sql ), $params );

            if ( is_array( $row ) && ! empty( $row['site_id'] ) ) {

                array_unshift( $site_ids, (string) $row['site_id'] );
            }
        }

        foreach ( $site_ids as $site_id ) {

            // A live web Profile only, as the REST API's CORS rule answers.
            $domains = \OWA\Module\Base\Classes\ProfileDomains::of( [ $site_id ] );

            if ( ! $domains ) {

                continue;
            }

            $site = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

            $domain = trim( (string) $site->get( 'domain' ) );
            $parts  = parse_url( strpos( $domain, '//' ) === false ? 'https://' . $domain : $domain );

            if ( empty( $parts['host'] ) ) {

                continue;
            }

            if ( $cube ) {

                list( $sql, $params ) = $where( [ $site_id ] );

                $rows = $db->get_results( sprintf(
                    'SELECT page_location FROM %s WHERE %s GROUP BY page_location ORDER BY COUNT(*) DESC LIMIT %d',
                    $table, $sql, self::CANDIDATES ), $params );

                foreach ( (array) $rows as $row ) {

                    $origin = \OWA\Module\Base\Classes\ProfileDomains::originOf( (string) ( $row['page_location'] ?? '' ), $domains );

                    if ( $origin !== '' ) {

                        return $origin;
                    }
                }
            }

            return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host']
                . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
        }

        return '';
    }

    /** How many of the most-clicked urls are tried for one on a Profile's domain. */
    const CANDIDATES = 25;
}
