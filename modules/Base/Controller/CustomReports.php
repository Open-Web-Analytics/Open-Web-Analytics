<?php
namespace OWA\Module\Base\Controller;

/**
 * The custom report roster.
 *
 * GATED ON view_site_list, which is the capability that means "any signed-in
 * user". Not edit_reports -- a reader who cannot author a report can still be
 * sent one, and the roster is where they find what they have. And not
 * view_reports either, which looks like the obvious choice and is wrong:
 * view_reports is listed in capabilitiesThatRequireSiteAccess, so it is only
 * satisfied against a particular site, and a request for the roster need name
 * none. Requiring it refused every non-admin with "No access to any site",
 * which is what the e2e suite caught.
 *
 * It lists the reports of ONE PROPERTY, the one pre() resolved: a report
 * belongs to the Property it was made on, and is listed there alone.
 *
 * What edit_reports changes is whether the New and Edit controls appear.
 *
 * WHAT THE ROSTER FILTERS, AND WHAT IT DOES NOT
 *
 * Ownership decides what is LISTED and what may be opened, the same set
 * (CustomReports::mayView()): an admin sees every report; everyone else the
 * ones they created and the ones their authors have shared.
 */
class CustomReports extends \OWA\Core\ReportController {

    public function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'view_site_list' );
    }

    public function action() {

        $user    = \OWA\Core\CoreAPI::getCurrentUser();
        $user_id = (string) $user->getUserData( 'user_id' );

        /*
         * "May see everything" is asked as a CAPABILITY, not by comparing the
         * role name to 'admin'. Roles are configurable -- an installation can
         * grant edit_users to a role of its own -- so a string comparison here
         * would answer differently from the rest of the application.
         */
        $sees_all = (bool) $user->isCapable( 'edit_users' );

        /*
         * The sort rides on the URL rather than being remembered, so a link to
         * the roster shows the same order to whoever opens it. Both halves come
         * from the request and neither is trusted: the column is resolved
         * through an allowlist, and the direction is a boolean.
         */
        $sort = (string) $this->getParam( 'rosterSort' );

        if ( ! isset( \OWA\Module\Base\Classes\CustomReports::ROSTER_SORTS[ $sort ] ) ) {

            $sort = \OWA\Module\Base\Classes\CustomReports::ROSTER_DEFAULT_SORT;
        }

        $descending = $this->getParam( 'rosterDesc' );
        $descending = $descending === null || $descending === ''
            ? null
            : (bool) $descending;

        /*
         * "Just mine" rides on the URL like the sort does, so a link to the
         * roster shows the same list to whoever opens it. It narrows and cannot
         * widen, so it needs no capability of its own.
         */
        $mine_only = (bool) $this->getParam( 'rosterMine' );

        /*
         * A THIRD state of the same control, not a second control: All, Just
         * mine, Favorites. They are alternatives -- "my favourites" and "my
         * reports" are different questions and answering both at once would
         * need a filter that says which.
         */
        $favorites_only = (bool) $this->getParam( 'rosterFavorites' );

        if ( $favorites_only ) {

            $mine_only = false;
        }

        $reports = \OWA\Module\Base\Classes\CustomReports::roster(
            $user_id, $sees_all, $sort, $descending, null, $this->rosterType(),
            $mine_only, $favorites_only, (string) $this->getParam( 'propertyId' ) );

        // What the headings need to draw themselves: which one is active, and
        // which way, so each can link to the OPPOSITE of what it shows now.
        $this->set( 'roster_sort', $sort );
        $this->set( 'roster_desc', $descending === null
            ? ( $sort === \OWA\Module\Base\Classes\CustomReports::ROSTER_DEFAULT_SORT )
            : $descending );

        /*
         * Which ones cannot be drawn, and why. Here and not in roster(): the
         * navigation reads the roster on every page, and this costs about half
         * a millisecond a report. A report saved before an upgrade can name
         * something that no longer exists, and it should say so in the list,
         * not only when someone opens it.
         *
         * Against the report's own Property, which is the one the roster is
         * on: one grouped by a custom dimension draws only where it is applied.
         */
        foreach ( $reports as $i => $report ) {

            $reports[ $i ]['kind']    = self::kindOf( $report );
            $reports[ $i ]['invalid'] = $reports[ $i ]['kind'] === 'report'
                ? \OWA\Module\Base\Classes\CustomReports::validate(
                    (array) ( $report['definition'] ?? [] ),
                    \OWA\Module\Base\Classes\CustomReports::propertyOf( $report ) )
                : '';
        }

        $this->set( 'custom_reports', $reports );
        $this->set( 'roster_count', count( $reports ) );
        $this->set( 'sees_all', $sees_all );
        $this->set( 'roster_mine', $mine_only );
        $this->set( 'roster_favorites', $favorites_only );
        $this->set( 'may_author', (bool) $user->isCapable( 'edit_reports' ) );
        $this->set( 'current_user_id', $user_id );
    }

    /**
     * Which kinds this roster lists: both. Reports and funnels are one list,
     * told apart by its Type column.
     *
     * @return string|null
     */
    protected function rosterType() {

        return null;
    }

    /**
     * What the Type column calls a row: 'report', or the visualization's own
     * kind ('funnel').
     *
     * @param array<string, mixed> $report a roster row
     * @return string
     */
    public static function kindOf( array $report ) {

        if ( ( $report['report_type'] ?? '' ) !== \OWA\Module\Base\Entity\CustomReport::TYPE_VISUALIZATION ) {

            return 'report';
        }

        $type = (string) ( $report['visualization_type'] ?? '' );

        return isset( \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES[ $type ] )
            ? $type
            : (string) array_key_first( \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES );
    }

    public function success() {

        $this->setSubview( 'base.customReports', [
            'template' => 'custom_reports.php',
            'vars'     => [ 'custom_reports', 'roster_count',
                            // Empty for a reader who may not author, which is what stops the
                            // "what do you want to make" modal rendering for them.
                            'new_kinds',
                            'sees_all', 'may_author', 'current_user_id', 'roster_sort', 'roster_desc',
                            'roster_mine', 'roster_favorites' ] ] );
        $this->setView( 'base.report' );
        $this->set( 'title', \OWA\Core\CoreAPI::t( 'Custom reports' ) );

        /*
         * New sits on the title's line, because it acts on the whole screen.
         * Offered only to someone who may author one -- a reader who cannot
         * would get a button that leads to a refusal.
         *
         * $this->data, NOT $this->get(): Controller::get() reads the REQUEST.
         *
         * Hooked by the roster template, which asks what to make (a report or
         * a funnel) in a modal. Still a real link: with no JavaScript it opens
         * the report builder, on this Property. A Template made here has no
         * link state of its own, so it is given this request's.
         */
        if ( ! empty( $this->data['may_author'] ) ) {

            $state = array_intersect_key( $this->params, array_flip( \OWA\Core\View::LINK_STATE_KEYS ) );

            $this->set( 'title_actions', [
                [
                    'url'   => ( new \OWA\Core\Template( null, [ 'link_state' => $state ] ) )
                                   ->makeLink( [ 'do' => 'base.customReportEdit' ], true ),
                    'label' => \OWA\Core\CoreAPI::t( 'New' ),
                    'icon'  => 'fa-plus',
                    'class' => 'owa_newCustomReport is-primary',
                ],
            ] );

            $this->set( 'new_kinds', self::newKinds() );
        }

        /*
         * The reporting NAV stays: the roster is part of the reporting UI, and
         * a reader who opens it should be able to get anywhere else from it
         * without going back first.
         *
         * The period picker and Live View do NOT: this is a list of reports,
         * not a report of a time range, and neither control would change
         * anything on the page.
         *
         * THE SITES FILTER STAYS. The list is of the Property it shows, and
         * every report link on it carries that propertyId (add_state). It was
         * once hidden on the reasoning that a custom report was site-agnostic,
         * which left that state invisible and unchangeable, and read as the
         * tile going missing.
         */
        $this->hideTimeControls();
    }

    /**
     * What + New offers, in order: a report, then each visualization.
     *
     * @return array<string, array{label: string, hint: string, icon: string, params: array<string, string>}> key => the builder's link
     */
    public static function newKinds() {

        $kinds = [
            'report' => [
                'label'  => \OWA\Core\CoreAPI::t( 'Report' ),
                'hint'   => \OWA\Core\CoreAPI::t( 'Tables, trends and totals laid out on a grid. You pick the metrics and dimensions for each widget.' ),
                'icon'   => 'fas fa-th-large',
                'params' => [ 'do' => 'base.customReportEdit' ],
            ],
        ];

        foreach ( \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES as $key => $label ) {

            $kinds[ $key ] = [
                'label'  => \OWA\Core\Strings::translate( $label ),
                'hint'   => \OWA\Core\Strings::translate( \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_HINTS[ $key ] ),
                'icon'   => \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_ICONS[ $key ],
                'params' => [ 'do' => 'base.visualizationEdit', 'visualizationType' => $key ],
            ];
        }

        return $kinds;
    }
}
