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
 * ONLY THE COLUMN READS ARE HERE. The computed kinds PLAN 2.4.1 describes --
 * concat, map, datePart, test, filtered -- each need a renderer, and none of
 * them is a column read.
 *
 * EVERY ONE IS DENORMALISED, structurally rather than by choice: the cube
 * carries its values as columns on the event row, so there is no dimension
 * table to join and no foreign key to name. That is the default this file is
 * read under.
 *
 * NAMES v1 ALSO REGISTERS ARE TAKEN rather than avoided. v2's dimensions
 * replace v1's -- v1's history is migrated into v2's raw store and no v1
 * reporting path survives it, so there is no v1 registry to keep working
 * (PLAN 2.23).
 *
 * A COLUMN THAT IS NOT THERE FAILS SILENTLY. Measured: the result set carries
 * no error, the aggregate is still right, and the breakdown comes back empty --
 * MySQL refuses the dimensional query with "Unknown column" and that refusal is
 * swallowed. CubeReportingTest checks every column below against the real
 * table for that reason.
 */
return array(

    'entity' => 'base.event',

    'dimensions' => array(

        /*
         * `date` and `siteId` are not here because a report asks for them: the
         * ENGINE requires them. Every query constrains on the site and the
         * period, and applyConstraints() resolves both through the dimension
         * registry -- without them the cube refuses every query with "date is
         * not a registered dimension".
         *
         * Registered against the cube directly, NOT by appending it to
         * $fact_table_entities: every dimension in that array would then claim
         * to work on the cube while still naming v1's columns.
         */
        'date' => array( 'column' => 'yyyymmdd', 'label' => 'Date',
            'family' => 'time', 'data_type' => 'yyyymmdd',
            'description' => 'The day the event was recorded, in the configured timezone.' ),

        /*
         * THE DATE PARTS, read out of yyyymmdd rather than out of ts.
         *
         * 1.x stored six of these as columns on every fact row. They are
         * rendered from one column now -- the measurement behind that, and the
         * timezone reasoning behind the basis, are in DimensionExpression.
         *
         * The short version: yyyymmdd was written by PHP in the configured
         * timezone, so a part read from it agrees with `date` above and with
         * the partition the row lives in. A part read from `ts` would answer in
         * the DATABASE's timezone, which on this installation is seven hours
         * away.
         */
        'year' => array( 'datePart' => 'year', 'label' => 'Year',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The year the event was recorded.' ),
        'month' => array( 'datePart' => 'month', 'label' => 'Month',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The month of the year, 1 to 12.' ),
        'yearMonth' => array( 'datePart' => 'yearMonth', 'label' => 'Year / Month',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The year and month together, as 202609 -- one value that sorts chronologically.' ),
        'day' => array( 'datePart' => 'day', 'label' => 'Day of Month',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The day of the month, 1 to 31.' ),
        'dayOfWeek' => array( 'datePart' => 'dayOfWeek', 'label' => 'Day of Week',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The day of the week, 1 for Sunday through 7 for Saturday.' ),
        'dayOfYear' => array( 'datePart' => 'dayOfYear', 'label' => 'Day of Year',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The day of the year, 1 to 366.' ),
        'weekOfYear' => array( 'datePart' => 'weekOfYear', 'label' => 'Week of Year',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The ISO-8601 week of the year: weeks start on Monday and week 1 holds the first Thursday.' ),

        /*
         * And the CLOCK parts, which yyyymmdd cannot answer.
         *
         * These read `ts` and convert it with the configured zone NAME, so the
         * server resolves the offset per row and daylight saving is handled
         * where it changes rather than where the query was built. They need
         * MySQL's timezone tables; an installation without them loses these
         * three and keeps the seven above.
         */
        'hour' => array( 'datePart' => 'hour', 'column' => 'ts', 'label' => 'Hour',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The hour of the day, 0 to 23, in the configured timezone. 1.x stored no hour at all.' ),
        'minute' => array( 'datePart' => 'minute', 'column' => 'ts', 'label' => 'Minute',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The minute of the hour, 0 to 59, in the configured timezone.' ),
        'dateHour' => array( 'datePart' => 'dateHour', 'column' => 'ts', 'label' => 'Date + Hour',
            'family' => 'time', 'data_type' => 'integer',
            'description' => 'The day and hour together, as 2026092518 -- one value that sorts chronologically.' ),
        'siteId' => array( 'column' => 'site_id', 'label' => 'Site ID',
            'family' => 'site', 'description' => 'The Profile the event was collected for.' ),

            // ---- the page -------------------------------------------------
            'pageLocation' => array( 'column' => 'page_location', 'label' => 'Page URL',
                'family' => 'content', 'description' => 'The complete URL of the page, query string included.' ),
            'pagePath' => array( 'column' => 'page_path', 'label' => 'Page Path',
                'family' => 'content', 'description' => 'The path of the page, without its host or query string.' ),
            'pageQuery' => array( 'column' => 'page_query', 'label' => 'Page Query',
                'family' => 'content', 'description' => 'The query string of the page, without its leading question mark.' ),
            'pageTitle' => array( 'column' => 'page_title', 'label' => 'Page Title',
                'family' => 'content', 'description' => 'The title of the page as it was at the time of the event.' ),
            'hostName' => array( 'column' => 'host', 'label' => 'Host Name',
                'family' => 'content', 'description' => 'The host the page was served from.' ),

            /*
             * The two joined readings of a page, both of which GA ships by
             * these names. They are EXPRESSIONS, not columns: the measurement
             * that settled it is in DimensionExpression. `parts` is what marks
             * one, and the separator before an absent part disappears with it,
             * so a page with no query string groups as `/pricing` rather than
             * as `/pricing?`.
             */
            'pagePathPlusQuery' => array( 'parts' => array( 'page_path', 'page_query' ),
                'separator' => '?', 'label' => 'Page Path + Query String',
                'family' => 'content', 'description' => 'The path of the page with its query string, as it would be typed.' ),
            'fullPageUrl' => array( 'parts' => array( 'host', 'page_path', 'page_query' ),
                'separator' => array( '', '?' ), 'label' => 'Full Page URL',
                'family' => 'content', 'description' => "The page's URL without its scheme -- host, path and query string." ),

            /*
             * The referring HOST, which the cube carried as a column and no
             * dimension read -- so a referring-sites report, one of the oldest
             * questions in analytics, had nothing to group by. 1.x asked it as
             * referralWebSite off an enriched referer table.
             *
             * Distinct from sessionSource beside it: that is the RESOLVED
             * source, which is the tag when a URL carried one and falls back to
             * direct when there was no referrer at all. This is the host as
             * observed, and empty when the visit was direct.
             */
            'referrerHost' => array( 'column' => 'referer_host', 'label' => 'Referring Site',
                'family' => 'traffic source',
                'description' => 'The host the user arrived from, as observed.' ),

            'pageReferrer' => array( 'column' => 'referer_url', 'label' => 'Page Referrer',
                'family' => 'content', 'description' => 'The page the user arrived from.' ),
            'contentGroup' => array( 'column' => 'content_group', 'label' => 'Content Group',
                'family' => 'content', 'description' => 'The grouping the author assigned to the page.' ),

            // ---- where the session landed ---------------------------------
            'landingPage' => array( 'column' => 'landing_page_path', 'label' => 'Landing Page',
                'family' => 'content', 'description' => "The path of the session's first page." ),
            'landingPageLocation' => array( 'column' => 'landing_page_location', 'label' => 'Landing Page URL',
                'family' => 'content', 'description' => "The complete URL of the session's first page." ),
            'landingPageQuery' => array( 'column' => 'landing_page_query', 'label' => 'Landing Page Query',
                'family' => 'content', 'description' => "The query string of the session's first page." ),
            'landingPagePlusQuery' => array( 'parts' => array( 'landing_page_path', 'landing_page_query' ),
                'separator' => '?', 'label' => 'Landing Page + Query String',
                'family' => 'content', 'description' => "The path of the session's first page with its query string." ),
            'landingPageTitle' => array( 'column' => 'landing_page_title', 'label' => 'Landing Page Title',
                'family' => 'content', 'description' => "The title of the session's first page." ),

            // ---- traffic source, at session scope -------------------------
            'sessionSource' => array( 'column' => 'source', 'label' => 'Source',
                'family' => 'traffic source', 'description' => 'Where this session came from -- the tag if there was one, otherwise the referring host.' ),
            'sessionMedium' => array( 'column' => 'medium', 'label' => 'Medium',
                'family' => 'traffic source', 'description' => 'How this session arrived -- organic search, social, referral or direct.' ),
            'sessionCampaign' => array( 'column' => 'campaign', 'label' => 'Campaign',
                'family' => 'traffic source', 'description' => 'The campaign this session was tagged with.' ),
            'sessionAd' => array( 'column' => 'ad', 'label' => 'Ad',
                'family' => 'traffic source', 'description' => 'The ad this session was tagged with.' ),
            'sessionSearchTerms' => array( 'column' => 'search_terms', 'label' => 'Search Terms',
                'family' => 'traffic source', 'description' => 'The terms the user searched for before this session.' ),
            'sessionSourceMedium' => array( 'parts' => array( 'source', 'medium' ),
                'separator' => ' / ', 'label' => 'Source / Medium',
                'family' => 'traffic source', 'description' => 'Where this session came from and how it arrived, as one value.' ),

            // ---- and at user scope, from the visit that acquired them ------
            'firstSource' => array( 'column' => 'acq_source', 'label' => 'First Source',
                'family' => 'traffic source', 'description' => 'Where the user came from on the session that acquired them.' ),
            'firstMedium' => array( 'column' => 'acq_medium', 'label' => 'First Medium',
                'family' => 'traffic source', 'description' => 'How the user arrived on the session that acquired them.' ),
            'firstCampaign' => array( 'column' => 'acq_campaign', 'label' => 'First Campaign',
                'family' => 'traffic source', 'description' => 'The campaign that acquired the user.' ),
            'firstAd' => array( 'column' => 'acq_ad', 'label' => 'First Ad',
                'family' => 'traffic source', 'description' => 'The ad that acquired the user.' ),
            'firstSearchTerms' => array( 'column' => 'acq_search_terms', 'label' => 'First Search Terms',
                'family' => 'traffic source', 'description' => 'The terms the user searched for before the session that acquired them.' ),
            'firstSourceMedium' => array( 'parts' => array( 'acq_source', 'acq_medium' ),
                'separator' => ' / ', 'label' => 'First Source / Medium',
                'family' => 'traffic source', 'description' => 'Where the user came from and how they arrived on the session that acquired them, as one value.' ),

            // ---- the tags as collected, before anything classified them ----
            'taggedSource' => array( 'column' => 'tagged_source', 'label' => 'Tagged Source',
                'family' => 'traffic source', 'description' => 'The source named by the landing URL, as collected.' ),
            'taggedMedium' => array( 'column' => 'tagged_medium', 'label' => 'Tagged Medium',
                'family' => 'traffic source', 'description' => 'The medium named by the landing URL, as collected.' ),
            'taggedCampaign' => array( 'column' => 'tagged_campaign', 'label' => 'Tagged Campaign',
                'family' => 'traffic source', 'description' => 'The campaign named by the landing URL, as collected.' ),

            // ---- who ------------------------------------------------------
            'clientId' => array( 'column' => 'visitor_id', 'label' => 'Client ID',
                'family' => 'visitor', 'description' => 'The identifier OWA assigned to the user.' ),
            'userId' => array( 'column' => 'user_id', 'label' => 'User ID',
                'family' => 'visitor', 'description' => 'The identifier the site declared for the person.' ),
            'priorSessionCount' => array( 'column' => 'prior_sessions', 'label' => 'Prior Sessions',
                'family' => 'visitor', 'description' => 'How many sessions the user had before this one.',
                'data_type' => 'integer' ),

            /*
             * REPLACES v1's isNewVisitor AND isRepeatVisitor, which were two
             * dimensions partitioning one population over a nullable tinyint --
             * three values, three GROUP BY buckets, and a valueLabels map in
             * dashboard.json folding them back onto two names. That is what drew
             * a pie with two slices both labelled New. GA has one
             * `New / returning` dimension and no boolean twins, for the same
             * reason.
             *
             * The cube stores the label, so this is a plain column read like
             * every other line in this file -- see Classes\Cube\NewVsReturningStep
             * for why a stored code could not have been grouped into named
             * buckets at all.
             */
            'newVsReturning' => array( 'column' => 'new_vs_returning', 'label' => 'New vs Returning',
                'family' => 'visitor',
                'description' => 'Whether the session was the user\'s first.' ),
            'sessionId' => array( 'column' => 'session_id', 'label' => 'Session ID',
                'family' => 'visit', 'description' => 'The identifier of the session the event belongs to.' ),

            // ---- where -----------------------------------------------------
            'country' => array( 'column' => 'country', 'label' => 'Country',
                'family' => 'geography', 'description' => "The country resolved from the user's address." ),
            'countryCode' => array( 'column' => 'country_code', 'label' => 'Country Code',
                'family' => 'geography', 'description' => 'The ISO 3166-1 alpha-2 code of that country.' ),
            'city' => array( 'column' => 'city', 'label' => 'City',
                'family' => 'geography', 'description' => "The city resolved from the user's address." ),
            'stateRegion' => array( 'column' => 'region', 'label' => 'Region',
                'family' => 'geography', 'description' => "The region or state resolved from the user's address." ),

            // ---- what they were using --------------------------------------
            'browserType' => array( 'column' => 'browser_type', 'label' => 'Browser',
                'family' => 'device', 'description' => 'The browser, as a name without its version.' ),
            'browserVersion' => array( 'column' => 'browser_version', 'label' => 'Browser Version',
                'family' => 'device', 'description' => 'The version of that browser.' ),
            'osType' => array( 'column' => 'os', 'label' => 'Operating System',
                'family' => 'device', 'description' => 'The operating system, as a name without its version.' ),
            'osVersion' => array( 'column' => 'os_version', 'label' => 'Operating System Version',
                'family' => 'device', 'description' => 'The version of that operating system.' ),
            'operatingSystemWithVersion' => array( 'parts' => array( 'os', 'os_version' ),
                'separator' => ' ', 'label' => 'Operating System with Version',
                'family' => 'device', 'description' => 'The operating system and its version, as one value -- GA ships this dimension under this name.' ),
            'deviceType' => array( 'column' => 'device_type', 'label' => 'Device Type',
                'family' => 'device', 'description' => 'Desktop, mobile or tablet.' ),
            'deviceBrand' => array( 'column' => 'device_brand', 'label' => 'Device Brand',
                'family' => 'device', 'description' => 'The maker of the device.' ),
            'deviceModel' => array( 'column' => 'device_model', 'label' => 'Device Model',
                'family' => 'device', 'description' => 'The model of the device.' ),
            'language' => array( 'column' => 'language', 'label' => 'Language',
                'family' => 'device', 'description' => 'The language the browser declared.' ),
            'ipAddress' => array( 'column' => 'ip_address', 'label' => 'IP Address',
                'family' => 'device', 'description' => "The user's address, where the install stores one." ),
            'consentState' => array( 'column' => 'consent_state', 'label' => 'Consent State',
                'family' => 'device', 'description' => 'What the page declared about consent when the event was sent.' ),

            // ---- the event itself -------------------------------------------
            'eventName' => array( 'column' => 'event_type', 'label' => 'Event Name',
                'family' => 'event', 'description' => 'The name of the event -- page_view, click, session_start.' ),
            /*
             * GA's SHAPE, our word. GA carries isKeyEvent (deprecating
             * isConversionEvent), so a flag dimension on the event row is the
             * right form -- but OWA calls these goals everywhere else it
             * speaks: is_goal_event, base.goal_event, GoalManager. Taking GA's
             * spelling here would have left one noun disagreeing with the
             * column it reads.
             *
             * BOOLEAN, not integer -- the formatter renders Yes and No where
             * integer rendered 1 and 0. That is safe here in a way it was not
             * for 1.x's isNewVisitor: is_goal_event is NOT NULL DEFAULT 0, so
             * it holds two values. The defect there was a NULLABLE tinyint
             * holding three, which grouped into three buckets and drew a pie
             * with two slices called New -- the formatter was never the
             * problem.
             */
            'isGoalEvent' => array( 'column' => 'is_goal_event', 'label' => 'Goal Event',
                'family' => 'event', 'description' => 'Whether this event met a goal condition.',
                'data_type' => 'boolean' ),
            'domElementId' => array( 'column' => 'element_id', 'label' => 'Element ID',
                'family' => 'event', 'description' => 'The id attribute of the element that was clicked.' ),
            'domElementTag' => array( 'column' => 'element_tag', 'label' => 'Element Tag',
                'family' => 'event', 'description' => 'The tag name of the element that was clicked.' ),
            'elementPath' => array( 'column' => 'element_path', 'label' => 'Element Path',
                'family' => 'event', 'description' => 'The selector locating the element that was clicked.' ),
            'clickTarget' => array( 'column' => 'target_url', 'label' => 'Click Target',
                'family' => 'event', 'description' => 'Where the click led.' ),
            'linkDomain' => array( 'column' => 'target_host', 'label' => 'Link Domain',
                'family' => 'event', 'description' => 'The host the click led to.' ),

            // ---- money -------------------------------------------------------
            'currencyCode' => array( 'column' => 'currency', 'label' => 'Currency',
                'family' => 'commerce', 'description' => 'The ISO 4217 currency the revenue on this row is denominated in.' ),
    ),
);
