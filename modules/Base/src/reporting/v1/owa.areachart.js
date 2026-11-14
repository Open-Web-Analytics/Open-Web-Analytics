// OWA is defined by owa.js; this module augments it (OWA.areaChart = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';
import { formatRange, describeChange } from './owa.periodpicker.js';

OWA.areaChart = function( options ) {

    // config options
    this.options = {
        
        series: [],
        // The plot's height where its container does not set one.
        height: 250,
        // A title over the chart, beside its controls. Off where the widget
        // already has a heading of its own.
        showTitle: true,
        // The total filled under its line. Off for a card: a line is enough there.
        fill: true,
        width:    '99%', // needed for flot resize plugin
        xaxis: {
            mode: 'time'
            
        },
        // A day's tick, and a month's: as Intl.DateTimeFormat takes them, in the install's locale (flotDates()).
        dayTick: { month: '2-digit', day: '2-digit' },
        showGrid: true,
        showLegend: true,
        showDots: false,
        lineWidth: 2.5,
        autoResizeCharts: true,
        fillColor: "rgba(24, 116, 205, 0.07)",

        /*
         * The plot's furniture, kept quiet so the line is what is read:
         * horizontal rules only, and the axis labels in the page's grey.
         */
        gridColor: "#6b7280",
        tickColor: "#eef0f3",

        /*
         * One colour per line, and the SAME ones the pie uses.
         *
         * A report shows traffic sources as a pie and the same sources as lines
         * over time; giving them different colours in each makes the reader do
         * work the colour was supposed to save. See OWA.chartColors.
         */
        colors: OWA.chartColors,

        /*
         * The total keeps the colour a trend has always been drawn in, because
         * it IS the trend: the same filled area, with the lines it is made of
         * drawn over it.
         */
        totalColor: "#1874CD",

        /*
         * How many of a dimension's values get their own line.
         *
         * A breakdown by page path has as many values as there are pages, and
         * a hundred lines is not a chart. The six largest get a line each and
         * the rest are not drawn.
         *
         * The total behind them is still the sum of EVERY row, not of the six
         * -- it is the shape of the whole thing, which is the one line that
         * would be wrong to leave anything out of.
         */
        maxSeries: 6,

        /* What the other lines fade to when one is selected in the legend. */
        dimmedOpacity: 0.5,

        /*
         * The x axis a reader can choose between, and what each queries.
         *
         * yearMonth, not month: on v2 `month` is the month of the YEAR, 1 to 12,
         * so a monthly trend grouped by it merged January of one year with
         * January of the next.
         */
        /*
         * Hours up to eight days: Last Seven Days starts at the end of the day
         * a week ago, so it covers eight dates.
         */
        granularities: [
            { dimension: 'dateHour', maxDays: 8 },
            { dimension: 'date' },
            { dimension: 'yearMonth' }
        ],

        /*
         * The narrowest an hour label is given, at a 16px root (rootScale()).
         * "12pm" and "10/30" are about forty pixels in the axis font, so this
         * leaves a gap between them.
         */
        hourTickWidth: 56,

        /*
         * The room a date label and a legend entry are given at a 16px root,
         * where the axis and legend font is 12px. A date tick is wider than
         * its label, "10/30": flot rounds the step between ticks down to a
         * day count it likes, which can double the number asked for.
         */
        dateTickWidth: 80,
        legendEntryWidth: 120,

        monthTick: { month: 'short', year: 'numeric' }
    };
    
    // merge passed options with defaults.
    if ( options ) {
        
        for (var option in options) {
            
            if ( options.hasOwnProperty( option ) ) {
                this.options[ option ] = options[ option ];
            }
        }
    }
    
    this.dom_id = '';
    this.domSelector = '';
    this.init = false;
}

/**
 * The root font size over 16px. The chart's label widths are measured at a
 * 16px root; a phone's larger root, or the browser's text size setting,
 * makes every label wider by this much.
 */
OWA.areaChart.rootScale = function () {

    var px = parseFloat( window.getComputedStyle( document.documentElement ).fontSize );

    return px > 0 ? px / 16 : 1;
};

/**
 * How many things `each` pixels wide fit across `width`: at least one, at
 * most `most`. An unknown width (0, not laid out) gives `most`.
 */
OWA.areaChart.fitCount = function ( width, each, most ) {

    most = Math.max( 1, most || 1 );

    if ( ! width ) {

        return most;
    }

    return Math.max( 1, Math.min( most, Math.floor( width / each ) ) );
};

/**
 * Where a tooltip w wide and h tall goes beside the pointer: right of it and
 * below, flipped left or above only where that side runs out of the view (the
 * window's scrolled box: left, top, width, height), and never outside it.
 */
OWA.areaChart.besidePointer = function ( pointer, w, h, view ) {

    var gap = 12, edge = 8;
    var left = pointer.x + gap;
    var top  = pointer.y + gap;

    if ( left + w > view.left + view.width - edge ) {

        left = pointer.x - gap - w;
    }

    if ( top + h > view.top + view.height - edge ) {

        top = pointer.y - gap - h;
    }

    return {
        left: Math.max( view.left + edge, Math.min( left, view.left + view.width - edge - w ) ),
        top:  Math.max( view.top + edge, top )
    };
};

OWA.areaChart.prototype = {
        
    setDomId: function(dom_id) {
        
        this.dom_id = dom_id;
        this.domSelector = "#" + dom_id + ' > .owa_areaChart';
        
        /*
         * ONE subscription, however many times this is called.
         *
         * setDomId runs from makeAreaChart AND from every generate() that is
         * handed a dom_id -- which is every changeMetric and every changeGranularity
         * -- so a plain bind() added a handler per interaction, and each one
         * redraws the whole chart on the next result set. Namespaced and
         * unbound first, so the count stays at one no matter how much the
         * reader has been clicking.
         */
        var that = this;
        jQuery( '#' + that.dom_id )
            .off( 'new_result_set.owaAreaChart' )
            .on( 'new_result_set.owaAreaChart', function( event, resultSet ) {
                that.generate( resultSet );
            });
        
    },
    
    getOption: function( name ) {
        
        if ( this.options.hasOwnProperty( name ) ) {
            return this.options[ name ];
        }
    },
    
    setOption: function ( name, value ) {
        
        this.options[name] = value;
    },
    
    getContainerHeight : function() {
        
        var that = this;
        var h =  jQuery("#"+that.dom_id).height();
        //alert(h);
        return h;    
    },
    
    // move to OWA.util
    formatValue : function(type, value) {
        
        switch(type) {
            // convery yyyymmdd to javascript timestamp as  flot requires that
            case 'yyyymmdd':
                
                //date = jQuery.datepicker.parseDate('yymmdd', value);
                //value = Date.parse(date);
                var year = value.substring(0,4) * 1;
                var month = (value.substring(4,6) * 1) -1;
                var day = value.substring(6,8) * 1; 
                var d = Date.UTC(year,month,day,0,0,0,0);
                value = d;
                OWA.debug('year: %s, month: %s, day: %s, timestamp: %s',year,month,day,d);
                break;
                
            // 202608 -> the first of that month. yearMonth stores yyyymm, which
            // is why a month axis orders correctly across a year boundary.
            case 'yyyymm':

                var m_year  = String( value ).substring( 0, 4 ) * 1;
                var m_month = ( String( value ).substring( 4, 6 ) * 1 ) - 1;

                value = Date.UTC( m_year, m_month, 1, 0, 0, 0, 0 );
                break;

            // 2026103014 -> 2pm on 10/30. The hour is the install's wall clock,
            // stored as if it were UTC, as a yyyymmdd day already is.
            case 'yyyymmddhh':

                var h_text = String( value );

                value = Date.UTC( h_text.substring( 0, 4 ) * 1, ( h_text.substring( 4, 6 ) * 1 ) - 1,
                    h_text.substring( 6, 8 ) * 1, h_text.substring( 8, 10 ) * 1, 0, 0, 0 );
                break;

            case 'currency':
                value = value/100;
        }
        
        return value;
    },
    
    /** A point's date in the install's locale: "10/30/2026", and its hour on an hourly chart. */
    timestampFormatter : function(timestamp) {

        var date = OWA.util.formatDate( new Date( timestamp * 1 ), { year: 'numeric', month: 'numeric', day: 'numeric', timeZone: 'UTC' } );

        if ( this.xType === 'yyyymmddhh' ) {

            date += ' ' + this.hourLabel( timestamp, false );
        }

        return date;
    },

    /**
     * An hour as the install's locale writes it -- "12am" to "11pm" in
     * English, "0 Uhr" in German -- or the date, "10/30", at a midnight when
     * the chart spans more than a day.
     */
    hourLabel : function ( timestamp, dateAtMidnight ) {

        var d = new Date( timestamp * 1 );

        if ( dateAtMidnight && d.getUTCHours() === 0 ) {

            return OWA.util.formatDate( d, { month: 'numeric', day: 'numeric', timeZone: 'UTC' } );
        }

        var f = OWA.util.dateFormat( { hour: 'numeric', timeZone: 'UTC' } );

        // English as a tick has room for: "12am", not "12 AM".
        if ( /^en\b/i.test( f.resolvedOptions().locale ) ) {

            return f.formatToParts( d ).map( function ( p ) {

                return p.type === 'literal' ? p.value.trim() : ( p.type === 'dayPeriod' ? p.value.toLowerCase() : p.value );

            } ).join( '' );
        }

        return f.format( d );
    },

    /**
     * flot's time axis options for a date as the install's locale writes it,
     * options as Intl.DateTimeFormat takes them: the strftime pattern flot
     * reads (timeformat) and, for a month by name, the months' names. In
     * English { month: 'short', year: 'numeric' } is "%b %Y".
     */
    flotDates : function ( options ) {

        var f     = OWA.util.dateFormat( Object.assign( { timeZone: 'UTC' }, options ) );
        var named = options.month === 'short' || options.month === 'long';
        var codes = { year: '%Y', month: named ? '%b' : '%m', day: '%d' };
        var names = [];

        var format = f.formatToParts( new Date( Date.UTC( 2026, 10, 14 ) ) ).map( function ( p ) {

            return codes[ p.type ] || p.value;

        } ).join( '' );

        for ( var m = 0; m < 12; m++ ) {

            names.push( f.formatToParts( new Date( Date.UTC( 2026, m, 1 ) ) )
                .filter( function ( p ) { return p.type === 'month'; } )
                .map( function ( p ) { return p.value; } ).join( '' ) );
        }

        return named ? { timeformat: format, monthNames: names } : { timeformat: format };
    },

    /**
     * Ticks on whole hours, as many as the width has room for.
     *
     * The step is the smallest of 1, 2, 3, 4, 6, 12, 24 and 48 hours that fits,
     * so a full-width chart of a day is labelled every two hours and a card
     * every six. Ticks sit on hours the step divides, so a midnight is always
     * one of them.
     */
    hourTicks : function ( min, max, width ) {

        var HOUR  = 3600000;
        var room  = Math.max( 2, Math.floor( width / this.options.hourTickWidth ) );
        var span  = Math.max( 0, Math.round( ( max - min ) / HOUR ) );
        var steps = [ 1, 2, 3, 4, 6, 12, 24, 48 ];
        var step  = steps[ steps.length - 1 ];

        for ( var i = 0; i < steps.length; i++ ) {

            if ( Math.floor( span / steps[i] ) + 1 <= room ) {

                step = steps[i];
                break;
            }
        }

        var ticks = [];

        for ( var t = min; t <= max; t += HOUR ) {

            // From the epoch, not the hour of the day, so a 48-hour step still
            // lands on alternate midnights.
            if ( Math.round( t / HOUR ) % step === 0 ) {

                ticks.push( t );
            }
        }

        return ticks;
    },
    
    /**
     * Main method for displaying an area chart
     */
    /**
     * A legend entry's label, as HTML: flot inserts what this returns as
     * markup, and the label is a tracked value.
     */
    legendLabel : function( label ) {

        return OWA.util.escapeHtml( label );
    },

    generate : function( resultSet, series, dom_id ) {
        OWA.debug('generating area chart for ' + dom_id);
        // set dom_id just in case.
        if ( dom_id ) {
        
            this.setDomId( dom_id );
        }
        
        dom_id = this.dom_id;
        
        // set series just in case.
        if ( series ) {
        
            this.options.series = series;
        }
        
        var selector = this.domSelector;
        
        // remove in case the chart is already there.
        // this is kind of a hack as it mean that only one area chart can be placed in a dom_id at a time.
        // this is needed so that charts can be over riden when report
        // tabs change.
        jQuery( selector ).remove();
        
        // if there is data, plot it.
        if ( resultSet.resultsRows.length > 0 ) {

            series = this.options.series;

            /*
             * A trend charts ONE metric.
             *
             * What varies is the DIMENSION: given one, each of its values
             * becomes a line -- visits by medium is a line per medium -- and
             * the filled area behind them is their total. Given none, the
             * metric itself is the single filled area, which is what a trend
             * has always been and what all sixty-one shipped ones still are.
             *
             * The call shape is unchanged: an array of {x, y}, now with an
             * optional `series` naming the dimension to break down by. Four
             * older templates still call it the old way and go on working.
             */
            var spec = series[0] || {};

            var x_name    = spec.x;
            var y_name    = spec.y;
            var breakdown = spec.series || null;

            this.xDimension = x_name;

            var built = breakdown
                ? this.seriesByDimension( resultSet, x_name, y_name, breakdown )
                : this.withGridRows( this.singleSeries( resultSet, x_name, y_name ), resultSet, x_name, y_name );

            var dataseries = built.series;

            this.setupAreaChart( series, dom_id );

            var num_ticks = built.points;

            // reduce number of x axis ticks if data set has too many points.
            if ( num_ticks > 10 ) {

                num_ticks = 10;
            }

            this.flotOptions = {

                yaxis: this.yAxis( resultSet, y_name, dataseries ),
                xaxis:{
                    ticks: num_ticks,
                    tickDecimals: null,
                    tickLength: 0
                },
                grid: {show: this.options.showGrid, hoverable: true, clickable: true,
                       // The active point is highlighted here (onHover), not by flot's nearest-point guess.
                       autoHighlight: false, borderWidth:0, borderColor: null,
                       color: this.options.gridColor, tickColor: this.options.tickColor},
                series: {
                    points: { show: this.options.showDots, fill: this.options.showDots },

                    /*
                     * Fill is decided PER SERIES, on the series itself.
                     *
                     * The total is an area, the way a trend has always looked;
                     * the lines broken out of it are lines. Several translucent
                     * fills stacked on each other muddy every colour underneath,
                     * which is the whole reason the breakdown is not filled.
                     */
                    lines: {
                        show: true,
                        fill: false,
                        lineWidth: this.options.lineWidth
                    }
                },
                legend: {
                    // The compared period is not a series of its own: it has no entry.
                    show: this.options.showLegend
                        && dataseries.filter( function ( s ) { return ! s.isCompare; } ).length > 1,

                    /*
                     * BELOW the chart, not floating inside it.
                     *
                     * flot draws its own legend over the plot area, where it
                     * covers the very data it is labelling -- tolerable for one
                     * entry, useless for eight. Given a container it renders
                     * there instead, and the container sits after the plot in
                     * the widget, which puts it under the x-axis labels.
                     */
                    container: jQuery( '#' + dom_id + ' > .owa_chartLegend' ),
                    // As many entries a row as the width has room for (draw()).
                    noColumns: dataseries.length,

                    /*
                     * Escaped: flot writes a label into the legend as HTML, and
                     * a breakdown's labels are tracked values -- a page title,
                     * a campaign name -- stored as the text that was sent.
                     */
                    labelFormatter: this.legendLabel
                },
                hooks: { drawSeries: [ this.dashLine ], drawOverlay: [ this.crosshairHook() ] }
            };

            this.xType = built.x_type;

            if ( built.x_type === 'yyyymmddhh' ) {

                this.hourAxis( this.flotOptions.xaxis );
            }

            if ( built.x_type === 'yyyymmdd' || built.x_type === 'yyyymm' ) {

                this.flotOptions.xaxis.mode = "time";

                // A month axis labelled with days would repeat the same day
                // number every tick.
                Object.assign( this.flotOptions.xaxis, this.flotDates(
                    built.x_type === 'yyyymm' ? this.options.monthTick : this.options.dayTick ) );
            }

            OWA.debug('Plotting area graph in ' + selector);

            /*
             * A colour each, fixed here rather than left to flot's rotation, so
             * the legend, the lines and the dimming all agree about which
             * colour belongs to which line. The total is always first; a
             * comparison takes the colour of the line it is the comparison of.
             */
            var nth = 0;

            for ( var c = 0; c < dataseries.length; c++ ) {

                if ( ! dataseries[c].isCompare ) {

                    dataseries[c].color = dataseries[c].isTotal
                        ? this.options.totalColor
                        : this.options.colors[ nth++ % this.options.colors.length ];
                }
            }

            for ( var k = 0; k < dataseries.length; k++ ) {

                if ( dataseries[k].isCompare ) {

                    dataseries[k].color = dataseries[ dataseries[k].compareOf || 0 ].color;
                }
            }

            this.dataseries = dataseries;
            this.selected   = null;
            this.hovered    = null;
            this.activeX    = null;

            this.draw();

            this.bindLegend();

            this.drawGranularityControl( dom_id );

            this.drawTitle( dom_id );

            this.watchWidth( dom_id );

            this.init = true;

        } else {

            /*
             * STILL A CHART, with nothing in it.
             *
             * An empty period used to replace the whole widget with a line of
             * text and collapse it to 50px -- so a report with no data for the
             * period changed SHAPE, and the reader had to work out whether the
             * chart was missing or the data was. The axes and the grid are the
             * frame of the answer; they belong there whether or not anything
             * has been plotted on them.
             *
             * The same path as a chart with data, minus the series: setup, plot,
             * watch the width. A caption over the plot says which of the two
             * empty states this is.
             */
            /*
             * ZERO ACROSS THE PERIOD, drawn as a trend.
             *
             * A period with nothing in it is not an absence of a chart, it is a
             * chart of zero -- and for a count that is the true answer rather
             * than a stand-in for one. So the series is synthesised: one point
             * per day of the period being reported on, all of them zero.
             *
             * The result set carries the period it was asked for, so the days
             * come from the question rather than from the answer -- which is
             * the only place they could come from when the answer is empty.
             */
            var zeroX = ( this.options.series[0] && this.options.series[0].x ) || 'date';
            var zero  = this.zeroFilled( resultSet, zeroX );

            this.xDimension = zeroX;
            this.xType      = zero ? zero.x_type : '';
            this.selected   = null;

            this.setupAreaChart( this.options.series, dom_id );

            if ( zero ) {

                this.dataseries = [ this.areaSeries( '', zero.points ) ];

                this.flotOptions = {

                    /*
                     * Stated, not derived. Every point is zero, and flot given a
                     * flat series scales to it -- an axis running -1 to 1, which
                     * reads as a chart of negative numbers.
                     */
                    yaxis: { min: 0, max: 1, tickDecimals: 0 },
                    xaxis: Object.assign( {
                        mode: 'time',
                        ticks: Math.min( 10, zero.points.length ),
                        tickLength: 0
                    }, this.flotDates( zero.x_type === 'yyyymm' ? this.options.monthTick : this.options.dayTick ) ),
                    grid: { show: this.options.showGrid, hoverable: true,
                            borderWidth: 0, borderColor: null,
                            color: this.options.gridColor, tickColor: this.options.tickColor },
                    series: {
                        points: { show: false },
                        lines: { show: true, fill: true, lineWidth: this.options.lineWidth }
                    },
                    legend: { show: false }
                };

                if ( zero.x_type === 'yyyymmddhh' ) {

                    this.hourAxis( this.flotOptions.xaxis );
                }

                this.dataseries[0].color = this.options.totalColor;

            } else {

                /*
                 * No period to draw against -- a result set handed in directly
                 * carries none. An axis and a caption, which is better than a
                 * widget that collapses to a line of text.
                 */
                this.dataseries = [ { data: [] } ];

                this.flotOptions = {
                    yaxis: { min: 0, max: 1, ticks: [ 0, 1 ] },
                    xaxis: { min: 0, max: 1, ticks: [] },
                    grid:  { show: this.options.showGrid, hoverable: false,
                             borderWidth: 0, borderColor: null,
                             color: this.options.gridColor, tickColor: this.options.tickColor },
                    series: { lines: { show: false }, points: { show: false } },
                    legend: { show: false }
                };

                jQuery( '#' + dom_id + ' > .owa_areaChart' ).append(
                    jQuery( '<div class="owa_chartEmpty"></div>' ).text( OWA.l( 'No data for this period' ) ) );
            }

            this.draw();

            this.drawGranularityControl( dom_id );

            this.drawTitle( dom_id );

            this.watchWidth( dom_id );

            this.init = true;
        }
    },

    /**
     * A point per interval of the reported period, all zero.
     *
     * For an EMPTY result set. The rows cannot say which days were asked about
     * -- there are none -- so the period does: the result set carries the
     * timePeriod it was queried for, and that is the question the empty answer
     * belongs to.
     *
     * Hours, days or months, matching whatever the chart is drawn over, so
     * the granularity control still means something on an empty report.
     *
     * @return {points, x_type} or null when there is no period to draw against
     */
    zeroFilled : function ( resultSet, x_name ) {

        var period = ( resultSet && resultSet.timePeriod ) || null;

        if ( ! period || ! period.startDate || ! period.endDate ) {

            return null;
        }

        var asDate = function ( yyyymmdd ) {

            var text = String( yyyymmdd );

            return new Date( Date.UTC(
                text.substring( 0, 4 ) * 1,
                ( text.substring( 4, 6 ) * 1 ) - 1,
                text.substring( 6, 8 ) * 1 ) );
        };

        var start = asDate( period.startDate );
        var end   = asDate( period.endDate );

        if ( isNaN( start.getTime() ) || isNaN( end.getTime() ) || end < start ) {

            return null;
        }

        var points = [];

        if ( x_name === 'dateHour' ) {

            /*
             * Every hour of every day, up to the hour it is now: today's
             * remaining hours have not happened, so they are not zeroes.
             * currentHour is the server's, in the zone the hours are read in.
             */
            var now  = period.currentHour ? String( period.currentHour ) : '';
            var last = now ? Date.UTC( now.substring( 0, 4 ) * 1, ( now.substring( 4, 6 ) * 1 ) - 1,
                now.substring( 6, 8 ) * 1, now.substring( 8, 10 ) * 1 ) : Infinity;

            var hour = new Date( start.getTime() );
            var stop = Math.min( end.getTime() + 23 * 3600000, last );

            while ( hour.getTime() <= stop && points.length < 24 * 31 ) {

                points.push( [ hour.getTime(), 0 ] );
                hour.setUTCHours( hour.getUTCHours() + 1 );
            }

            return { points: points, x_type: 'yyyymmddhh' };
        }

        if ( x_name === 'yearMonth' ) {

            var month = new Date( Date.UTC( start.getUTCFullYear(), start.getUTCMonth(), 1 ) );

            while ( month <= end && points.length < 120 ) {

                points.push( [ month.getTime(), 0 ] );
                month.setUTCMonth( month.getUTCMonth() + 1 );
            }

            return { points: points, x_type: 'yyyymm' };
        }

        var day = new Date( start.getTime() );

        // Bounded: a hand-edited date range could otherwise ask for a point per
        // day of a decade, and a chart of four thousand zeroes is not a chart.
        while ( day <= end && points.length < 400 ) {

            points.push( [ day.getTime(), 0 ] );
            day.setUTCDate( day.getUTCDate() + 1 );
        }

        return { points: points, x_type: 'yyyymmdd' };
    },

    /**
     * One metric over time: the filled area a trend has always been.
     *
     * @return {series, points, x_type}
     */
    singleSeries : function ( resultSet, x_name, y_name ) {

        var data   = [];
        var x_type = '';

        for ( var i = 0; i < resultSet.resultsRows.length; i++ ) {

            var row = resultSet.resultsRows[i];

            x_type = row[ x_name ].data_type;

            /*
             * The y value goes through formatValue too.
             *
             * Currency is stored in minor units and formatValue is what divides
             * it by a hundred -- so without this a revenue trend plotted 6300
             * and the axis, which labels in major units, read $6,300.00 for
             * $63.00. Every other type passes through unchanged, which is why
             * dropping this went unnoticed until an axis put a unit on it.
             */
            data.push( [
                this.formatValue( x_type, row[ x_name ].value ),
                this.formatValue( row[ y_name ].data_type, row[ y_name ].value ) * 1
            ] );
        }

        var xs = this.periodXs( resultSet, x_name, x_type );

        if ( xs ) {

            var byX = {};

            data.forEach( function ( p ) { byX[ p[0] ] = p[1]; } );

            // An interval with no row is zero, whatever the metric: see periodXs().
            data = xs.map( function ( x ) {
                return [ x, byX.hasOwnProperty( x ) ? byX[ x ] : 0 ];
            } );
        }

        var series  = [ this.areaSeries( resultSet.getMetricLabel( y_name ), data ) ];
        var compare = this.compareSeries( resultSet, x_name, y_name, x_type, xs );

        if ( compare ) {

            series.push( compare );
        }

        return {
            series: series,
            points: data.length,
            x_type: x_type
        };
    },

    /**
     * The compared period as a line over the period's area, or null.
     *
     * From resultSet.compare.rows: the comparison's own rows with their
     * dates already moved onto the period's (CompareMerger), so the two line
     * up day for day. Each point keeps the date it really is, for the
     * tooltip.
     */
    compareSeries : function ( resultSet, x_name, y_name, x_type, xs ) {

        var rows = resultSet.compare && resultSet.compare.rows;

        /*
         * A comparison with no rows at all is still a line when the period's
         * days are known: nothing happened then, a line at zero.
         */
        if ( ! rows || ( ! rows.length && ! xs ) ) {

            return null;
        }

        var that = this;
        var byX  = {};
        var when = {};

        rows.forEach( function ( row ) {

            if ( ! row[ x_name ] || ! row[ y_name ] ) {

                return;
            }

            var x = that.formatValue( x_type, row[ x_name ].value );

            byX[ x ]  = that.formatValue( row[ y_name ].data_type, row[ y_name ].value ) * 1;
            when[ x ] = row[ x_name ].compare_period_value;
        } );

        var axis = xs || Object.keys( byX ).map( Number ).sort( function ( a, b ) { return a - b; } );

        var data = axis.map( function ( x ) {
            return [ x, byX.hasOwnProperty( x ) ? byX[ x ] : 0 ];
        } );

        /*
         * The same metric over other dates, so the same colour, dashed, and no
         * label: a legend entry would make it read as one more series to add
         * to the rest.
         */
        return {
            isCompare: true,
            data: data,
            // The date each point really is, by x, and the metric: for the tooltip.
            compareDates: when,
            tooltipLabel: resultSet.getMetricLabel( y_name ),
            dashed: data.length > 1,
            // What the key under the chart calls the two lines.
            keyLabels: this.compareKeyLabels( resultSet ),
            lines: { show: true, fill: false, lineWidth: 1.5 },
            shadowSize: 0,
            points: data.length === 1 ? { show: true, fill: true, radius: 3 } : undefined
        };
    },

    /**
     * The rows of the grid under the chart, as lines over its total.
     *
     * OWA.linkTrendToGrid hands the chart the top rows the grid is showing
     * (plotRows); their numbers come from a query of their own, by the x axis
     * and the grid's dimensions and restricted to those rows (rowValues), so
     * the total stays the trend's own, not the sum of a few rows.
     *
     * Each line keeps its comparison when the report is compared, drawn only
     * while that line is hovered or selected -- with several lines, a dashed
     * partner for every one of them would be unreadable.
     *
     * The series are ordered lines first and comparisons last, so a legend
     * entry's index is its series' index.
     */
    withGridRows : function ( built, resultSet, x_name, y_name ) {

        if ( ! this.plot || ! this.plot.rows.length ) {

            return built;
        }

        if ( ! this.rowsResultSet || this.rowsX !== x_name ) {

            // Fetched for another axis -- the reader changed days to months.
            this.fetchRows();

            return built;
        }

        var that  = this;
        var dims  = this.plot.dims;
        var total = built.series.filter( function ( s ) { return ! s.isCompare; } )[0];
        var totalCompare = built.series.filter( function ( s ) { return s.isCompare; } )[0];
        var xs    = total.data.map( function ( p ) { return p[0]; } );
        var cmpRs = this.rowsResultSet.compare;

        var keyOf = function ( values ) {

            return dims.map( function ( d ) { return String( values[ d ] ); } ).join( '\u0001' );
        };

        var collect = function ( rows ) {

            var by = {};

            ( rows || [] ).forEach( function ( row ) {

                if ( ! row[ x_name ] || ! row[ y_name ] ) {

                    return;
                }

                var values = {};

                dims.forEach( function ( d ) { values[ d ] = row[ d ] ? row[ d ].value : ''; } );

                var key = keyOf( values );
                var x   = that.formatValue( row[ x_name ].data_type, row[ x_name ].value );

                by[ key ] = by[ key ] || { points: {}, when: {} };
                by[ key ].points[ x ] = ( by[ key ].points[ x ] || 0 )
                    + that.formatValue( row[ y_name ].data_type, row[ y_name ].value ) * 1;

                if ( row[ x_name ].compare_period_value ) {

                    by[ key ].when[ x ] = row[ x_name ].compare_period_value;
                }
            } );

            return by;
        };

        var main = collect( this.rowsResultSet.resultsRows );
        var cmp  = cmpRs ? collect( cmpRs.rows ) : null;

        var dataOf = function ( entry ) {

            return xs.map( function ( x ) {

                return [ x, entry && entry.points.hasOwnProperty( x ) ? entry.points[ x ] : 0 ];
            } );
        };

        var one   = xs.length === 1 ? { show: true, fill: true, radius: 3 } : undefined;
        var lines = [];
        var compares = [];

        total.label = OWA.l( 'Total' );

        this.plot.rows.forEach( function ( row, i ) {

            var key = keyOf( row.values );

            lines.push( {
                label: row.label,
                rowKey: key,
                data: dataOf( main[ key ] ),
                lines: { fill: false },
                points: one
            } );

            if ( cmp ) {

                compares.push( {
                    isCompare: true,
                    compareOf: i + 1,
                    data: dataOf( cmp[ key ] ),
                    compareDates: cmp[ key ] ? cmp[ key ].when : {},
                    tooltipLabel: row.label,
                    dashed: xs.length > 1,
                    lines: { show: true, fill: false, lineWidth: 1.5 },
                    shadowSize: 0,
                    points: one
                } );
            }
        } );

        if ( totalCompare ) {

            totalCompare.compareOf = 0;
            compares.unshift( totalCompare );
        }

        return {
            series: [ total ].concat( lines, compares ),
            points: built.points,
            x_type: built.x_type
        };
    },

    /**
     * Chart these rows of the grid, or none.
     *
     * @param array dims the grid's dimensions
     * @param array rows [{ values: { dim: value }, label }] in the grid's order
     * @param string label the dimensions' names, for the chart's title
     */
    plotRows : function ( dims, rows, label ) {

        var plot = { dims: dims || [], rows: rows || [], label: label || '' };
        var key  = JSON.stringify( plot );

        if ( this.plot && this.plotKey === key ) {

            return false;
        }

        this.plot          = plot;
        this.plotKey       = key;
        this.rowsResultSet = null;
        this.rowsX         = null;

        if ( ! plot.rows.length ) {

            this.redraw();

            return true;
        }

        this.fetchRows();

        return true;
    },

    /** The trend's own query again, by the x axis and the grid's dimensions, for the plotted rows only. */
    fetchRows : function () {

        if ( ! this.plot || ! this.plot.rows.length || ! this.explorer || ! this.explorer.resultSet ) {

            return;
        }

        var x    = this.xDimension || 'date';
        var dims = this.plot.dims;
        var url  = new OWA.uri( this.explorer.resultSet.self );
        var restrict = {};

        dims.forEach( function ( d ) { restrict[ d ] = []; } );

        this.plot.rows.forEach( function ( row ) {

            dims.forEach( function ( d ) {

                if ( restrict[ d ].indexOf( row.values[ d ] ) === -1 ) {

                    restrict[ d ].push( row.values[ d ] );
                }
            } );
        } );

        url.setQueryParam( 'dimensions', [ x ].concat( dims ).join( ',' ) );
        url.setQueryParam( 'rowValues', JSON.stringify( restrict ) );
        url.setQueryParam( 'resultsPerPage', 1000 );
        url.removeQueryParam( 'page' );
        url.removeQueryParam( 'sort' );

        var source = url.getSource();

        if ( this.rowsUrl === source ) {

            return;   // already asked
        }

        this.rowsUrl = source;

        var that = this;

        jQuery.getJSON( source, '', function ( data ) {

            if ( that.rowsUrl !== source ) {

                return;   // a later question has been asked since
            }

            that.rowsResultSet = new OWA.resultSet( data && data.hasOwnProperty( 'data' ) ? data.data : data );
            that.rowsX         = x;

            that.redraw();
        } );
    },

    /** Draw again from the trend's result set, keeping the metric and axis. */
    redraw : function () {

        if ( this.explorer && this.explorer.resultSet ) {

            this.generate( this.explorer.resultSet, this.options.series, this.dom_id );
        }
    },

    /** Whether a comparison waits for its line to be hovered: when the grid's rows are drawn. */
    compareOnHover : function () {

        return ( this.dataseries || [] ).some( function ( s ) { return s.rowKey !== undefined; } );
    },

    /**
     * One metric, broken out by the values of one dimension.
     *
     * The rows arrive as (x, dimension value) pairs, so they are pivoted here:
     * a line per value, and the total across ALL of them as the area behind.
     *
     * The total is the sum of every row, not of the lines that get drawn. Only
     * the largest few values are drawn -- a breakdown by page path would
     * otherwise be a hundred lines and no chart -- and a total that quietly
     * left the rest out would be a different number from the one the report's
     * metric boxes show.
     *
     * @return {series, points, x_type}
     */
    seriesByDimension : function ( resultSet, x_name, y_name, breakdown ) {

        var x_type  = '';
        var xs      = [];      // every x value, in the order first seen
        var seen_x  = {};
        var totals  = {};      // x -> the sum across every dimension value
        var byValue = {};      // dimension value -> { label, points: {x: y} , total }

        for ( var i = 0; i < resultSet.resultsRows.length; i++ ) {

            var row = resultSet.resultsRows[i];

            if ( ! row[ x_name ] || ! row[ y_name ] || ! row[ breakdown ] ) {

                continue;
            }

            x_type = row[ x_name ].data_type;

            var x = this.formatValue( x_type, row[ x_name ].value );
            // Through formatValue, so currency is in major units -- see
            // singleSeries().
            var y = this.formatValue( row[ y_name ].data_type, row[ y_name ].value ) * 1;

            if ( ! seen_x[ x ] ) {

                seen_x[ x ] = true;
                xs.push( x );
            }

            totals[ x ] = ( totals[ x ] || 0 ) + y;

            var cell = row[ breakdown ];

            var key = ( cell.value === null || cell.value === undefined ) ? '' : String( cell.value );

            if ( ! byValue[ key ] ) {

                byValue[ key ] = {
                    label: ( cell.formatted_value === null || cell.formatted_value === undefined
                        || cell.formatted_value === '' ) ? key : cell.formatted_value,
                    points: {},
                    total: 0
                };
            }

            byValue[ key ].points[ x ] = ( byValue[ key ].points[ x ] || 0 ) + y;
            byValue[ key ].total += y;
        }

        // Days nobody visited, too, at zero.
        ( this.periodXs( resultSet, x_name, x_type ) || [] ).forEach( function ( x ) {

            if ( ! seen_x[ x ] ) {
                seen_x[ x ] = true;
                xs.push( x );
                totals[ x ] = 0;
            }
        } );

        xs.sort( function ( a, b ) { return a - b; } );

        // The biggest first, by the metric being charted, so the cap keeps
        // what matters rather than whatever the sort happened to return first.
        var ranked = Object.keys( byValue ).sort( function ( a, b ) {

            return byValue[b].total - byValue[a].total;
        } );

        var values = ranked.slice( 0, this.options.maxSeries );

        var built = [];

        // The total, first and filled -- it is the shape of the whole thing,
        // and the lines are what it is made of.
        built.push( this.areaSeries( OWA.l( 'Total' ), xs.map( function ( x ) {

            return [ x, totals[ x ] ];

        } ) ) );

        for ( var v = 0; v < values.length; v++ ) {

            var entry = byValue[ values[v] ];

            built.push( {
                label: entry.label,
                dimensionValue: values[v],

                /*
                 * A missing x is a ZERO, not a gap.
                 *
                 * A dimension value with no rows on a given day had none of
                 * that metric that day. Leaving the point out would make flot
                 * join the days either side of it, drawing a line straight over
                 * an absence and reading as "steady" where the answer is
                 * "nothing".
                 */
                data: xs.map( function ( x ) {

                    return [ x, entry.points[ x ] || 0 ];
                } ),

                lines: { fill: false }
            } );
        }

        return { series: built, points: xs.length, x_type: x_type };
    },

    /**
     * The y axis, labelled in the units of the metric being charted.
     *
     * A bounce rate is stored 0 to 1 and a revenue figure in minor units, so an
     * axis of bare numbers was labelling a rate "0, 0, 0, 1" and money in a
     * currency nobody named. The metric already says what it is -- its
     * data_type is what the server formats its values with -- so the axis is
     * read from the same answer the numbers are.
     *
     * tickDecimals was hard-coded to 0, which is right for counts and is what
     * flattened a rate's axis to zeroes. flot decides it now, and each
     * formatter is told what it decided.
     *
     * @param object resultSet
     * @param string metric the metric being plotted
     */
    yAxis : function ( resultSet, metric, dataseries ) {

        var aggregate = ( resultSet.aggregates || {} )[ metric ] || {};
        var type      = aggregate.data_type || '';
        var that      = this;

        /*
         * The axis starts at zero unless something plotted is below it.
         *
         * flot scales to the data, which is right until the data is flat: a
         * bounce rate of zero all month gave an axis running -100% to 100%,
         * because a series with no range has no range to scale to. Nothing OWA
         * measures is negative in the ordinary case, and a count or a duration
         * cannot be -- but revenue can, once a refund is recorded, so this is
         * checked rather than assumed.
         */
        var floor = ( dataseries || [] ).some( function ( s ) {

            return s.data.some( function ( point ) { return point[1] < 0; } );

        } ) ? null : 0;

        switch ( type ) {

            case 'percentage':

                return {
                    min: floor,
                    /*
                     * A share of something is at most all of it. flot pads the
                     * axis past the data, so a rate peaking at 100% was drawn
                     * against a 125% tick; stop at 100% unless a value exceeds it.
                     */
                    max: ( dataseries || [] ).some( function ( s ) {

                        return s.data.some( function ( point ) { return point[1] > 1; } );

                    } ) ? null : 1,
                    /*
                     * The value is a FRACTION -- the server formats it by
                     * multiplying by a hundred -- so the label needs two fewer
                     * decimals than the axis was scaled to. Without that, an
                     * axis stepping by 0.05 labels every tick "5%".
                     */
                    tickFormatter: function ( value, axis ) {

                        return OWA.util.formatPercent( value, Math.max( 0, axis.tickDecimals - 2 ) );
                    }
                };

            case 'currency':

                /*
                 * The SYMBOL comes from the server's own formatting of this
                 * metric, not from a guess here. Currency is a per-install
                 * setting -- locale and ISO code -- and the browser has neither;
                 * taking the non-numeric parts off a value the server already
                 * formatted gets the right symbol on the right side without
                 * reimplementing any of that.
                 */
                var money = this.affixesOf( aggregate.formatted_value );

                return {
                    min: floor,
                    tickDecimals: 2,
                    tickFormatter: function ( value, axis ) {

                        return money.prefix
                            + that.groupDigits( value, axis.tickDecimals )
                            + money.suffix;
                    }
                };

            case 'timestamp':

                // Seconds. An axis of "630" is a number; "10:30" is a duration.
                return {
                    min: floor,
                    tickDecimals: 0,
                    tickFormatter: function ( value ) {

                        return that.formatDuration( value );
                    }
                };

            case 'milliseconds':

                // Engagement time is recorded in milliseconds; labelled as the
                // same duration the metric box shows, not as a count.
                return {
                    min: floor,
                    tickDecimals: 0,
                    tickFormatter: function ( value ) {

                        return that.formatDuration( value / 1000 );
                    }
                };

            default:

                // Counts. Thousands separated, the way the server writes them.
                return {
                    min: floor,
                    tickDecimals: 0,
                    tickFormatter: function ( value, axis ) {

                        return that.groupDigits( value, axis.tickDecimals );
                    }
                };
        }
    },

    /**
     * What sits either side of the number in an already-formatted value.
     *
     * "$1,234.56" gives a prefix; "1.234,56 kr" gives a suffix. Which one a
     * currency uses is a property of the locale, and this reads it off the
     * answer rather than deciding it.
     */
    affixesOf : function ( formatted ) {

        var text = ( formatted === null || formatted === undefined ) ? '' : String( formatted );

        var match = text.match( /^([^0-9-]*)[0-9., -]*([^0-9]*)$/ );

        return match
            ? { prefix: match[1] || '', suffix: match[2] || '' }
            : { prefix: '', suffix: '' };
    },

    /** 1234567.5, 1 -> "1,234,567.5": digits grouped in the install's locale, to a fixed number of decimals. */
    groupDigits : function ( value, decimals ) {

        var d = decimals || 0;

        return OWA.util.formatNumber( Number( value ), { minimumFractionDigits: d, maximumFractionDigits: d, useGrouping: true } );
    },

    /** Seconds as a duration: 90 -> "1:30", 3900 -> "1:05:00". */
    formatDuration : function ( seconds ) {

        seconds = Math.max( 0, Math.round( seconds ) );

        var h = Math.floor( seconds / 3600 );
        var m = Math.floor( ( seconds % 3600 ) / 60 );
        var s = seconds % 60;

        var pad = function ( n ) { return n < 10 ? '0' + n : String( n ); };

        return h ? h + ':' + pad( m ) + ':' + pad( s ) : m + ':' + pad( s );
    },

    /** The filled series -- the one that makes this an AREA chart. */
    areaSeries : function ( label, data ) {

        return {
            label: label,
            isTotal: true,
            data: data,
            lines: { fill: this.options.fill !== false, fillColor: this.options.fillColor },

            // A line needs two points. A period of one day -- Today -- drew
            // nothing at all, so its one value is a dot.
            points: data.length === 1 ? { show: true, fill: true, radius: 4 } : undefined
        };
    },

    /**
     * An x axis of hours: time mode, labelled by hourLabel(). The ticks are
     * placed in draw(), where the width is known.
     */
    hourAxis : function ( xaxis ) {

        var that = this;

        xaxis.mode        = 'time';
        xaxis.minTickSize = [ 1, 'hour' ];

        xaxis.tickFormatter = function ( value, axis ) {

            return that.hourLabel( value, ( axis.max - axis.min ) >= 86400000 );
        };
    },

    /** How many days the result set's period covers, or null when it has none. */
    periodDays : function ( resultSet ) {

        var period = resultSet && resultSet.timePeriod;

        if ( ! period || ! period.startDate || ! period.endDate ) {

            return null;
        }

        var day = function ( yyyymmdd ) {

            var t = String( yyyymmdd );

            return Date.UTC( t.substring( 0, 4 ) * 1, ( t.substring( 4, 6 ) * 1 ) - 1, t.substring( 6, 8 ) * 1 );
        };

        return Math.round( ( day( period.endDate ) - day( period.startDate ) ) / 86400000 ) + 1;
    },

    /**
     * Every interval of the period, in order, as x values -- or null when the
     * period cannot be read or is not what the chart is drawn over.
     *
     * The cube writes no row for an interval with nothing in it, and the
     * chart draws that interval at zero, a ratio's as well as a count's.
     * Left out, flot joined the intervals either side of it, a line drawn
     * straight over the absence; left empty, a ratio's line broke there.
     */
    periodXs : function ( resultSet, x_name, x_type ) {

        var period = this.zeroFilled( resultSet, x_name );

        if ( ! period || period.x_type !== x_type ) {
            return null;
        }

        return period.points.map( function ( p ) { return p[0]; } );
    },

    /**
     * Chart a different metric.
     *
     * NO REFETCH. The widget already queried every metric it draws -- the boxes
     * under the chart are those same metrics -- so which one is plotted is a
     * choice about what to draw from data already here. Going back to the
     * server would be asking for something it has.
     *
     * The breakdown survives: a trend of visits by medium becomes a trend of
     * page views by medium, which is the same question about a different
     * measure.
     *
     * @return bool whether anything changed
     */
    changeMetric : function ( name ) {

        if ( ! name || ! this.options.series[0] || name === this.options.series[0].y ) {

            return false;
        }

        if ( ! this.explorer || ! this.explorer.resultSet ) {

            return false;
        }

        /*
         * ...and it has to be a metric this result set actually carries.
         *
         * NO REFETCH means exactly that: the metric has to be here already.
         * Asked for one that is not, singleSeries() reads `.data_type` off an
         * undefined cell and throws out of the redraw -- the chart is left
         * blank and the only trace is the console.
         *
         * Not reachable from the boxes, which offer only what was queried. It
         * is reachable from anything else holding a metric name: a caller that
         * remembered a choice from a widget that has since been edited, or a
         * test.
         */
        var rows = this.explorer.resultSet.resultsRows;

        if ( ! rows || ! rows.length || ! rows[0][ name ] ) {

            return false;
        }

        this.options.series[0].y = name;

        this.generate( this.explorer.resultSet, this.options.series, this.dom_id );

        return true;
    },

    /** The metric currently drawn, or '' before anything has been. */
    chartedMetric : function () {

        return ( this.options.series[0] && this.options.series[0].y ) || '';
    },

    /**
     * The day/month control, top right of the chart.
     *
     * A trend is a shape over time, and which time -- days or months -- is a
     * question about how you want to read it rather than about what the report
     * is. So it is a reader's control, not something baked into the definition:
     * the stored query says `date`, and this rewrites it on the way out.
     *
     * Drawn only when the chart knows who to ask for new data. The explorer
     * owns fetching; a select that could not refetch would be a control that
     * does nothing, which is worse than no control.
     */
    /**
     * What the chart draws, over it: the metric, by the grid's dimensions
     * when it draws the grid's rows, over time.
     */
    chartTitle : function () {

        var rs     = this.explorer && this.explorer.resultSet;
        var metric = this.chartedMetric();

        if ( ! metric || ! rs || ! rs.aggregates || ! rs.aggregates[ metric ] ) {

            return '';
        }

        var label = rs.getMetricLabel ? rs.getMetricLabel( metric ) : metric;

        if ( this.plot && this.plot.rows.length && this.plot.label ) {

            return OWA.util.sprintf( OWA.l( '%s by %s over time' ), label, this.plot.label );
        }

        return OWA.util.sprintf( OWA.l( '%s over time' ), label );
    },

    drawTitle : function ( dom_id ) {

        var $title = jQuery( '#' + ( dom_id || this.dom_id ) + ' .owa_chartTitle' ).first();

        $title.text( this.options.showTitle ? this.chartTitle() : '' );
    },

    drawGranularityControl : function ( dom_id ) {

        var that = this;
        var $box = jQuery( '#' + dom_id + ' .owa_chartControls' ).first();

        $box.empty();

        if ( ! this.explorer || ! this.explorer.resultSet ) {

            return;
        }

        var current = this.xDimension;

        var known = this.options.granularities.filter( function ( g ) {

            return g.dimension === current;
        } );

        if ( ! known.length ) {

            // The chart is over something this control does not know how to
            // switch between. Leaving it off is the honest answer.
            return;
        }

        var days = this.periodDays( this.explorer.resultSet );

        var $select = jQuery( '<select class="owa_chartGranularity">' )
            .attr( 'title', OWA.l( 'Show this trend by hour, day or month' ) );

        this.options.granularities.forEach( function ( g ) {

            // Hours over a long period are more points than a chart can show.
            if ( g.maxDays && days !== null && days > g.maxDays && g.dimension !== current ) {

                return;
            }

            $select.append( jQuery( '<option>' ).attr( 'value', g.dimension )
                .prop( 'selected', g.dimension === current ).text( that.granularityLabel( g ) ) );
        } );

        $select.on( 'change', function () {

            that.changeGranularity( jQuery( this ).val() );
        } );

        $box.append( $select );
    },

    /** A granularity's name in the select: its label, or the name of its dimension's grain. */
    granularityLabel : function ( g ) {

        switch ( g.dimension ) {

            case 'dateHour':
                return OWA.l( 'Hour', 'trend granularity' );

            case 'date':
                return OWA.l( 'Day', 'trend granularity' );

            case 'yearMonth':
                return OWA.l( 'Month', 'trend granularity' );
        }

        return g.label || g.dimension;
    },

    /**
     * Ask for the same trend at a different grain.
     *
     * The x dimension is swapped in the result-set URL and the whole thing is
     * refetched -- the grouping happens in SQL, so there is nothing to
     * recompute here and nothing that could be recomputed correctly: a month's
     * value is not the sum of the days that were returned, it is the sum of the
     * days that exist.
     *
     * The sort travels with it. It orders by the x dimension, and a sort naming
     * a dimension the query no longer has is a sort the server cannot resolve.
     */
    changeGranularity : function ( dimension ) {

        if ( ! dimension || dimension === this.xDimension || ! this.explorer ) {

            return;
        }

        var url  = new OWA.uri( this.explorer.resultSet.self );
        var key  = 'dimensions';
        var dims = OWA.util.urldecode( url.getQueryParam( key ) || '' ).split( ',' );

        var was = this.xDimension;

        var swapped = dims.map( function ( name ) {

            return name.trim() === was ? dimension : name.trim();

        } ).filter( Boolean );

        url.setQueryParam( key, swapped.join( ',' ) );
        url.setQueryParam( 'sort', dimension );

        // A different grain is a different number of points, so the page the
        // reader was on no longer means anything.
        url.removeQueryParam( 'page' );

        this.xDimension = dimension;

        // The series spec is what generate() reads on the way back in, and the
        // refetch arrives as a new_result_set event carrying no spec of its own.
        if ( this.options.series[0] ) {

            this.options.series[0].x = dimension;
        }

        this.explorer.getNewResultSet( url.getSource() );
    },

    /**
     * Break the same trend out by a different dimension.
     *
     * The sibling of changeGranularity: that one swaps the X dimension, this
     * swaps the SERIES one -- the second entry in the query's dimension list,
     * which is what turns one filled area into a line per value.
     *
     * A REFETCH, not a redraw, and for the same reason changeGranularity is
     * one: the grouping happens in SQL. Rows grouped by (date, medium) cannot
     * be regrouped by browser in the browser -- the numbers for a value that
     * was never asked for do not exist in what came back.
     *
     * That is the difference from changeMetric, which redraws without asking:
     * every metric the boxes show was already queried, so which one is plotted
     * is a choice about data that is already here.
     *
     * An empty name removes the breakdown and leaves the filled total, which
     * is what a trend is with no dimension to break out by.
     *
     * @param string dimension the dimension to break out by, or '' for none
     * @return bool whether anything changed
     */
    changeBreakdown : function ( dimension ) {

        if ( ! this.explorer || ! this.explorer.resultSet ) {

            return false;
        }

        dimension = dimension || '';

        var spec = this.options.series[0];

        if ( ! spec ) {

            return false;
        }

        if ( ( spec.series || '' ) === dimension ) {

            return false;
        }

        var url  = new OWA.uri( this.explorer.resultSet.self );
        var key  = 'dimensions';

        var dims = OWA.util.urldecode( url.getQueryParam( key ) || '' )
            .split( ',' )
            .map( function ( n ) { return n.trim(); } )
            .filter( Boolean );

        /*
         * The x dimension is whatever the chart is CURRENTLY over, not the
         * first name in the URL. A reader who switched to months and then
         * changed the breakdown would otherwise be put back onto days.
         */
        var x = this.xDimension || dims[0] || 'date';

        url.setQueryParam( key, dimension ? x + ',' + dimension : x );

        // A different breakdown is a different number of rows, so the page the
        // reader was on no longer means anything.
        url.removeQueryParam( 'page' );

        /*
         * A broken-out trend is one row per (x, value) pair, and the chart sums
         * those rows for its total and ranks them to pick its lines. Both are
         * wrong if the result set was paginated -- the same bound the renderer
         * puts on the first query, restated here because this rewrites it.
         */
        if ( dimension ) {

            url.setQueryParam( 'resultsPerPage', 1000 );
        }

        // What generate() reads on the way back in: the refetch arrives as a
        // new_result_set event carrying no spec of its own.
        spec.series = dimension || null;

        this.explorer.getNewResultSet( url.getSource() );

        return true;
    },

    /**
     * Redraw at the widget's width whenever the layout changes it.
     *
     * A chart has no irreducible width the way a table does -- it should simply
     * be as wide as the room it is given -- so this never scrolls and never
     * refetches. Everything it needs is already here: draw() re-plots
     * this.dataseries, and flot sizes its canvas from the placeholder, so
     * plotting again at a new width IS the resize.
     *
     * Bound to the widget CONTAINER, not to the plot element. setupAreaChart()
     * replaces the plot element on every redraw, which is exactly what made
     * flot's own resize plugin stop working -- see OWA.onWidthChange.
     *
     * Rebound rather than accumulated: generate() runs again on every metric
     * change, granularity change and refetch, and a second observer would mean
     * two full re-plots per resize.
     */
    watchWidth : function ( dom_id ) {

        if ( this.unwatchWidth ) {

            this.unwatchWidth();
        }

        var that = this;

        this.unwatchWidth = OWA.onWidthChange( dom_id, function () {

            if ( that.dataseries && that.dataseries.length ) {

                that.draw();
            }
        } );
    },

    /**
     * Plot what is in this.dataseries, dimming everything but the selection.
     *
     * Redrawn rather than restyled: flot owns the canvas, and there is no
     * per-series opacity to change after the fact. Recolouring the series and
     * plotting again is how a flot chart changes its appearance.
     */
    draw : function () {

        var that   = this;
        var hover  = this.compareOnHover();
        var active = this.selected !== null ? this.selected : this.hovered;

        var plotted = [];
        var scaleOnly = [];

        this.dataseries.forEach( function ( s, i ) {

            var own = s.isCompare ? ( s.compareOf || 0 ) : i;

            /*
             * With the grid's rows drawn, a comparison shows with its line.
             * Hidden, it is still handed to flot, undrawn, so the y axis is
             * scaled to every series and stays put when one comes and goes.
             */
            if ( s.isCompare && hover && own !== active ) {

                scaleOnly.push( { data: s.data, lines: { show: false }, points: { show: false }, shadowSize: 0 } );

                return;
            }

            var copy = jQuery.extend( {}, s, { index: i } );

            if ( that.selected !== null && that.selected !== own ) {

                copy.color = that.fade( s.color, that.options.dimmedOpacity );
            }

            plotted.push( copy );
        } );

        // The width in the px the label widths are given in (rootScale()).
        var width = ( jQuery( this.domSelector ).width() || 0 ) / OWA.areaChart.rootScale();

        if ( this.xType === 'yyyymmddhh' && this.flotOptions.xaxis ) {

            var xs = ( this.dataseries[0] && this.dataseries[0].data || [] ).map( function ( p ) { return p[0]; } );

            if ( xs.length ) {

                this.flotOptions.xaxis.ticks = this.hourTicks(
                    xs[0], xs[ xs.length - 1 ], width || 600 );
            }

        } else if ( this.flotOptions.xaxis && this.flotOptions.xaxis.mode === 'time' ) {

            // generate()'s count is the most; a narrower plot gets fewer.
            var axis = this.flotOptions.xaxis;

            axis.owaMostTicks = axis.owaMostTicks || axis.ticks;
            axis.ticks = OWA.areaChart.fitCount( width, this.options.dateTickWidth, axis.owaMostTicks );
        }

        if ( this.flotOptions.legend ) {

            var entries = this.dataseries.filter( function ( s ) { return ! s.isCompare; } ).length;

            this.flotOptions.legend.noColumns = OWA.areaChart.fitCount( width, this.options.legendEntryWidth, entries );
        }

        jQuery.plot( jQuery( this.domSelector ), plotted.concat( scaleOnly ), this.flotOptions );

        // What was actually handed to flot, so the drawn state is readable and
        // not only visible. The colours here ARE the dimming.
        this.plotted = plotted;

        this.markLegend();

        // The plot's canvases say nothing to a screen reader; the summary does.
        jQuery( this.domSelector ).attr( { role: 'img', 'aria-label': this.chartSummary() } );

        this.drawCompareKey( plotted );
    },

    /**
     * flot's drawSeries hook: a series marked dashed is stroked in dashes.
     *
     * flot 0.8 has no dash option. The hook runs just before each series is
     * drawn, on the same context, so the dash is set for a dashed series and
     * cleared for every other one.
     */
    dashLine : function ( plot, ctx, series ) {

        if ( typeof ctx.setLineDash !== 'function' ) {

            return;
        }

        ctx.setLineDash( series.dashed ? [ 5, 4 ] : [] );
    },

    /**
     * A compared chart's tooltip: the day against the day it is compared to,
     * then every line's value there, its value on the compared day and the
     * change. Null when nothing is compared.
     */
    comparedTooltip : function ( x ) {

        var compares = ( this.dataseries || [] ).filter( function ( s ) { return s.isCompare; } );

        return compares.length ? this.pointTooltip( x ) : null;
    },

    /**
     * The tooltip at x: the day, then every line's value there. Compared, the
     * day it is compared to, and each line's value then and its change.
     */
    pointTooltip : function ( x ) {

        var series   = this.dataseries || [];
        var compares = series.filter( function ( s ) { return s.isCompare; } );
        var compared = compares.length > 0;

        var that = this;
        var esc  = OWA.util.escapeHtml;

        var at = function ( s ) {

            for ( var i = 0; i < s.data.length; i++ ) {

                if ( s.data[i][0] === x ) {

                    return s.data[i][1];
                }
            }

            return null;
        };

        var day = function ( t ) {

            return that.xType === 'yyyymmdd' || that.xType === 'yyyymm' || that.xType === 'yyyymmddhh'
                ? that.timestampFormatter( t ) : String( t );
        };

        var info = ( this.rowsResultSet && this.rowsResultSet.compare )
            || ( this.explorer && this.explorer.resultSet && this.explorer.resultSet.compare ) || null;

        var real = this.comparedX( x, info );

        compares.forEach( function ( c ) {

            if ( real === null && c.compareDates && c.compareDates[ x ] ) {

                real = that.formatValue( that.xType, String( c.compareDates[ x ] ) );
            }
        } );

        var head = compared && real !== null
            ? OWA.util.sprintf( OWA.l( '%1$s vs %2$s', 'a date and the date it is compared with' ), day( x ), day( real ) )
            : day( x );

        var html = '<div class="owa_chartTipHead">' + esc( head ) + '</div><table class="owa_chartTip">';

        series.forEach( function ( s, i ) {

            if ( s.isCompare ) {

                return;
            }

            var v = at( s );
            var c = compares.filter( function ( cs ) { return ( cs.compareOf || 0 ) === i; } )[0];
            var w = c ? at( c ) : null;
            var change = '';

            if ( c && info ) {

                var ch = v !== null && w !== null && w !== 0 ? ( v - w ) / w : null;
                var d  = describeChange( { change: ch, compare_formatted_value: w }, info );

                change = '<span class="owa_gridChange ' + d.cls + '">' + esc( d.text ) + '</span>';
            }

            html += '<tr><th><i style="background:' + esc( String( s.color ) ) + '"></i><span>' + esc( s.label || s.tooltipLabel || '' ) + '</span></th>'
                + '<td>' + esc( v === null ? '\u2014' : that.tipNumber( v ) ) + '</td>'
                + ( compared
                    ? '<td class="owa_chartTipWas">' + ( c ? esc( w === null ? '\u2014' : that.tipNumber( w ) ) : '' ) + '</td>'
                        + '<td>' + change + '</td>'
                    : '' )
                + '</tr>';
        } );

        return html + '</table>';
    },

    /** The first line that is not a comparison: the total. */
    firstLine : function () {

        var series = this.dataseries || [];

        for ( var i = 0; i < series.length; i++ ) {

            if ( ! series[i].isCompare ) {

                return i;
            }
        }

        return 0;
    },

    /** The x of the point nearest to x on the chart's axis, or null. */
    nearestX : function ( x ) {

        var data = ( this.dataseries && this.dataseries[ this.firstLine() ] || {} ).data || [];
        var best = null;

        data.forEach( function ( p ) {

            if ( best === null || Math.abs( p[0] - x ) < Math.abs( best - x ) ) {

                best = p[0];
            }
        } );

        return best;
    },

    /**
     * The pointer moved over the chart.
     *
     * Anywhere in the plot, the point nearest the pointer's x is the active
     * one: on the line the pointer is on, else on the line clicked (selected),
     * else on the total. A vertical line marks it, and the tooltip follows the
     * pointer with every line's value there.
     */
    onHover : function ( pos, item ) {

        var plot = jQuery( this.domSelector ).data( 'plot' );

        if ( ! plot || ! this.dataseries || ! this.dataseries.length ) {

            return;
        }

        var o = plot.offset();

        // The plot, and a little either side so its first and last points can be reached.
        if ( pos.pageX < o.left - 10 || pos.pageX > o.left + plot.width() + 10
            || pos.pageY < o.top || pos.pageY > o.top + plot.height() ) {

            this.endHover();

            return;
        }

        var own, x;

        if ( item ) {

            own = item.series.isCompare ? ( item.series.compareOf || 0 ) : this.hoveredLine( item );
            x   = item.datapoint[0];

        } else {

            own = this.selected !== null && this.selected !== undefined ? this.selected : this.firstLine();
            x   = this.nearestX( pos.x );
        }

        if ( x === null ) {

            return;
        }

        if ( own !== this.hovered ) {

            this.hovered = own;

            // With the grid's rows drawn, a line's comparison shows with it.
            if ( this.compareOnHover() ) {

                this.draw();
                plot = jQuery( this.domSelector ).data( 'plot' );
            }
        }

        this.activeX = x;
        this.highlightAt( plot, own, x );
        this.tipAt( this.pointTooltip( x ), pos.pageX, pos.pageY );
    },

    /** Mark line own's point at x, and redraw the vertical line. */
    highlightAt : function ( plot, own, x ) {

        plot.unhighlight();

        plot.getData().forEach( function ( s, si ) {

            if ( s.index !== own || s.isCompare ) {

                return;
            }

            for ( var j = 0; j < s.data.length; j++ ) {

                if ( s.data[j][0] === x && s.data[j][1] !== null ) {

                    plot.highlight( si, j );
                }
            }
        } );

        plot.triggerRedrawOverlay();
    },

    /** The tooltip, at the pointer: its contents change only when the point does. */
    tipAt : function ( html, pageX, pageY ) {

        var $tip = jQuery( '#tooltip' );

        if ( ! $tip.length ) {

            this.showTooltip( pageX, pageY, html, { x: pageX, y: pageY } );

            return;
        }

        if ( $tip.html() !== html ) {

            $tip.html( html );
        }

        $tip.css( OWA.areaChart.besidePointer( { x: pageX, y: pageY }, $tip.outerWidth(), $tip.outerHeight(), {
            left: window.pageXOffset,
            top: window.pageYOffset,
            width: document.documentElement.clientWidth,
            height: document.documentElement.clientHeight
        } ) );
    },

    /** The pointer left the plot: no tooltip, no active point. */
    endHover : function () {

        jQuery( '#tooltip' ).remove();

        var plot = jQuery( this.domSelector ).data( 'plot' );

        if ( this.activeX !== null && this.activeX !== undefined && plot ) {

            this.activeX = null;
            plot.unhighlight();
            plot.triggerRedrawOverlay();
        }

        if ( this.hovered !== null && this.hovered !== undefined ) {

            this.hovered = null;

            if ( this.compareOnHover() ) {

                this.draw();
            }
        }
    },

    /**
     * A point clicked: its line is the one followed, as a legend click
     * selects it. Clicking it again puts the total back.
     */
    onPointClick : function ( item ) {

        var lines = ( this.dataseries || [] ).filter( function ( s ) { return ! s.isCompare; } );

        if ( ! item || lines.length < 2 ) {

            return;
        }

        var own = item.series.isCompare ? ( item.series.compareOf || 0 ) : this.hoveredLine( item );

        this.selected = this.selected === own ? null : own;
        this.hovered  = own;
        this.draw();

        var plot = jQuery( this.domSelector ).data( 'plot' );

        if ( plot && this.activeX !== null && this.activeX !== undefined ) {

            this.highlightAt( plot, own, this.activeX );
        }
    },

    /** flot's drawOverlay hook, bound to this chart. */
    crosshairHook : function () {

        var that = this;

        return function ( plot, ctx ) { that.drawCrosshair( plot, ctx ); };
    },

    /** The vertical line at the active x, the height of the plot. */
    drawCrosshair : function ( plot, ctx ) {

        if ( this.activeX === null || this.activeX === undefined ) {

            return;
        }

        var o  = plot.getPlotOffset();
        var cx = o.left + plot.getAxes().xaxis.p2c( this.activeX );

        if ( cx < o.left - 1 || cx > o.left + plot.width() + 1 ) {

            return;
        }

        cx = Math.round( cx ) + 0.5;

        ctx.save();
        ctx.strokeStyle = 'rgba(55, 65, 81, 0.45)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo( cx, o.top );
        ctx.lineTo( cx, o.top + plot.height() );
        ctx.stroke();
        ctx.restore();
    },

    /**
     * The line a hovered point belongs to. Where lines meet -- every line at
     * zero on a quiet day -- flot hands over the last of them; the first is
     * the one meant, the total, whose comparison is the one worth showing.
     */
    hoveredLine : function ( item ) {

        var x = item.datapoint[0], y = item.datapoint[1];
        var series = this.dataseries || [];

        for ( var i = 0; i < item.series.index; i++ ) {

            if ( series[i].isCompare ) {

                continue;
            }

            for ( var j = 0; j < series[i].data.length; j++ ) {

                if ( series[i].data[j][0] === x ) {

                    if ( series[i].data[j][1] === y ) {

                        return i;
                    }

                    break;
                }
            }
        }

        return item.series.index;
    },

    /**
     * The point on the comparison's axis that x is compared with: the same
     * distance from the comparison's start as x is from the period's. Null
     * when the dates to work it out from are not here.
     */
    comparedX : function ( x, info ) {

        var period = this.explorer && this.explorer.resultSet && this.explorer.resultSet.timePeriod;

        if ( ! info || ! info.startDate || ! period || ! period.startDate ) {

            return null;
        }

        var ymd = function ( t ) {

            t = String( t );

            return Date.UTC( t.substring( 0, 4 ) * 1, t.substring( 4, 6 ) * 1 - 1, t.substring( 6, 8 ) * 1 );
        };

        if ( this.xType === 'yyyymm' ) {

            var from = String( period.startDate ), to = String( info.startDate );
            var months = ( from.substring( 0, 4 ) * 12 + from.substring( 4, 6 ) * 1 ) - ( to.substring( 0, 4 ) * 12 + to.substring( 4, 6 ) * 1 );
            var d = new Date( x );

            return Date.UTC( d.getUTCFullYear(), d.getUTCMonth() - months, 1 );
        }

        if ( this.xType === 'yyyymmdd' || this.xType === 'yyyymmddhh' ) {

            return x - ( ymd( period.startDate ) - ymd( info.startDate ) );
        }

        return null;
    },

    /** A number for the tooltip: digits grouped, at most two decimals. */
    tipNumber : function ( v ) {

        return OWA.util.formatNumber( v, { maximumFractionDigits: 2 } );
    },

    /**
     * The period's dates and the comparison's, for the key, or null when the
     * result set does not say what its period was.
     */
    compareKeyLabels : function ( resultSet ) {

        var period  = resultSet.timePeriod;
        var compare = resultSet.compare;

        if ( ! period || ! period.startDate || ! period.endDate || ! compare.startDate || ! compare.endDate ) {

            return null;
        }

        // Named as the picker names them (OWA.periodNames); a custom range by its dates.
        var names = OWA.periodNames || { periods: {}, compares: {} };
        var entry = function ( name, start, end ) {

            var dates = formatRange( String( start ), String( end ) );

            return { label: name || dates, dates: dates };
        };

        return [
            entry( names.periods[ period.period ], period.startDate, period.endDate ),
            entry( names.compares[ compare.compare ], compare.startDate, compare.endDate )
        ];
    },

    /**
     * Under a compared trend: a solid line and the period's dates, a dashed line
     * and the comparison's.
     *
     * Not a legend of series -- the two lines are one metric over two periods,
     * so the key names the periods. Written after each draw because flot empties
     * the legend's container every time it plots.
     */
    drawCompareKey : function ( plotted ) {

        var cmp  = plotted.filter( function ( s ) { return s.isCompare; } )[0];
        var main = plotted.filter( function ( s ) { return ! s.isCompare; } )[0];

        if ( ! cmp || ! main || ! cmp.keyLabels || ( this.flotOptions.legend && this.flotOptions.legend.show ) ) {

            return;
        }

        var esc  = OWA.util.escapeHtml;
        var item = function ( cls, color, key ) {

            // A named period has its dates in its title.
            return '<span class="' + cls + '"' + ( key.label !== key.dates ? ' title="' + esc( key.dates ) + '"' : '' ) + '>'
                + '<i style="border-top-color:' + esc( String( color ) ) + '"></i>'
                + esc( key.label ) + '</span>';
        };

        jQuery( '#' + this.dom_id + ' > .owa_chartLegend' ).html(
            '<div class="owa_chartKey">'
            + item( 'owa_chartKeyPeriod', main.color, cmp.keyLabels[0] )
            + item( 'owa_chartKeyCompare', cmp.color, cmp.keyLabels[1] )
            + '</div>' );
    },

    /**
     * A colour at partial opacity.
     *
     * flot accepts any CSS colour for a series, so an rgba() string is the
     * whole mechanism -- there is nothing to set on the line itself.
     */
    fade : function ( color, alpha ) {

        var hex = String( color ).replace( '#', '' );

        if ( hex.length === 3 ) {

            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }

        if ( ! /^[0-9a-f]{6}$/i.test( hex ) ) {

            return color;   // already rgba(), or a name -- leave it alone
        }

        return 'rgba(' + parseInt( hex.substring( 0, 2 ), 16 )
            + ',' + parseInt( hex.substring( 2, 4 ), 16 )
            + ',' + parseInt( hex.substring( 4, 6 ), 16 )
            + ',' + alpha + ')';
    },

    /**
     * Clicking a legend entry brings that line forward; so does Enter or Space
     * on its label, which is a toggle button (markLegend()).
     *
     * Bound once per plot, on the container rather than on the entries, because
     * flot rebuilds the legend's contents on every draw -- and every draw is
     * what a click causes.
     */
    bindLegend : function () {

        var that = this;
        var $legend = jQuery( '#' + this.dom_id + ' > .owa_chartLegend' );

        $legend.addClass( 'owa_chartLegendInteractive' ).off( 'click.owaSeries keydown.owaSeries' );

        if ( ! this.legendIsInteractive() ) {

            // One line is always the selected one. Offering a control that can
            // only turn itself off is worse than offering none.
            $legend.removeClass( 'owa_chartLegendInteractive' );

            return;
        }

        $legend.on( 'click.owaSeries', '.legendLabel, .legendColorBox', function () {

            that.toggleSeries( parseInt( jQuery( this ).attr( 'data-series' ), 10 ) );
        } );

        $legend.on( 'keydown.owaSeries', '.legendLabel', function ( e ) {

            if ( e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar' ) {

                return;
            }

            e.preventDefault();

            var index = parseInt( jQuery( this ).attr( 'data-series' ), 10 );

            that.toggleSeries( index );

            // The draw rebuilt the legend: focus the new label for the same line.
            $legend.find( '.legendLabel[data-series="' + index + '"]' ).trigger( 'focus' );
        } );
    },

    /** More than one line to choose between: the legend's entries are controls. */
    legendIsInteractive : function () {

        return ( this.dataseries || [] ).length >= 2;
    },

    /**
     * Select a line, or put every line back when it is the selected one.
     *
     * The series index is stamped on the cell, not read from its row: the
     * legend reads left to right, as many entries a row as fit, so a row
     * holds several series.
     */
    toggleSeries : function ( index ) {

        if ( isNaN( index ) || index < 0 || index >= this.dataseries.length ) {

            return;
        }

        // Selecting the selected one again puts every line back, so there is
        // always a way out that does not need a second control.
        this.selected = ( this.selected === index ) ? null : index;

        this.draw();
    },

    /**
     * Number the legend entries, and say which one is selected.
     *
     * Run after every draw, because flot rebuilds the legend's contents each
     * time -- so the numbering has to be reapplied, not set once.
     *
     * The entries are cells rather than rows: the legend reads left to right,
     * as many entries a row as the width has room for, so a row holds
     * several. Each label and its colour swatch carry the index, which is what the
     * click handler reads and what these classes hang on. Where the entries
     * are controls, each label is a toggle button, pressed while its line is
     * the selected one; the swatch beside it is decoration.
     */
    markLegend : function () {

        var $legend     = jQuery( '#' + this.dom_id + ' > .owa_chartLegend' );
        var selected    = this.selected;
        var interactive = this.legendIsInteractive();

        $legend.find( '.legendLabel' ).each( function ( i ) {

            var $label = jQuery( this );
            var $swatch = $label.prevAll( '.legendColorBox' ).first();

            $label.add( $swatch )
                .attr( 'data-series', i )
                .toggleClass( 'owa_seriesSelected', selected === i )
                .toggleClass( 'owa_seriesDimmed', selected !== null && selected !== i );

            if ( interactive ) {

                $label.attr( { role: 'button', tabindex: '0', 'aria-pressed': selected === i ? 'true' : 'false' } );
                $swatch.attr( 'aria-hidden', 'true' );
            }
        } );
    },

    /**
     * What the chart shows, in words, for a reader who cannot see it: its
     * title, the first and last dates plotted, and each line's lowest and
     * highest value. The plot carries it as its accessible name.
     */
    chartSummary : function () {

        var that  = this;
        var lines = ( this.dataseries || [] ).filter( function ( s ) {
            return ! s.isCompare && s.data && s.data.length;
        } );

        if ( ! lines.length ) {

            return OWA.l( 'No data for this period' );
        }

        var dated = this.xType === 'yyyymmdd' || this.xType === 'yyyymm' || this.xType === 'yyyymmddhh';
        var day   = function ( t ) { return dated ? that.timestampFormatter( t ) : String( t ); };
        var xs    = lines[0].data;
        var out   = [ OWA.util.sprintf( OWA.l( '%1$s, %2$s to %3$s.', 'chart summary: title, first date, last date' ),
            this.chartTitle() || OWA.l( 'Trend' ), day( xs[0][0] ), day( xs[ xs.length - 1 ][0] ) ) ];

        lines.forEach( function ( s ) {

            var ys = s.data.map( function ( p ) { return p[1]; } ).filter( function ( v ) {
                return v !== null && isFinite( v );
            } );

            if ( ! ys.length ) {

                return;
            }

            var low  = that.tipNumber( Math.min.apply( null, ys ) );
            var high = that.tipNumber( Math.max.apply( null, ys ) );

            out.push( s.label
                ? OWA.util.sprintf( OWA.l( '%1$s: lowest %2$s, highest %3$s.' ), s.label, low, high )
                : OWA.util.sprintf( OWA.l( 'Lowest %1$s, highest %2$s.' ), low, high ) );
        } );

        return out.join( ' ' );
    },

    /**
     * Shows a tooltip for flot charts at x, y. Given the pointer, it goes
     * beside the pointer instead: right of it and below, or left of it and
     * above where that would run off the window.
     */
    showTooltip : function(x, y, contents, pointer) {

        var tip = jQuery('<div id="tooltip">' + contents + '</div>').css( {
            position: 'absolute',
            display: 'none',
            top: y + 5,
            left: x + 5,
            border: '1px solid #cccccc',
            padding: '2px',
            'background-color': '#ffffff',
            opacity: 0.90,
            // Over the report's side nav and sticky headers, which sit above the page.
            'z-index': 1000,
            // Never under the pointer: over the tooltip, the pointer has left the chart.
            'pointer-events': 'none'
        }).appendTo("body");

        if ( pointer ) {

            tip.css( OWA.areaChart.besidePointer( pointer, tip.outerWidth(), tip.outerHeight(), {
                left: window.pageXOffset,
                top: window.pageYOffset,
                width: document.documentElement.clientWidth,
                height: document.documentElement.clientHeight
            } ) );
        }

        tip.fadeIn(100);
    },

    
    setupAreaChart : function(series, dom_id) {
        
        dom_id = dom_id || this.dom_id;
        
        var that = this;
        
        //var w = this.getContainerWidth();
        var w = jQuery("#"+dom_id).css('width');
        //alert(w);
        var h = this.getContainerHeight() || this.getOption('height');
        //var h = this.getOption('height');
        
        /*
         * The plot, and a place under it for the legend.
         *
         * After the plot in the markup, so the legend lands below the x-axis
         * labels -- flot draws those inside the plot's own area, so anything
         * that follows the plot element is below them.
         */
        jQuery("#"+dom_id).html(
            '<div class="owa_chartHead"><div class="owa_chartTitle"></div><div class="owa_chartControls"></div></div>'
          + '<div class="owa_areaChart" data-ui="chart:' + OWA.util.escapeHtml( dom_id ) + '"></div>'
          + '<div class="owa_chartLegend"></div>' );

        jQuery(that.domSelector).css('width', this.getOption('width'));
        jQuery(that.domSelector).css('height', h);
        
        // The tooltip and the active point follow the pointer; see onHover.
        jQuery(that.domSelector).bind( 'mouseleave', function () { that.endHover(); } );
        jQuery(that.domSelector).bind( 'plothover', function ( event, pos, item ) { that.onHover( pos, item ); } );
        jQuery(that.domSelector).bind( 'plotclick', function ( event, pos, item ) { that.onPointClick( item ); } );
    }
}
