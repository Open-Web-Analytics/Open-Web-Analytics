// OWA is defined by owa.js; this module augments it (OWA.report = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

// The report period-picker's jqote2 markup. This was formerly fetched over HTTP from
// modules/base/templates/filter_period.php -- a file that contained NO PHP, just this
// pure client-side template, given a .php extension by convention. Inlining it here
// drops the per-report round-trip AND removes modules_url's last runtime code-fetch
// (it is now image-only), so the web-access allowlist needs no templates/ exception.
const FILTER_PERIOD_TMPL =
`<div class="timePeriodControlContainer">

    <table id="owa_reportPeriodLabelContainer" cellpadding="0" cellspacing="0">
        <TR>
            <TD class="owa_reportPeriodLabelText">

                <span>
                    <*=this.datelabel *>
                </span>
            </TD>

            <TD class="owa_reportRevealControl"></TD>
        </TR>
    </table>

    <table id="owa_reportPeriodFiltersContainer" style="display:none;" cellpadding="0" cellspacing="0">
        <TR>
            <TH colspan="3">
                Enter a Date Range:
            </TH>
        </TR>
        <TR>
            <TD class="picker" valign="top">
                <div>Start: <input type="text" id="owa_report-datepicker-start-display" size="10"></div>
                <div id="owa_report-datepicker-start"></div>
            <TD class="picker" valign="top">
                <div>End: <input type="text" id="owa_report-datepicker-end-display"  size="10"></div>
                <div id="owa_report-datepicker-end"></div>
            </TD>
            <TD>

            </TD>

            <TD valign="top">
                Predefined Periods:<BR>

                <SELECT id="owa_reportPeriodFilter" name="owa_reportPeriodFilter">
                    <OPTION>Select...</OPTION>
                    <* for (period in this.periods) { *>
                    <OPTION VALUE="<*= period *>" <* if ( period === this.selectedPeriod ) { *>selected<* } *> >
                        <*= this.periods[period] *>
                    </OPTION>
                    <* } *>
                </SELECT>
                <P><INPUT class="submit-button" type="submit" id="owa_reportPeriodFilterSubmit" name="" value="Change Date Range"></P>
            </TD>
        </TR>
        <TR>
            <TD colspan="3"></TD>
        </TR>
    </table>
</div>`;

OWA.report = function(dom_id, options) {
    
    this.options = {
        autoRefreshResultSets:             false,
        autoRefreshResultSetsInterval:     15000
    
    };
    
    this.overrideOptions(options);
    
    this.dom_id = dom_id;
    this.config = OWA.config;
    this.properties = {};
    this.tabs = {};
    this.timePeriodControl = '';
    // container for resultSetExplorer objects
    this.resultSetExplorers = {};
    // is window active?
    this.isActive = false;
    // the dom id of the active tab
    this.activeTab = '';
    
    var ar = this.getOption('autoRefreshResultSets');
    // bind focus/blur handlers
    
};

OWA.report.prototype = {
    
    display : function() {
        
        
        
    },
    
    showAutoRefreshControl : function( options ) {
        
        var selector = '';
        
        if (options.hasOwnProperty('target')) {
            
            selector = options.target;
        } else {
            selector = '#' + this.dom_id + ' > .liveViewSwitch';        
        }
        
        if (options.hasOwnProperty('label')) {
            
            var label= options.label;
        } else {
            selector = 'Live View: ';        
        }
        
        
        var c = [];
        c.push('<div class="autoRefreshControl">');
        c.push( OWA.util.sprintf( '<span class="label">%s</span>', label ) );
        c.push('<span class="buttons">');
        c.push('<input type="radio" name="autorefresh" id="autorefresh-on-button" /><label for="autorefresh-on-button">On</label>');
        c.push('<input type="radio" name="autorefresh" checked="checked" id="autorefresh-off-button" /><label for="autorefresh-off-button">Off</label>');
        c.push('</span>');
        c.push('<div style="clear:both;"></div>');
        c.push('</div>');
        
        jQuery( selector ).append( c.join(' ') );
        // jQuery-UI 1.12 deprecated buttonset() in favor of controlgroup().
        // controlgroup auto-enhances the child radio inputs via checkboxradio
        // (its default `items`), so the On/Off radios become .ui-checkboxradio
        // .ui-button labels inside a .ui-controlgroup container.
        //
        // BUT jQuery-UI 1.13's checkboxradio defaults to `icon:true`, which
        // prepends a blank radio-dot span (.ui-checkboxradio-icon) to each label
        // -- so the "Live View" toggle rendered as On/Off buttons WITH radio dots
        // instead of the clean two-segment switch 1.8.12's buttonset() produced.
        // Pre-enhance the radios with icon:false FIRST; controlgroup() then adopts
        // the already-enhanced checkboxradios (it won't re-init them) and the dots
        // are gone. Order matters: checkboxradio before controlgroup.
        jQuery( selector + ' > .autoRefreshControl > .buttons > input[type=radio]')
            .checkboxradio({ icon: false });
        jQuery( selector + ' > .autoRefreshControl > .buttons').controlgroup();
        
        var that = this;
        
        
        jQuery( selector + ' > .autoRefreshControl > .buttons > #autorefresh-on-button').click( function() {
            
            that.startAutoRefresh();            
        });
        
        jQuery( selector + ' > .autoRefreshControl > .buttons > #autorefresh-off-button').click( function() {
            
            that.stopAutoRefresh();
        });
        
        // bind window focus events to start auto refresh        
        jQuery(window).focus(function() { 
            
            // set flag
            that.isActive = true; 
            
            // enable auto-refesh if called for
            if ( that.getOption( 'autoRefreshResultSets' ) ) {
                
                that.startAutoRefresh();
            }
                
        });
        
        // bind window blur event to stop needless auto-refreshes
        jQuery(window).blur(function() { 
        
            // set flag
            that.isActive = false; 
            //pause. stops but keeps the option set to true
            if ( that.getOption( 'autoRefreshResultSets' ) ) {
               
                that.pauseAutoRefresh();
            }
        });
    
    },
    
    startAutoRefresh : function() {
        
        var interval = this.getOption( 'autoRefreshResultSetsInterval' );
        
        if (OWA.util.countObjectProperties( this.resultSetExplorers ) > 0 ) { 
            
            for ( var name in this.resultSetExplorers )    {
                
                if ( this.resultSetExplorers.hasOwnProperty( name ) ) {
                    
                    this.resultSetExplorers[name].enableAutoRefresh( interval );
                }
            }
        }
        
        // if there are any tabs, start their resultSetExplorers too.
        if ( this.activeTab ) {
        
            this.tabs[ this.activeTab ].startAutoRefresh();    
        }
        
        this.options.autoRefreshResultSets = true;
            
    },
    
    stopAutoRefresh : function() {
        
        if (OWA.util.countObjectProperties( this.resultSetExplorers ) > 0 ) { 
            
            for ( var name in this.resultSetExplorers )    {
                
                if ( this.resultSetExplorers.hasOwnProperty( name ) ) {
                    
                    this.resultSetExplorers[name].stopAutoRefresh( );
                }
            }
        }
        
        // if there are any tabs, stop their resultSetExplorers too.
        // if there are any tabs, start their resultSetExplorers too.
        if ( this.activeTab ) {
        
            this.tabs[ this.activeTab ].stopAutoRefresh();    
        }
        
        
        this.options.autoRefreshResultSets = false;        
        
    },
    
    pauseAutoRefresh : function() {
        
        this.stopAutoRefresh();
        this.options.autoRefreshResultSets = true;    
    },
    
    registerResultSetExplorer : function( name, rse ) {
        
        if ( this.getOption( 'autoRefreshResultSets' ) ) {
            rse.enableAutoRefresh( this.getOption( 'autoRefreshResultSetsInterval' ) );
        }
        
        this.resultSetExplorers[ name ] = rse;
    },

    overrideOptions: function( options ) {
        
        options = options || {};
        
        // override default options
        for ( var option in options ) {
            
            if ( options.hasOwnProperty( option ) ) {
                
                this.options[option] = options[option];
            }
        }            
    },
    
    getOption : function( name ) {
        
        if ( this.options.hasOwnProperty( name ) ) {
            
            return this.options[ name ];
        }
    },
    
    config: '',
    
    displayTimePeriodPicker : function(dom_id) {
        var that = this;
        dom_id = dom_id || '#owa_reportPeriodLabelContainer';
        
        if ( ! this.timePeriodControl ) {
        
            this.timePeriodControl = new OWA.report.timePeriodControl(
                    dom_id, 
                    {
                        startDate: this.getStartDate(), 
                        endDate: this.getEndDate(), 
                        selectedPeriod: this.getProperty('period') 
                    } 
            );
            
            //bind event listener for when a new date is set
            jQuery(dom_id).bind( 
                'owa_new_time_period_set',
                function(event, startDate, endDate) { 
                    
                    that.setDateRange(startDate, endDate);
                    that.reload(); 
                } 
            );
            
            // bind event listener for when new fixed period is set
            // this will go away once data picker sets it's own fixed
            // time periods instead of relying on the server to do it.
            jQuery(dom_id).bind( 
                'owa_new_fixed_time_period_set',
                function(event, period) { 
                    
                    that.reportSetTimePeriod(period);
                } 
            );    
        }
    },
    
    showSiteFilter : function(dom_id) {

        /*
         * Kept so report.php's existing call still works. The wiring itself is
         * OWA.initSiteControl(), which also runs on ready -- the control now
         * appears on the settings screens too, and those have no report object
         * to call this from.
         */
        OWA.initSiteControl();
    },
    
    reportSetTimePeriod : function(period) {

        this.setPeriod(period);
        this.reload();
    },
    
    reload: function() {
    
        /*
         * siteId is NOT read from a control any more. Choosing a Profile is a
         * link in the site control, so the browser navigates and the new siteId
         * arrives in the URL -- reload() reloads whatever is current, which is
         * what its other caller (the period picker) actually wants.
         *
         * It read '#owa_reportSiteFilterSelect option:selected' until the site
         * control replaced that select. That lookup would now return undefined
         * and the guard below would quietly skip it, so this would have kept
         * "working" while silently ignoring the site.
         */
        // reload report    
        var url = OWA.util.makeUrl(OWA.config.link_template, OWA.config.main_url, this.properties);
        window.location.href = url;
    },
        
    _parseDate: function (date) {
        
        
    },
    
    setDateRange: function (startDate, endDate) {
        
        this.setProperty( 'startDate', startDate );
        
        this.setProperty( 'endDate', endDate );
        
        this.removeProperty('period');
    },
    
    setPeriod: function(period) {
    
        this.properties.period = period;
        
        if ( this.properties.hasOwnProperty( 'startDate' ) ) {
            delete this.properties[ 'startDate' ];
        }
        
        if ( this.properties.hasOwnProperty( 'endDate' ) ) {
            delete this.properties[ 'endDate' ];
        }
    },
    
    addTab : function(obj) {
    
        if (obj.dom_id.length > 0 ) {
            this.tabs[obj.dom_id] = obj;
        } else {
            OWA.debug('tab cannot be added with no dom_id set.');
        }
    },
    
    createTabs : function() {
        
        var that = this;
        
        jQuery("#report-tabs").prepend('<ul class="report-tabs-nav-list"></ul>');
        for (var tab in this.tabs) {
    
            if ( this.tabs.hasOwnProperty(tab) ) {    
                jQuery("#report-tabs > .report-tabs-nav-list").append(OWA.util.sprintf( '<li><a href="#%s">%s</a></li>', tab, that.tabs[tab].label ) );
                
            }
        }
        
        jQuery("#report-tabs").tabs({
            // jQuery-UI 1.9+ renamed the `show` option to the `activate` event.
            // The ui payload also changed: the newly-shown panel is ui.newPanel
            // (a jQuery object), not ui.panel. selectTab() below reads the id off
            // whatever panel it is handed.
            activate: function(event, ui) {
                that.selectTab( ui.newPanel.attr('id') );
            }
        });

        // 1.8's `show` fired for the initially-selected tab AT CREATION; the 1.9+
        // `activate` event does NOT fire on init. Load the active tab explicitly so
        // its grid still builds on first render. tabs("option","active") is the
        // active index; map it back to the panel id.
        var activeIdx = jQuery("#report-tabs").tabs("option", "active");
        var activePanelId = jQuery("#report-tabs > div").eq(activeIdx).attr('id');
        if ( activePanelId ) {
            this.selectTab( activePanelId );
        }

    },

    // Load a tab by its panel id and swap auto-refresh from the previously active
    // tab to this one. Shared by createTabs' initial load and its activate handler.
    selectTab : function( panelId ) {

        OWA.debug('tab selected is: %s', panelId);
        this.tabs[ panelId ].load();

        // stop auto refresh of last selected tab
        if ( this.activeTab && this.getOption('autoRefreshResultSets') ) {
            this.tabs[ this.activeTab ].stopAutoRefresh();
        }

        this.activeTab = panelId;

        // start auto refresh of selected tab
        if ( this.activeTab && this.getOption('autoRefreshResultSets') ) {
            this.tabs[ this.activeTab ].startAutoRefresh();
        }
    },

    getSiteId : function() {
        
        return this.getProperty('siteId');
    },
    
    getPeriod : function() {
        
        return this.getProperty('period');
    },
    
    getStartDate : function() {
    
        return this.getProperty('startDate');
    },
    
    getEndDate : function() {
    
        return this.getProperty('endDate');
    },
    
    setRequestProperty : function(name, value) {
        
        this.setProperty(name, value);
    },
    
    setProperty : function (name, value) {
        
        this.properties[name] = value;
    },
    
    removeProperty : function( name ) {
        
        if ( this.properties.hasOwnProperty( name ) ) {
            
            delete this.properties[name];
        }
    },
    
    getProperty : function ( name ) {
        
        if ( this.properties.hasOwnProperty( name ) ) {
            
            return this.properties[name];
        }
    }
}

OWA.report.tab = function(dom_id) {
    this.dom_id = dom_id;
    this.resultSetExplorers = {};
    this.label = 'Default label';
    this.isLoaded = false;
    this.load = function() {
        if ( ! this.isLoaded ) {
            for (var rse in this.resultSetExplorers) {
                
                if (this.resultSetExplorers.hasOwnProperty(rse)) {
            
                    this.resultSetExplorers[rse].load();
                }
                
            }
            
            this.isLoaded = true;
        }
    }
}

OWA.report.tab.prototype = {

    startAutoRefresh : function() {
        
        for (var rse in this.resultSetExplorers) {
                
            if (this.resultSetExplorers.hasOwnProperty(rse)) {
        
                this.resultSetExplorers[rse].enableAutoRefresh();
            }
        }
    },
    
    stopAutoRefresh : function() {
        
        for (var rse in this.resultSetExplorers) {
                
            if (this.resultSetExplorers.hasOwnProperty(rse)) {
                
                this.resultSetExplorers[rse].stopAutoRefresh();
            }
        }
    },
    
    addRse : function (name, rse) {
        
        this.resultSetExplorers[name] = rse;
    },
    
    setLabel : function (label) {
        this.label = label;
    },
    
    setDomId : function (dom_id) {
        this.dom_id = dom_id;
    }
}

OWA.report.timePeriodControl = function( dom_id, options ) {
    
    var options = options || {};
    this.dom_id = dom_id || 'owa_reportPeriodControl';
    this.startDate = '';
    this.endDate = '';
    this.label = '';
    
    if (options.hasOwnProperty('startDate')) {
        this.setStartDate( options.startDate );
    }
    
    if (options.hasOwnProperty('endDate')) {
        this.setEndDate( options.endDate );
    }
    
    if (options.hasOwnProperty('selectedPeriod')) {
        this.setSelectedPeriod(options.selectedPeriod);
    }
    
    this.label = OWA.util.sprintf(
                        '%s - %s', 
                        this.formatYyyymmdd(this.getStartDate(), '/'), 
                        this.formatYyyymmdd(this.getEndDate(), '/')
                );

    this.setupDomElements();
    
};

OWA.report.timePeriodControl.prototype = {
    
    fixedPeriods: {
        
        today:                    'Today',
        yesterday:                 'Yesterday',
        this_week:                 'This Week',
        this_month:             'This Month',
        this_year:                 'This Year',
        last_week:                 'Last Week',
        last_month:                'Last Month',
        last_year:                'Last Year',
        last_seven_days:        'Last Seven Days',
        last_thirty_days:        'Last Thirty Days',
        same_day_last_week:        'Same Day Last Week',
        same_week_last_year:     'Same Week Last Year',
        same_month_last_year:    'Same Month Last Year'
    },
    
    setSelectedPeriod : function( period ) {
        
        this.selectedPeriod = period;
    },
    
    /**
     * yyyymmdd as the date string the CALENDARS parse.
     *
     * The year is FOUR digits, because the datepickers are created with
     * `dateFormat: 'mm-dd-yy'` and in jQuery UI's date format `yy` is a
     * four-digit year -- `y` is the two-digit one. This emitted two ('07-27-26'
     * for 20260727), so jQuery UI threw "Missing number at position 6" parsing
     * the defaultDate it was handed, the calendars never received the period's
     * dates, and they showed something unrelated to the period named beside
     * them.
     *
     * The label reads from this too, so a date range now shows its full year.
     */
    formatYyyymmdd : function( yyyymmdd, sep ) {

        sep = sep || '-';

        var year = yyyymmdd.substr(0,4);
        var month = yyyymmdd.substr(4,2);
        var day = yyyymmdd.substr(6,2);

        return month + sep + day + sep + year;
    },
    
    /**
     * yyyymmdd as a Date, the inverse of what the submit handler emits.
     *
     * 'yymmdd' is jQuery UI's four-digit-year-month-day, which is exactly the
     * shape the server speaks -- the same format the submit handler formats
     * back into. Returns null for an absent or unparseable value rather than
     * throwing, because a missing date must not take the whole picker down.
     */
    /**
     * Put the predefined-period menu back to its placeholder.
     *
     * Choosing specific dates means the report is no longer on a named period,
     * so leaving "Last Thirty Days" selected in the menu says something untrue
     * -- and it is the control the reader looks at to know what they are seeing.
     *
     * The placeholder is the first option and carries no value of its own, so it
     * is selected by INDEX rather than by a value that does not exist.
     */
    clearFixedPeriodSelection : function() {

        jQuery( '#owa_reportPeriodFilter' ).prop( 'selectedIndex', 0 );
    },

    parseYyyymmdd : function( yyyymmdd ) {

        if ( ! yyyymmdd ) {

            return null;
        }

        try {

            return jQuery.datepicker.parseDate( 'yymmdd', String( yyyymmdd ) );

        } catch ( e ) {

            return null;
        }
    },

    setStartDate : function( yyyymmdd ) {
        
        this.startDate = yyyymmdd;
    },
    
    setEndDate : function( yyyymmdd ) {
        
        this.endDate = yyyymmdd;
    },
    
    getStartDate : function() {
        
        return this.startDate;
    },
    
    getEndDate : function() {
        
        return this.endDate;
    },
    
    isValidDateString : function(str) {
        
        if (str.length != 10 ) {
            return false;
        }
        
        if (str.substr(2,1) != '-' || str.substr( 5,1) != '-' ) {
            return false;
        }

        return true;
    },
    
    setupDomElements : function() {
        
        //closure
        var that = this;
        
        // set template data obj
        var data = {
            periods:         this.fixedPeriods, 
            datelabel:         this.label,
            selectedPeriod: this.selectedPeriod
        };
        // compile the inlined template directly into the dom (no server fetch).
        {
            // inject into dom
            jQuery(that.dom_id).jqoteapp(FILTER_PERIOD_TMPL, data, '*');
    
            // register show/hide controls event handler
            jQuery("#owa_reportPeriodLabelContainer").click(function() {
                jQuery('#owa_reportPeriodFiltersContainer').toggle();
            });
            
            // bind handler to change start date picker when user enters date by hand.
            jQuery('#owa_report-datepicker-start-display').change( function() {
                var value = jQuery(this).val();
                if ( that.isValidDateString( value ) ) {
                    // set date picker
                    jQuery("#owa_report-datepicker-start").datepicker( "setDate", value );
                    // simulate triggering the onSelect event by calling the
                    // handler directly.
                    var func = jQuery("#owa_report-datepicker-start").datepicker("option","onSelect");
                    func(value);
                } else {
                    alert('Date must be in mm-dd-yyyy format.');
                    // wipe value
                    jQuery('#owa_report-datepicker-start-display').val('');
                }
            });
            
            // bind handler to change end date picker when user enters date by hand.
            jQuery('#owa_report-datepicker-end-display').change( function() {
                var value = jQuery(this).val();
                if ( that.isValidDateString( value ) ) {
                    // set date picker
                    jQuery("#owa_report-datepicker-end").datepicker( "setDate", value ); 
                    // simulate triggering the onSelect event by calling the
                    // handler directly.
                    var func = jQuery("#owa_report-datepicker-end").datepicker("option","onSelect");
                    func(value);
    
                } else {
                    alert('Date must be in mm-dd-yyyy format.');
                    // wipe value
                    jQuery('#owa_report-datepicker-end-display').val('');
                }
            });
            
            // create data picker objects
            jQuery("#owa_report-datepicker-start").datepicker({
                
                dateFormat: 'mm-dd-yy',
                altField: "#owa_report-datepicker-start-display",
                onSelect: function(selectedDate) {
                    // parse date
                    var instance = jQuery("#owa_report-datepicker-start").data( "datepicker" );
                    var date = jQuery.datepicker.parseDate(
                            instance.settings.dateFormat ||
                            jQuery.datepicker._defaults.dateFormat,
                            selectedDate, instance.settings );
                    
                    // Picking a day means this is a custom range.
                    that.clearFixedPeriodSelection();

                    // constrain min date
                    jQuery("#owa_report-datepicker-end").datepicker( "option", 'minDate', date);        
                    // constrain new max date using value from end date picker
                    jQuery("#owa_report-datepicker-start").datepicker( "option", 'maxDate',
                        jQuery("#owa_report-datepicker-end").datepicker( "getDate" )    
                    
                    );
                },
                defaultDate: that.formatYyyymmdd( that.getStartDate() )
            });    
            
            jQuery("#owa_report-datepicker-end").datepicker({
                
                dateFormat: 'mm-dd-yy',
                altField: "#owa_report-datepicker-end-display",
                onSelect: function(selectedDate) { 
                
                    // parse date
                    var instance = jQuery("#owa_report-datepicker-end").data( "datepicker" );
                    var date = jQuery.datepicker.parseDate(
                            instance.settings.dateFormat ||
                            jQuery.datepicker._defaults.dateFormat,
                            selectedDate, instance.settings );
                    
                    // Picking a day means this is a custom range.
                    that.clearFixedPeriodSelection();

                    // constrain min date using value from start date picker
                    jQuery("#owa_report-datepicker-end").datepicker( "option", 'minDate', 
                        jQuery("#owa_report-datepicker-start").datepicker( "getDate" )
                    
                    );        
                    // constrain new max date 
                    jQuery("#owa_report-datepicker-start").datepicker( "option", 'maxDate', date); 
                },
                defaultDate: that.formatYyyymmdd( that.getEndDate() )
            });

            /*
             * The two calendars bound each other from the start.
             *
             * Without this they only constrain one another once something is
             * clicked -- the onSelect handlers above are what set minDate and
             * maxDate -- so on load the end calendar would happily offer a date
             * before its own start.
             *
             * The dates themselves are already selected by the defaultDate each
             * picker was created with. That is worth stating because it looks
             * like it needs a setDate() and does not: for an INLINE picker
             * defaultDate is the selection, and it fills the display field too.
             * A setDate() here was measured to change nothing.
             */
            var owaStartDate = that.parseYyyymmdd( that.getStartDate() );
            var owaEndDate   = that.parseYyyymmdd( that.getEndDate() );

            if ( owaStartDate && owaEndDate ) {
                jQuery( "#owa_report-datepicker-end" ).datepicker( 'option', 'minDate', owaStartDate );
                jQuery( "#owa_report-datepicker-start" ).datepicker( 'option', 'maxDate', owaEndDate );
            }
            
            // trigger owa_new_time_period_set event when 
            // submit button is pressed
            jQuery('#owa_reportPeriodFilterSubmit').click(function() {

                // The report is on a date range now, not on a named period.
                that.clearFixedPeriodSelection();

                jQuery(that.dom_id).trigger(
                    'owa_new_time_period_set', 
                    [
                        jQuery.datepicker.formatDate(
                            'yymmdd', 
                            jQuery("#owa_report-datepicker-start").datepicker( "getDate" )
                        ),
                        jQuery.datepicker.formatDate(
                            'yymmdd', 
                            jQuery("#owa_report-datepicker-end").datepicker( "getDate" )  
                        )
                    ]
                );
            });
            
            // trigger change event when new fixed time period is selected
            // TODO: refactor this to just set new dates in the date pickers
            jQuery('#owa_reportPeriodFilter').change( function() { 
                
                var period = jQuery("#owa_reportPeriodFilter option:selected").val();
                jQuery(that.dom_id).trigger('owa_new_fixed_time_period_set', [period]);

            });

        }
    }
};

/*
 * Wire the site control wherever it appears.
 *
 * It is on report pages AND on the Organization / Property / Profile settings
 * screens, and those have no report object -- showSiteFilter() was only ever
 * called from report.php, so on a settings page the tile rendered and did
 * nothing when clicked.
 *
 * Idempotent: a page with both a report and the control would otherwise bind
 * every handler twice, and the toggle would open and immediately close.
 */
OWA.initSiteControl = function() {

    var $control = jQuery('#owa_siteControl');

    if ( ! $control.length || $control.data('owaControlBound') ) {
        return;
    }

    $control.data('owaControlBound', true);

        

        var $summary = jQuery('#owa_siteControlSummary');
        var $panel   = jQuery('#owa_siteControlPanel');

        function close() {
            $panel.attr('hidden', 'hidden');
            $control.removeClass('is-open');
            $summary.attr('aria-expanded', 'false');
        }

        function open() {
            $panel.removeAttr('hidden');
            $control.addClass('is-open');
            $summary.attr('aria-expanded', 'true');
        }

        $summary.on('click', function() {
            $control.hasClass('is-open') ? close() : open();
        });

        // The summary is a div acting as a button, so it has to answer the keys
        // a button would. Without this the control is unreachable by keyboard.
        $summary.on('keydown', function(e) {
            if ( e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' ) {
                e.preventDefault();
                $control.hasClass('is-open') ? close() : open();
            }
        });

        jQuery(document).on('keydown', function(e) {
            if ( e.key === 'Escape' && $control.hasClass('is-open') ) {
                close();
                $summary.focus();
            }
        });

        // Clicking away closes it. Bound on document and filtered rather than
        // on a backdrop element, so the panel does not need one covering the
        // page -- a backdrop would swallow the first click on anything else.
        jQuery(document).on('click', function(e) {
            if ( $control.hasClass('is-open') && ! jQuery(e.target).closest('#owa_siteControl').length ) {
                close();
            }
        });

        /*
         * A link inside the panel closes it too. The click navigates, so the
         * panel would go anyway on the new page -- but not before the browser
         * has spent a moment fetching it, and leaving an overlay open under a
         * pending navigation reads as though the click missed.
         */
        $panel.on('click', 'a', function() {
            close();
        });

        /*
         * Column 2 -> column 3. Selecting a Property shows ITS Profiles; the
         * lists are all rendered and toggled rather than fetched, because the
         * whole hierarchy is already on the page and a request per click would
         * make browsing it feel slower than the select it replaced.
         *
         * Not bound to the edit link: that is a navigation away, and swapping
         * column 3 underneath someone on their way out is just a flicker.
         */
        jQuery('.owa_siteControlProperties .owa_siteControlItem').on('click', function(e) {

            if ( jQuery(e.target).hasClass('owa_siteControlEdit') ) {
                return;
            }

            var index = jQuery(this).data('property-index');

            jQuery('.owa_siteControlProperties .owa_siteControlItem').removeClass('is-selected');
            jQuery(this).addClass('is-selected');

            jQuery('.owa_siteControlProfileList').attr('hidden', 'hidden');
            jQuery('.owa_siteControlProfileList[data-property-index="' + index + '"]').removeAttr('hidden');
        });
};

jQuery(document).ready(function() {
    OWA.initSiteControl();
});
