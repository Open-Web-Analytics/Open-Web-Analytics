<?php

/**
 * The dimensions that read a Property's reporting cube.
 *
 * A dimension is a name, a column and what to call it -- data, not code -- so
 * it lives beside the other vocabularies core reads from a file:
 * `reports/*.json`, `config/tracking_properties.json`, `<module>/settings.php`.
 * Core\Module::registerDimensionsFromConfig() picks this up; a module ships the
 * file and needs no code at all.
 *
 * THREE KINDS: a column read (`column`), several columns joined (`parts`) and
 * a component of a date (`datePart`). Core\Module::registerDimensionsFromConfig()
 * renders the last two through DimensionExpression. No other computed kind
 * (a value map, a test, a filtered column) is supported.
 *
 * EVERY ONE IS DENORMALISED, structurally rather than by choice: the cube
 * carries its values as columns on the event row, so there is no dimension
 * table to join and no foreign key to name. That is the default this file is
 * read under.
 *
 * NAMES v1 USED ARE TAKEN rather than avoided: v1's history is migrated into
 * v2's raw store and there is no v1 reporting path.
 *
 * `internal` => true KEEPS A DIMENSION OUT OF THE PICKERS -- the custom report
 * and visualization editors -- while the reports API still resolves it. For
 * the ones a feature queries and a person would not group by: the heatmap's
 * coordinates and element anchor.
 *
 * A COLUMN THAT IS NOT THERE FAILS SILENTLY: the result set carries no error,
 * the aggregate is still right, and the breakdown comes back empty --
 * MySQL refuses the dimensional query with "Unknown column" and that refusal is
 * swallowed. CubeReportingTest checks every column below against the real
 * table for that reason.
 */
return [

    'entity' => 'base.event',

    'dimensions' => [

        /*
         * `date` and `siteId` are not here because a report asks for them: the
         * ENGINE requires them. Every query constrains on the period, a REST
         * query naming a Profile constrains on the site too, and
         * applyConstraints() resolves both through the dimension registry --
         * without them the cube refuses every query with "date is not a
         * registered dimension".
         *
         * Registered against the cube directly, NOT by appending it to
         * $fact_table_entities: every dimension in that array would then claim
         * to work on the cube while still naming v1's columns.
         */
        'date' => [ 'column' => 'yyyymmdd', 'label' => 'Date',
            'family' => 'time', 'data_type' => 'yyyymmdd',
            'description' => 'The day the event was recorded, in the configured timezone.' ],

        /*
         * THE DATE PARTS, read out of yyyymmdd rather than out of ts.
         *
         * Rendered from one column; the timezone reasoning behind the basis is
         * in DimensionExpression. yyyymmdd is written by PHP in the configured
         * timezone, so a part read from it agrees with `date` above and with
         * the partition the row lives in. A part read from `ts` would answer in
         * the DATABASE's timezone.
         */
        'year' => [ 'datePart' => 'year', 'label' => 'Year',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The year the event was recorded.' ],
        'month' => [ 'datePart' => 'month', 'label' => 'Month',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The month of the year, 1 to 12.' ],
        'yearMonth' => [ 'datePart' => 'yearMonth', 'label' => 'Year / Month',
            'family' => 'time', 'data_type' => 'yyyymm',
            'description' => 'The year and month together, as 202609: one value that sorts chronologically.' ],
        'day' => [ 'datePart' => 'day', 'label' => 'Day of Month',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The day of the month, 1 to 31.' ],
        'dayOfWeek' => [ 'datePart' => 'dayOfWeek', 'label' => 'Day of Week',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The day of the week, 1 for Sunday through 7 for Saturday.' ],
        'dayOfYear' => [ 'datePart' => 'dayOfYear', 'label' => 'Day of Year',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The day of the year, 1 to 366.' ],
        'weekOfYear' => [ 'datePart' => 'weekOfYear', 'label' => 'Week of Year',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The ISO-8601 week of the year: weeks start on Monday and week 1 holds the first Thursday.' ],

        /*
         * And the CLOCK parts, which yyyymmdd cannot answer.
         *
         * These read `ts` and convert it with the configured zone NAME, so the
         * server resolves the offset per row and daylight saving is handled
         * where it changes rather than where the query was built. They need
         * MySQL's timezone tables; an installation without them loses these
         * three and keeps the seven above.
         */
        'hour' => [ 'datePart' => 'hour', 'column' => 'ts', 'label' => 'Hour',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The hour of the day, 0 to 23, in the configured timezone.' ],
        'minute' => [ 'datePart' => 'minute', 'column' => 'ts', 'label' => 'Minute',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The minute of the hour, 0 to 59, in the configured timezone.' ],
        // Its own type, as yearMonth has, so a chart reads it as a time and a
        // grid does not print it as 2,026,092,518.
        'dateHour' => [ 'datePart' => 'dateHour', 'column' => 'ts', 'label' => 'Date + Hour',
            'family' => 'time', 'data_type' => 'yyyymmddhh',
            'description' => 'The day and hour together, as 2026092518: one value that sorts chronologically.' ],
        'siteId' => [ 'column' => 'site_id', 'label' => 'Profile ID',
            'family' => 'site', 'description' => 'The tracking id of the Observation Profile the event was collected for. Reports cover every Profile of a Property; this breaks them out.' ],
        'profileName' => [ 'column' => 'profile_name', 'label' => 'Profile Name',
            'family' => 'site', 'description' => 'The name of the Observation Profile the event was collected for, as it was when its day was last built.' ],

            // ---- the page -------------------------------------------------
            'pageLocation' => [ 'column' => 'page_location', 'label' => 'Page URL',
                'family' => 'content', 'description' => 'The complete URL of the page, query string included.' ],
            'pagePath' => [ 'column' => 'page_path', 'label' => 'Page Path',
                'family' => 'content', 'description' => 'The path of the page, without its host or query string.' ],
            'pageQuery' => [ 'column' => 'page_query', 'label' => 'Page Query',
                'family' => 'content', 'description' => 'The query string of the page, without its leading question mark.' ],
            'pageTitle' => [ 'column' => 'page_title', 'label' => 'Page Title',
                'family' => 'content', 'description' => 'The title of the page as it was at the time of the event.' ],
            'hostName' => [ 'column' => 'host', 'label' => 'Host Name',
                'family' => 'content', 'description' => 'The host the page was served from.' ],

            /*
             * The two joined readings of a page. They are EXPRESSIONS, not
             * columns: the measurement
             * that settled it is in DimensionExpression. `parts` is what marks
             * one, and the separator before an absent part disappears with it,
             * so a page with no query string groups as `/pricing` rather than
             * as `/pricing?`.
             */
            'pagePathPlusQuery' => [ 'parts' => [ 'page_path', 'page_query' ],
                'separator' => '?', 'label' => 'Page Path + Query String',
                'family' => 'content', 'description' => 'The path of the page with its query string, as it would be typed.' ],
            'fullPageUrl' => [ 'parts' => [ 'host', 'page_path', 'page_query' ],
                'separator' => [ '', '?' ], 'label' => 'Full Page URL',
                'family' => 'content', 'description' => "The page's URL without its scheme: host, path and query string." ],

            /*
             * The referring HOST, for a referring-sites report.
             *
             * Distinct from sessionSource beside it: that is the RESOLVED
             * source, which is the tag when a URL carried one and falls back to
             * direct when there was no referrer at all. This is the host as
             * observed, and empty when the visit was direct or came from one
             * of the site's own pages (TrackingEventHelpers::deriveRefererHost()).
             */
            'referrerHost' => [ 'column' => 'referer_host', 'label' => 'Referring Site',
                'family' => 'traffic source',
                'description' => 'The host the user arrived from, as observed. Empty when it was one of the site\'s own pages.' ],

            'pageReferrer' => [ 'column' => 'referer_url', 'label' => 'Page Referrer',
                'family' => 'content', 'description' => 'The page the user arrived from.' ],
            'contentGroup' => [ 'column' => 'content_group', 'label' => 'Content Group',
                'family' => 'content', 'description' => 'The grouping the author assigned to the page.' ],

            // ---- where the session landed ---------------------------------
            'landingPage' => [ 'column' => 'landing_page_path', 'label' => 'Landing Page',
                'family' => 'content', 'description' => "The path of the session's first page." ],
            'landingPageLocation' => [ 'column' => 'landing_page_location', 'label' => 'Landing Page URL',
                'family' => 'content', 'description' => "The complete URL of the session's first page." ],
            'landingPageQuery' => [ 'column' => 'landing_page_query', 'label' => 'Landing Page Query',
                'family' => 'content', 'description' => "The query string of the session's first page." ],
            'landingPagePlusQuery' => [ 'parts' => [ 'landing_page_path', 'landing_page_query' ],
                'separator' => '?', 'label' => 'Landing Page + Query String',
                'family' => 'content', 'description' => "The path of the session's first page with its query string." ],
            'landingPageTitle' => [ 'column' => 'landing_page_title', 'label' => 'Landing Page Title',
                'family' => 'content', 'description' => "The title of the session's first page." ],

            // ---- the page's place in the session --------------------------
            'pageDepth' => [ 'column' => 'page_depth', 'label' => 'Page Depth',
                'family' => 'content', 'data_type' => 'integer',
                'description' => 'Which page of the session the event happened on: 1 for the landing page, 2 for the next, and so on.' ],
            'previousPagePath' => [ 'column' => 'previous_page_path', 'label' => 'Previous Page Path',
                'family' => 'content',
                'description' => 'On a page view, the path of the page viewed before it in the session, or (entrance) on the first.' ],
            'nextPagePath' => [ 'column' => 'next_page_path', 'label' => 'Next Page Path',
                'family' => 'content',
                'description' => 'On a page view, the path of the page viewed after it in the session, or (exit) on the last. Empty on the newest page of a session that is still open.' ],

            // ---- traffic source, at session scope -------------------------
            'sessionSource' => [ 'column' => 'source', 'label' => 'Source',
                'family' => 'traffic source', 'description' => 'Where this session came from: the tag if there was one, otherwise the referring host without a leading www.' ],
            'sessionMedium' => [ 'column' => 'medium', 'label' => 'Medium',
                'family' => 'traffic source', 'description' => 'How this session arrived: the tagged medium, else organic, referral, ai-agent or (none) for a direct visit.' ],
            'sessionCampaign' => [ 'column' => 'campaign', 'label' => 'Campaign',
                'family' => 'traffic source', 'description' => 'The campaign this session was tagged with. An untagged session is (direct), (organic), (referral) or (ai-agent), after its medium; a tagged one without a campaign is (not set).' ],
            'sessionChannel' => [ 'column' => 'channel', 'label' => 'Channel',
                'family' => 'traffic source', 'description' => "What kind of traffic this session was, from the channel rules in conf/channels.php: Direct, Organic Search, Organic Social, Referral, Email, Paid Search, AI Agent and the rest." ],
            'sessionAd' => [ 'column' => 'ad', 'label' => 'Ad',
                'family' => 'traffic source', 'description' => 'The ad this session was tagged with.' ],
            'sessionSearchTerms' => [ 'column' => 'search_terms', 'label' => 'Search Terms',
                'family' => 'traffic source', 'description' => 'The terms the user searched for before this session.' ],
            'sessionSourceMedium' => [ 'parts' => [ 'source', 'medium' ],
                'separator' => ' / ', 'label' => 'Source / Medium',
                'family' => 'traffic source', 'description' => 'Where this session came from and how it arrived, as one value.' ],

            // ---- the last non-direct touch within the lookback ----
            'attributedSource' => [ 'column' => 'attributed_source', 'label' => 'Attributed Source',
                'family' => 'traffic source', 'description' => "Where this session's credit goes: its own source if it arrived with tags or a referrer, otherwise the user's last campaign, search or referral visit within the attribution lookback, otherwise (direct)." ],
            'attributedMedium' => [ 'column' => 'attributed_medium', 'label' => 'Attributed Medium',
                'family' => 'traffic source', 'description' => 'The medium of the attributed source.' ],
            'attributedCampaign' => [ 'column' => 'attributed_campaign', 'label' => 'Attributed Campaign',
                'family' => 'traffic source', 'description' => 'The campaign of the attributed source, or the placeholder for an untagged one.' ],
            'attributedChannel' => [ 'column' => 'attributed_channel', 'label' => 'Attributed Channel',
                'family' => 'traffic source', 'description' => 'What kind of traffic the attributed source is, by the channel rules in conf/channels.php.' ],
            'attributedAd' => [ 'column' => 'attributed_ad', 'label' => 'Attributed Ad',
                'family' => 'traffic source', 'description' => 'The ad of the attributed source.' ],
            'attributedSourceMedium' => [ 'parts' => [ 'attributed_source', 'attributed_medium' ],
                'separator' => ' / ', 'label' => 'Attributed Source / Medium',
                'family' => 'traffic source', 'description' => 'The attributed source and medium, as one value.' ],

            // ---- and at user scope, from the visit that acquired them ------
            'firstSource' => [ 'column' => 'acq_source', 'label' => 'First Source',
                'family' => 'traffic source', 'description' => 'Where the user came from on the session that acquired them.' ],
            'firstMedium' => [ 'column' => 'acq_medium', 'label' => 'First Medium',
                'family' => 'traffic source', 'description' => 'How the user arrived on the session that acquired them.' ],
            'firstCampaign' => [ 'column' => 'acq_campaign', 'label' => 'First Campaign',
                'family' => 'traffic source', 'description' => 'The campaign that acquired the user, or the placeholder for how they arrived untagged.' ],
            'firstChannel' => [ 'column' => 'acq_channel', 'label' => 'First Channel',
                'family' => 'traffic source', 'description' => 'What kind of traffic acquired the user, by the same channel rules.' ],
            'firstAd' => [ 'column' => 'acq_ad', 'label' => 'First Ad',
                'family' => 'traffic source', 'description' => 'The ad that acquired the user.' ],
            'firstSearchTerms' => [ 'column' => 'acq_search_terms', 'label' => 'First Search Terms',
                'family' => 'traffic source', 'description' => 'The terms the user searched for before the session that acquired them.' ],
            'firstSourceMedium' => [ 'parts' => [ 'acq_source', 'acq_medium' ],
                'separator' => ' / ', 'label' => 'First Source / Medium',
                'family' => 'traffic source', 'description' => 'Where the user came from and how they arrived on the session that acquired them, as one value.' ],

            // ---- the tags as collected, before anything classified them ----
            'taggedSource' => [ 'column' => 'tagged_source', 'label' => 'Tagged Source',
                'family' => 'traffic source', 'description' => 'The source named by the landing URL, as collected.' ],
            'taggedMedium' => [ 'column' => 'tagged_medium', 'label' => 'Tagged Medium',
                'family' => 'traffic source', 'description' => 'The medium named by the landing URL, as collected.' ],
            'taggedCampaign' => [ 'column' => 'tagged_campaign', 'label' => 'Tagged Campaign',
                'family' => 'traffic source', 'description' => 'The campaign named by the landing URL, as collected.' ],

            // ---- who ------------------------------------------------------
            'clientId' => [ 'column' => 'visitor_id', 'label' => 'Client ID',
                'family' => 'visitor', 'description' => 'The identifier OWA assigned to the user.' ],
            'userId' => [ 'column' => 'user_id', 'label' => 'User ID',
                'family' => 'visitor', 'description' => 'The identifier the site declared for the person.' ],
            'priorSessionCount' => [ 'column' => 'prior_sessions', 'label' => 'Prior Sessions',
                'family' => 'visitor', 'description' => 'How many sessions the user had before this one.',
                'data_type' => 'integer' ],

            /*
             * ONE `New / returning` dimension and no boolean twins: two
             * dimensions partitioning one population over a nullable tinyint give
             * three values and three GROUP BY buckets.
             *
             * The cube stores the label, so this is a plain column read like
             * every other line in this file -- see Classes\Cube\NewVsReturningStep
             * for why a stored code could not have been grouped into named
             * buckets at all.
             */
            'newVsReturning' => [ 'column' => 'new_vs_returning', 'label' => 'New vs Returning',
                'family' => 'visitor',
                'description' => 'Whether the session was the user\'s first.' ],
            'isEngagedSession' => [ 'column' => 'is_engaged_session', 'label' => 'Engaged Session',
                'family' => 'visit',
                'description' => 'Whether the session was engaged: ten seconds or more of engagement time, two or more page views, or a goal event.',
                'data_type' => 'boolean' ],
            'sessionId' => [ 'column' => 'session_id', 'label' => 'Session ID',
                'family' => 'visit', 'description' => 'The identifier of the session the event belongs to.' ],
            // The tracker's sts, in epoch seconds: every row of a session
            // carries the same one, so grouped by it a row is a visit, and
            // sorted by it the newest visit comes first.
            'sessionStartTime' => [ 'column' => 'session_start_ts', 'label' => 'Visit Start',
                'family' => 'visit', 'data_type' => 'datetime',
                'description' => 'When the session started, to the second, in the configured timezone.' ],

            // ---- where -----------------------------------------------------
            'country' => [ 'column' => 'country', 'label' => 'Country',
                'family' => 'geography', 'description' => "The country resolved from the user's address." ],
            'countryCode' => [ 'column' => 'country_code', 'label' => 'Country Code',
                'family' => 'geography', 'description' => 'The ISO 3166-1 alpha-2 code of that country.' ],
            'city' => [ 'column' => 'city', 'label' => 'City',
                'family' => 'geography', 'description' => "The city resolved from the user's address." ],
            'stateRegion' => [ 'column' => 'region', 'label' => 'Region',
                'family' => 'geography', 'description' => "The region or state resolved from the user's address." ],

            // ---- what they were using --------------------------------------
            'browserType' => [ 'column' => 'browser_type', 'label' => 'Browser',
                'family' => 'device', 'description' => 'The browser, as a name without its version.' ],
            'browserVersion' => [ 'column' => 'browser_version', 'label' => 'Browser Version',
                'family' => 'device', 'description' => 'The version of that browser.' ],
            'osType' => [ 'column' => 'os', 'label' => 'Operating System',
                'family' => 'device', 'description' => 'The operating system, as a name without its version.' ],
            'osVersion' => [ 'column' => 'os_version', 'label' => 'Operating System Version',
                'family' => 'device', 'description' => 'The version of that operating system.' ],
            'operatingSystemWithVersion' => [ 'parts' => [ 'os', 'os_version' ],
                'separator' => ' ', 'label' => 'Operating System with Version',
                'family' => 'device', 'description' => 'The operating system and its version, as one value.' ],
            'deviceType' => [ 'column' => 'device_type', 'label' => 'Device Type',
                'family' => 'device', 'description' => 'Desktop, mobile or tablet.' ],
            'deviceBrand' => [ 'column' => 'device_brand', 'label' => 'Device Brand',
                'family' => 'device', 'description' => 'The maker of the device.' ],
            'deviceModel' => [ 'column' => 'device_model', 'label' => 'Device Model',
                'family' => 'device', 'description' => 'The model of the device.' ],
            'language' => [ 'column' => 'language', 'label' => 'Language',
                'family' => 'device', 'description' => 'The language the browser declared.' ],
            'screenResolution' => [ 'column' => 'screen_resolution', 'label' => 'Screen Resolution',
                'family' => 'device', 'description' => 'The size of the device screen, width by height in CSS pixels.' ],
            'ipAddress' => [ 'column' => 'ip_address', 'label' => 'IP Address',
                'family' => 'device', 'description' => "The user's address, where the install stores one." ],
            'consentState' => [ 'column' => 'consent_state', 'label' => 'Consent State',
                'family' => 'device', 'description' => 'What the page declared about consent when the event was sent.' ],

            // ---- the event itself -------------------------------------------
            'eventName' => [ 'column' => 'event_type', 'label' => 'Event Name',
                'family' => 'event', 'description' => 'The name of the event, such as page_view, click or session_start.' ],
            /*
             * A flag dimension on the event row, named for GOALS because that
             * is what OWA calls them everywhere else it speaks: is_goal_event,
             * base.goal_event_definition, GoalMarking. Any other noun here would
             * disagree with the column it reads.
             *
             * BOOLEAN, not integer -- the formatter renders Yes and No where
             * integer rendered 1 and 0. Safe because is_goal_event is NOT NULL
             * DEFAULT 0, so it holds two values; a NULLABLE tinyint would hold
             * three and group into three buckets.
             */
            'isGoalEvent' => [ 'column' => 'is_goal_event', 'label' => 'Goal Event',
                'family' => 'event', 'description' => 'Whether this event is a goal event.',
                'data_type' => 'boolean' ],
            'domElementId' => [ 'column' => 'element_id', 'label' => 'Element ID',
                'family' => 'event', 'description' => 'The id attribute of the element that was clicked.' ],
            'domElementTag' => [ 'column' => 'element_tag', 'label' => 'Element Tag',
                'family' => 'event', 'description' => 'The tag name of the element that was clicked.' ],
            /*
             * is_outbound is decided by the tracker (OWATracker.isOutboundUrl()) and sent
             * with the click.
             *
             * BOOLEAN is safe here for the same reason it is on isGoalEvent: is_outbound is
             * NOT NULL DEFAULT 0, so it holds two values and the formatter's Yes/No maps
             * onto them one to one. A nullable flag would put the non-clicks in a third
             * bucket also rendered 'No'.
             */
            'isOutbound' => [ 'column' => 'is_outbound', 'label' => 'Outbound Click',
                'family' => 'event', 'data_type' => 'boolean',
                'description' => 'Whether the click went to a host other than the page it was on.' ],

            /*
             * THE DOWNLOAD.
             *
             * These exist BECAUSE the columns do. A params key is unreportable
             * until a site registers it as a custom dimension, so leaving these in
             * the bag meant every install spending a registration slot on a value
             * OWA set itself. The element and form params stay in the bag, and are
             * reportable the same way a site's own values are: by registering the
             * ones that install actually cares about.
             *
             * fileName is the PATH, without host, query or fragment -- so two
             * files of the same name in different folders stay apart.
             */
            'fileName' => [ 'column' => 'file_name', 'label' => 'File Name',
                'family' => 'event',
                'description' => 'The path of the downloaded file.' ],
            'fileExtension' => [ 'column' => 'file_extension', 'label' => 'File Extension',
                'family' => 'event',
                'description' => 'The extension of the downloaded file, such as pdf, zip or csv.' ],

            /*
             * SITE SEARCH.
             *
             * Distinct from `sessionSearchTerms` and `firstSearchTerms`, which are
             * what a SEARCH ENGINE sent the visitor in on and are resolved by the cube
             * pass. This is what they typed into this site's own box, on the event.
             */
            'searchTerm' => [ 'column' => 'search_term', 'label' => 'Search Term',
                'family' => 'event',
                'description' => 'What the user searched this site for.' ],
            /*
             * HOW FAR DOWN THE PAGE, as the threshold passed: 25, 50, 75 or 90 by
             * default. A scroll event is raised for EVERY threshold a page view
             * crosses, so counting scroll events grouped by this reads directly as
             * "how many reached at least this far" -- a depth funnel -- and a
             * visitor who jumps straight to the bottom still counts at every level.
             *
             * Integer, so it sorts as a number and 90 comes after 75.
             */
            'scrollDepth' => [ 'column' => 'scroll_depth', 'label' => 'Scroll Depth',
                'family' => 'event', 'data_type' => 'integer',
                'description' => 'How far down the page the visitor scrolled: the deepest percentage threshold reached on the page view.' ],

            /*
             * THE CLICK'S COORDINATES, which the heatmap groups by: its fetch asks
             * the reports API for `dimensions=clickX,clickY`, and a name that does
             * not resolve through this registry never reaches SQL.
             *
             * A coordinate is only meaningful against the viewport it was measured
             * in, which is why page_width and page_height ride the same click row.
             */
            'clickX' => [ 'column' => 'click_x', 'label' => 'Click X',
                'family' => 'event', 'data_type' => 'integer', 'internal' => true,
                'description' => 'How far across the page the click landed, in pixels.' ],
            'clickY' => [ 'column' => 'click_y', 'label' => 'Click Y',
                'family' => 'event', 'data_type' => 'integer', 'internal' => true,
                'description' => 'How far down the page the click landed, in pixels.' ],

            /*
             * WHERE ON THE ELEMENT, which is what the heatmap draws a click by:
             * a page coordinate places a click only in a window as wide as the
             * one it was recorded in. The anchor is a hash the overlay matches
             * against the page in front of it (ElementAnchor.js), not something
             * a person reads, so the three are internal. viewportWidth is how the
             * overlay tells whether a click with no anchor was recorded in a
             * window like the reader's, and is a fair thing to group by.
             */
            'elementAnchor' => [ 'column' => 'element_anchor', 'label' => 'Element Anchor',
                'family' => 'event', 'data_type' => 'integer', 'internal' => true,
                'description' => 'The clicked element, as a hash of its place in the page. The heatmap finds the element by it.' ],
            'anchorX' => [ 'column' => 'anchor_x', 'label' => 'Anchor X',
                'family' => 'event', 'data_type' => 'integer', 'internal' => true,
                'description' => 'How far across the clicked element the click landed, in thousandths of its width.' ],
            'anchorY' => [ 'column' => 'anchor_y', 'label' => 'Anchor Y',
                'family' => 'event', 'data_type' => 'integer', 'internal' => true,
                'description' => 'How far down the clicked element the click landed, in thousandths of its height.' ],
            'viewportWidth' => [ 'column' => 'page_width', 'label' => 'Viewport Width',
                'family' => 'event', 'data_type' => 'integer',
                'description' => 'The width of the browser window when the click happened, in pixels.' ],
            'clickTarget' => [ 'column' => 'target_url', 'label' => 'Click Target',
                'family' => 'event', 'description' => 'Where the click led.' ],
            'linkDomain' => [ 'column' => 'target_host', 'label' => 'Link Domain',
                'family' => 'event', 'description' => 'The host the click led to.' ],

            // ---- money -------------------------------------------------------
            'currencyCode' => [ 'column' => 'currency', 'label' => 'Currency',
                'family' => 'commerce', 'description' => 'The ISO 4217 currency the revenue on this row is denominated in.' ],
            'transactionId' => [ 'column' => 'transaction_id', 'label' => 'Transaction ID',
                'family' => 'commerce', 'description' => 'The order id the purchase or refund was recorded under.' ],
    ],
];
