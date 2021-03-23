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
            
            label= options.label;
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
        jQuery( selector + ' > .autoRefreshControl > .buttons').buttonset();
        
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
            
            for ( name in this.resultSetExplorers )    {
                
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
            
            for ( name in this.resultSetExplorers )    {
                
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
        for ( option in options ) {
            
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
        
        // create dom elements
        // ...
        
        // bind event handlers
        var that = this;
        jQuery('#owa_reportSiteFilterSelect').change( function() { that.reload(); } );
    },
    
    reportSetTimePeriod : function(period) {

        this.setPeriod(period);
        this.reload();
    },
    
    reload: function() {
    
        // add new site_id to properties
        var siteId = jQuery("#owa_reportSiteFilterSelect option:selected").val(); 
        OWA.debug(this.properties['action']);
        
        if (siteId != undefined) {
            this.properties['siteId'] = siteId;
        }
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
        for (tab in this.tabs) {
    
            if ( this.tabs.hasOwnProperty(tab) ) {    
                jQuery("#report-tabs > .report-tabs-nav-list").append(OWA.util.sprintf( '<li><a href="#%s">%s</a></li>', tab, that.tabs[tab].label ) );
                
            }
        }
        
        jQuery("#report-tabs").tabs({
            show: function(event, ui) {
                OWA.debug('tab selected is: %s', ui.panel.id);
                that.tabs[ui.panel.id].load();
                
                // stop auto refresh of last selected tab
                if ( that.activeTab && that.getOption('autoRefreshResultSets') ) {
                    that.tabs[ that.activeTab ].stopAutoRefresh();
                }
                                
                that.activeTab = ui.panel.id;
                
                // start auto refresh of  selected tab
                if ( that.activeTab && that.getOption('autoRefreshResultSets') ) {
                    that.tabs[ that.activeTab ].startAutoRefresh();
                }

            }
        });

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
            for (rse in this.resultSetExplorers) {
                
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
        
        for (rse in this.resultSetExplorers) {
                
            if (this.resultSetExplorers.hasOwnProperty(rse)) {
        
                this.resultSetExplorers[rse].enableAutoRefresh();
            }
        }
    },
    
    stopAutoRefresh : function() {
        
        for (rse in this.resultSetExplorers) {
                
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

    if ( ! OWA.isJsLoaded( 'jquery-ui') ) {
    
        OWA.requireJs( 
            'jquery-ui', 
            OWA.getOption('modules_url') + 'base/js/includes/jquery/jquery-ui-1.8.12.custom.min.js', 
            OWA.requireJs(
                'jqote', 
                OWA.getOption('modules_url') + 'base/js/includes/jquery/jQote2/jquery.jqote2.min.js',
                this.setupDomElements()
            )
        );
        
    } else {
        this.setupDomElements();
    }
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
    
    formatYyyymmdd : function( yyyymmdd, sep ) {
        
        sep = sep || '-';
        
        var year = yyyymmdd.substr(2,2);
        var month = yyyymmdd.substr(4,2);
        var day = yyyymmdd.substr(6,2);
        
        return month + sep + day + sep + year;
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
        // fetch template from server
        jQuery.get(OWA.getOption('modules_url') + 'base/templates/filter_period.php', function(tmpl) {
    
            // inject into dom
            jQuery(that.dom_id).jqoteapp(tmpl, data, '*');
    
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
                    
                    // constrain min date using value from start date picker
                    jQuery("#owa_report-datepicker-end").datepicker( "option", 'minDate', 
                        jQuery("#owa_report-datepicker-start").datepicker( "getDate" )
                    
                    );        
                    // constrain new max date 
                    jQuery("#owa_report-datepicker-start").datepicker( "option", 'maxDate', date); 
                },
                defaultDate: that.formatYyyymmdd( that.getEndDate() )
            });
            
            // trigger owa_new_time_period_set event when 
            // submit button is pressed
            jQuery('#owa_reportPeriodFilterSubmit').click(function() {
            
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
            
        });    
    }
};