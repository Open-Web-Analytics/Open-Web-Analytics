<?php

/**
 * Base's report navigation: nav group => subgroup => item.
 *
 * Base\Module::registerNavigation() registers each item with
 * addNavigationSubGroup() and each of its `links` with
 * addNavigationLinkInSubGroup(); see Core\Module::registerNavigationFromConfig()
 * for the keys. `report` names a registered report id (reportRef()); `ref`
 * gives the link params instead. Included when the nav is built, so the labels
 * are translated then. `order` places an item among its siblings, lowest first.
 */
return [

    'Reports' => [

        'Dashboard' => [
            'report'     => 'dashboard',
            'anchortext' => \OWA\Core\CoreAPI::t( 'Dashboard' ),
            'order'      => 1,
            'icon_class' => 'fa fa-tachometer-alt',
        ],

        // Second, after the dashboard.
        'Realtime' => [
            'report'     => 'realtime',
            'anchortext' => \OWA\Core\CoreAPI::t( 'Realtime' ),
            'order'      => 2,
            'icon_class' => 'fa fa-bolt',
        ],

        /*
         * The custom report roster. Gated on view_reports, checked against a
         * Profile of the nav's Property as every report link is, rather than
         * on edit_reports: a reader who cannot author one can still be sent
         * one, and the roster is where they find what they have been given.
         * ReportController::withCustomReports() replaces this entry with one
         * carrying the reader's favorites, under the same capability.
         */
        'Custom Reports' => [
            'ref'        => [ 'do' => 'base.customReports' ],
            'anchortext' => \OWA\Core\CoreAPI::t( 'Custom Reports' ),
            'order'      => 9,
            'priviledge' => 'view_reports',
            'icon_class' => 'fa fa-sliders-h',
        ],

        /*
         * Every e-commerce report is in the NAV, not behind a links widget on
         * the overview.
         *
         * days-to-purchase and visits-to-purchase go with them. Each is one
         * dimension -- daysToTransaction, visitsToTransaction -- measuring the
         * gap between acquisition and a purchase, and v2 has built neither. The
         * anchors are on the row (visitor_fsts, prior_sessions), so these come
         * back as computed dimensions rather than needing schema.
         *
         * THE PRODUCT REPORTS ARE NOT BUILT, and the data is kept for them. A
         * purchase's line items are in raw's params.items, from ingest and from
         * the v1 migration (PurchaseMigrator), in the tracker's shape. No cube
         * column or dimension reads them yet, so productName, productSku,
         * productCategory, lineItemRevenue and lineItemQuantity have no v2
         * answer. Items are a list per purchase, so reading them is a row per
         * item -- JSON_TABLE, or an item-grain table a build fills -- not one
         * more cube column.
         */
        'Ecommerce' => [
            'report'     => 'ecommerce',
            'anchortext' => \OWA\Core\CoreAPI::t( 'Ecommerce' ),
            'order'      => 6,
            'icon_class' => 'fa fa-shopping-cart',
            'links'      => [
                [ 'report' => 'revenue',                   'anchortext' => \OWA\Core\CoreAPI::t( 'Revenue' ),             'order' => 2 ],
                [ 'report' => 'transactions',              'anchortext' => \OWA\Core\CoreAPI::t( 'Transactions' ),        'order' => 3 ],
                [ 'report' => 'avg-order-value',           'anchortext' => \OWA\Core\CoreAPI::t( 'Average Order Value' ), 'order' => 4 ],
                [ 'report' => 'ecommerce-conversion-rate', 'anchortext' => \OWA\Core\CoreAPI::t( 'Conversion Rate' ),     'order' => 5 ],
            ],
        ],

        'Content' => [
            'report'     => 'content',
            'anchortext' => \OWA\Core\CoreAPI::t( 'Content' ),
            'order'      => 5,
            'icon_class' => 'fa fa-newspaper',
            'links'      => [
                [ 'report' => 'pages',        'anchortext' => \OWA\Core\CoreAPI::t( 'Pages' ),        'order' => 1 ],
                [ 'report' => 'page-types',   'anchortext' => \OWA\Core\CoreAPI::t( 'Page Types' ),   'order' => 2 ],
                [ 'report' => 'entry-pages',  'anchortext' => \OWA\Core\CoreAPI::t( 'Entry Pages' ),  'order' => 3 ],
                [ 'report' => 'exit-pages',   'anchortext' => \OWA\Core\CoreAPI::t( 'Exit Pages' ),   'order' => 4 ],
                [ 'report' => 'clicks',       'anchortext' => \OWA\Core\CoreAPI::t( 'Clicks' ),       'order' => 5 ],
                [ 'report' => 'scroll-depth', 'anchortext' => \OWA\Core\CoreAPI::t( 'Scroll Depth' ), 'order' => 6 ],
                [ 'report' => 'site-search',  'anchortext' => \OWA\Core\CoreAPI::t( 'Site Search' ),  'order' => 7 ],
                [ 'report' => 'forms',        'anchortext' => \OWA\Core\CoreAPI::t( 'Forms' ),        'order' => 8 ],
            ],
        ],

        'Visitors' => [
            'report'     => 'visitors',
            'anchortext' => \OWA\Core\CoreAPI::t( 'Visitors' ),
            'order'      => 4,
            'icon_class' => 'fa fa-user-friends',
            'links'      => [
                [ 'report' => 'geolocation',        'anchortext' => \OWA\Core\CoreAPI::t( 'Geo-location' ),       'order' => 1 ],
                [ 'report' => 'languages',          'anchortext' => \OWA\Core\CoreAPI::t( 'Languages' ),          'order' => 2 ],
                [ 'report' => 'visitors-loyalty',   'anchortext' => \OWA\Core\CoreAPI::t( 'Visitor Loyalty' ),    'order' => 3 ],
                [ 'report' => 'devices',            'anchortext' => \OWA\Core\CoreAPI::t( 'Device Types' ),       'order' => 4 ],
                [ 'report' => 'screen-resolutions', 'anchortext' => \OWA\Core\CoreAPI::t( 'Screen Resolutions' ), 'order' => 5 ],
                [ 'report' => 'browsers',           'anchortext' => \OWA\Core\CoreAPI::t( 'Browser Types' ),      'order' => 6 ],
                [ 'report' => 'os',                 'anchortext' => \OWA\Core\CoreAPI::t( 'Operating Systems' ),  'order' => 7 ],
                [ 'report' => 'latest-visits',      'anchortext' => \OWA\Core\CoreAPI::t( 'Latest Visits' ),      'order' => 8 ],
                [ 'report' => 'events',             'anchortext' => \OWA\Core\CoreAPI::t( 'Events' ),             'order' => 9 ],
            ],
        ],

        'Traffic' => [
            'report'     => 'traffic',
            'anchortext' => \OWA\Core\CoreAPI::t( 'Traffic' ),
            'order'      => 3,
            'icon_class' => 'fa fa-random',
            'links'      => [
                [ 'report' => 'channels',             'anchortext' => \OWA\Core\CoreAPI::t( 'Channels' ),             'order' => 0 ],
                [ 'report' => 'user-acquisition',     'anchortext' => \OWA\Core\CoreAPI::t( 'User Acquisition' ),     'order' => 2 ],
                [ 'report' => 'model-comparison',     'anchortext' => \OWA\Core\CoreAPI::t( 'Model Comparison' ),     'order' => 7 ],
                [ 'report' => 'keywords',             'anchortext' => \OWA\Core\CoreAPI::t( 'Search Terms' ),         'order' => 1 ],
                [ 'report' => 'search-engines',       'anchortext' => \OWA\Core\CoreAPI::t( 'Search Engines' ),       'order' => 3 ],
                [ 'report' => 'referring-sites',      'anchortext' => \OWA\Core\CoreAPI::t( 'Referring Web Sites' ),  'order' => 4 ],
                [ 'report' => 'campaigns',            'anchortext' => \OWA\Core\CoreAPI::t( 'Campaigns' ),            'order' => 5 ],
                [ 'report' => 'ads',                  'anchortext' => \OWA\Core\CoreAPI::t( 'Ad Performance' ),       'order' => 6 ],
                [ 'report' => 'creative-performance', 'anchortext' => \OWA\Core\CoreAPI::t( 'Creative Performance' ), 'order' => 8 ],
            ],
        ],

        'Goals' => [
            'report'     => 'goals',
            'anchortext' => \OWA\Core\CoreAPI::t( 'Goals' ),
            'order'      => 7,
            'icon_class' => 'fa fa-bullseye',
        ],
    ],
];
