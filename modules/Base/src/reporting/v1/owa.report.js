// OWA is defined by owa.js; this module augments it (OWA.report = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';
import { PeriodPicker } from './owa.periodpicker.js';

OWA.report = function(dom_id, options) {
    
    this.options = {};
    
    this.overrideOptions(options);
    
    this.dom_id = dom_id;
    this.config = OWA.config;
    this.properties = {};
    this.tabs = {};
    this.timePeriodControl = '';
    // container for resultSetExplorer objects
    this.resultSetExplorers = {};
    // the dom id of the active tab
    this.activeTab = '';
    // the oldest "as of" any widget on this page has reported, unix seconds
    this.asOf = null;
};

OWA.report.prototype = {
    
    display : function() {
        
        
        
    },
    
    /**
     * "Data as of <time>" in the header, where the Live View switch was.
     *
     * Live View re-polled every widget on a timer, but a widget reads the
     * reporting cube, which moves only when a build runs -- so it refreshed
     * nothing. What a reader needs instead is when the numbers were built.
     *
     * Each widget's result carries asOf, the newest build time among the rows
     * it read, and announces it (OWA.resultSetExplorer.setResultSet). The page
     * shows the OLDEST of those: the widgets read the same cube, so they differ
     * only if a build landed while the page loaded, and then the older is the
     * honest stamp for the page.
     *
     * The container is rendered only for a period that is not settled yet,
     * with the installation's timezone and any warning already in it.
     */
    showAsOf : function( selector ) {

        var el = jQuery( selector );

        if ( ! el.length ) {

            return;
        }

        var that = this;

        jQuery( document ).on( 'owa:resultSetAsOf', function( e, asOf ) {

            if ( ! asOf || ( that.asOf && that.asOf <= asOf ) ) {

                return;
            }

            that.asOf = asOf;

            el.find( '.owa_reportAsOfTime' ).text( OWA.report.formatAsOf( asOf, el.data( 'timezone' ) ) );
            el.addClass( 'owa_reportAsOfKnown' );
        } );
    },

    registerResultSetExplorer : function( name, rse ) {

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
    
    /**
     * The period picker (owa.periodpicker.js), given the config
     * ReportController::periodPickerConfig() builds. Apply sets the period and
     * comparison parameters it hands back and reloads the report.
     */
    displayTimePeriodPicker : function( dom_id, config ) {

        var that = this;
        var el = document.querySelector( dom_id || '#owa_timePeriodControl' );

        // No dates means no config: drawing a picker of nothing would throw.
        if ( ! el || this.timePeriodControl || ! config || ! config.startDate || ! config.endDate ) {

            return;
        }

        /*
         * What the picker calls each period and comparison, for anything else
         * on the page that names them: a compared chart's key says "Last 30
         * days" and "Previous period", as the picker does, not their dates.
         */
        OWA.periodNames = { periods: {}, compares: {} };

        ( config.presets || [] ).forEach( function ( p ) { OWA.periodNames.periods[ p.key ] = p.label; } );
        ( config.compares || [] ).forEach( function ( c ) { OWA.periodNames.compares[ c.key ] = c.label; } );

        this.timePeriodControl = new PeriodPicker( el, config || {}, function( params ) {

            that.applyPeriodParams( params );
            that.reload();
        } );
    },

    /** Set (or, for null, remove) each period and comparison parameter. */
    applyPeriodParams : function( params ) {

        for ( var k in params ) {

            if ( ! params.hasOwnProperty( k ) ) {

                continue;
            }

            if ( params[ k ] === null || params[ k ] === '' ) {

                this.removeProperty( k );

            } else {

                this.setProperty( k, params[ k ] );
            }
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
         * The Property is NOT read from a control any more. Choosing one is a
         * link in the site control, so the browser navigates and the new
         * propertyId arrives in the URL -- reload() reloads whatever is
         * current, which is what its other caller (the period picker) actually
         * wants.
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

    // Load a tab by its panel id. Shared by createTabs' initial load and its
    // activate handler.
    selectTab : function( panelId ) {

        OWA.debug('tab selected is: %s', panelId);
        this.tabs[ panelId ].load();

        this.activeTab = panelId;
    },

    getPropertyId : function() {
        
        return this.getProperty('propertyId');
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

/**
 * An "as of" time as a person reads it, in the installation's zone -- the same
 * zone the reports' days are cut in -- rather than the browser's.
 *
 * @param {number} asOf      unix seconds
 * @param {string} timezone  IANA name; the browser's own if absent or unknown
 * @return {string}
 */
OWA.report.formatAsOf = function( asOf, timezone ) {

    var opts = { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' };

    if ( timezone ) {

        opts.timeZone = timezone;
    }

    try {

        return new Intl.DateTimeFormat( undefined, opts ).format( new Date( asOf * 1000 ) );

    } catch ( e ) {

        delete opts.timeZone;

        return new Intl.DateTimeFormat( undefined, opts ).format( new Date( asOf * 1000 ) );
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

        /*
         * Bring each column's selection into view.
         *
         * The columns scroll at 320px, and an install with many Properties
         * opens the panel showing the TOP of each list -- so the
         * one row that says where you are is below the fold, and the control
         * that exists to answer "where am I" does not.
         *
         * Scrolled rather than pinned: the selected row keeps its place in the
         * list, so its neighbours still say what is either side of it. Done on
         * open rather than once at load because the column has no height while
         * the panel is hidden, and scrollTop on a zero-height element is a
         * no-op.
         */
        function revealSelections() {

            $panel.find('.owa_siteControlColumn').each(function() {

                var column = this;
                var selected = column.querySelector('.owa_siteControlItem.is-selected');

                if ( ! selected ) {
                    return;
                }

                // Only when it is actually out of view: a short list is already
                // showing its selection, and scrolling it would move a list
                // that had no need to move.
                var top = selected.offsetTop - column.offsetTop;
                var bottom = top + selected.offsetHeight;

                if ( top < column.scrollTop ) {
                    column.scrollTop = top;
                } else if ( bottom > column.scrollTop + column.clientHeight ) {
                    column.scrollTop = bottom - column.clientHeight;
                }
            });
        }

        function open() {
            $panel.removeAttr('hidden');
            $control.addClass('is-open');
            $summary.attr('aria-expanded', 'true');
            revealSelections();
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
};

/*
 * The Property chooser is drawn in the header, beside the logo. Where the left
 * column becomes the slide-out menu (900px and narrower, as in owa.report.css)
 * it belongs in the menu instead. The one element moves, so its ids and its
 * handlers stay as they are.
 */
OWA.placeSiteControl = function() {

    var control = document.getElementById('owa_siteControl');
    var header  = document.querySelector('.owa_headerSiteControl');
    var rail    = document.querySelector('.owa_railSiteControl');

    if ( ! control || ! header || ! rail || ! window.matchMedia || OWA.siteControlPlaced ) {
        return;
    }

    OWA.siteControlPlaced = true;

    var narrow = window.matchMedia('(max-width: 900px)');

    function place() {

        var home = narrow.matches ? rail : header;

        if ( control.parentNode !== home ) {
            home.appendChild( control );
        }
    }

    place();

    if ( narrow.addEventListener ) {
        narrow.addEventListener( 'change', place );
    } else {
        narrow.addListener( place );
    }
};

jQuery(document).ready(function() {
    OWA.placeSiteControl();
    OWA.initSiteControl();
});
