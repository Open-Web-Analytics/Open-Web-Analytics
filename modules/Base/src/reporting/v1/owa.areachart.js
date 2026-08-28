// OWA is defined by owa.js; this module augments it (OWA.areaChart = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

OWA.areaChart = function( options ) {

    // config options
    this.options = {
        
        series: [],
        height: 125,
        width:    '99%', // needed for flot resize plugin
        xaxis: {
            mode: 'time'
            
        },
        timeformat: "%m/%d",
        showGrid: true,
        showLegend: true,
        showDots: true,
        lineWidth: 4,
        autoResizeCharts: true,
        fillColor: "rgba(202,225,255, 0.6)",

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

        /* The x axis a reader can choose between, and what each queries. */
        granularities: [
            { dimension: 'date',  label: 'Day' },
            { dimension: 'month', label: 'Month' }
        ],

        monthFormat: "%b %Y"
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
                
            // 202608 -> the first of that month. The `month` COLUMN stores
            // yyyymm despite its name, which is why a month axis orders
            // correctly across a year boundary.
            case 'yyyymm':

                var m_year  = String( value ).substring( 0, 4 ) * 1;
                var m_month = ( String( value ).substring( 4, 6 ) * 1 ) - 1;

                value = Date.UTC( m_year, m_month, 1, 0, 0, 0, 0 );
                break;

            case 'currency':
                value = value/100;
        }
        
        return value;
    },
    
    timestampFormatter : function(timestamp) {
        
        var d = new Date(timestamp*1);
        var curr_date = d.getUTCDate();
        var curr_month = d.getUTCMonth() + 1;
        var curr_year = d.getUTCFullYear();
        //alert(d+' date: '+curr_month);
        var date =  curr_month + "/" + curr_date + "/" + curr_year;
        //var date =  curr_month + "/" + curr_date;
        return date;
    },
    
    /**
     * Main method for displaying an area chart
     */
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
                : this.singleSeries( resultSet, x_name, y_name );

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
                    tickDecimals: null
                },
                grid: {show: this.options.showGrid, hoverable: true, autoHilight:true, borderWidth:0, borderColor: null},
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
                    show: this.options.showLegend && dataseries.length > 1,

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
                    noColumns: dataseries.length
                }
            };

            if ( built.x_type === 'yyyymmdd' || built.x_type === 'yyyymm' ) {

                this.flotOptions.xaxis.mode = "time";

                // A month axis labelled with days would repeat the same day
                // number every tick.
                this.flotOptions.xaxis.timeformat = built.x_type === 'yyyymm'
                    ? this.options.monthFormat
                    : this.options.timeformat;
            }

            OWA.debug('Plotting area graph in ' + selector);

            /*
             * A colour each, fixed here rather than left to flot's rotation, so
             * the legend, the lines and the dimming all agree about which
             * colour belongs to which line. The total is always first.
             */
            for ( var c = 0; c < dataseries.length; c++ ) {

                dataseries[c].color = dataseries[c].isTotal
                    ? this.options.totalColor
                    : this.options.colors[ ( c - 1 ) % this.options.colors.length ];
            }

            this.dataseries = dataseries;
            this.selected   = null;

            this.draw();

            this.bindLegend();

            this.drawGranularityControl( dom_id );

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
                    xaxis: {
                        mode: 'time',
                        timeformat: zero.x_type === 'yyyymm'
                            ? this.options.monthFormat
                            : this.options.timeformat,
                        ticks: Math.min( 10, zero.points.length )
                    },
                    grid: { show: this.options.showGrid, hoverable: true,
                            borderWidth: 0, borderColor: null },
                    series: {
                        points: { show: false },
                        lines: { show: true, fill: true, lineWidth: this.options.lineWidth }
                    },
                    legend: { show: false }
                };

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
                             borderWidth: 0, borderColor: null },
                    series: { lines: { show: false }, points: { show: false } },
                    legend: { show: false }
                };

                jQuery( '#' + dom_id + ' > .owa_areaChart' ).append(
                    '<div class="owa_chartEmpty">No data for this period</div>' );
            }

            this.draw();

            this.drawGranularityControl( dom_id );

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
     * Days or months, matching whatever the chart is drawn over, so the
     * granularity control still means something on an empty report.
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

        if ( x_name === 'month' ) {

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

        return {
            series: [ this.areaSeries( resultSet.getMetricLabel( y_name ), data ) ],
            points: data.length,
            x_type: x_type
        };
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
        built.push( this.areaSeries( 'Total', xs.map( function ( x ) {

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
                     * The value is a FRACTION -- the server formats it by
                     * multiplying by a hundred -- so the label needs two fewer
                     * decimals than the axis was scaled to. Without that, an
                     * axis stepping by 0.05 labels every tick "5%".
                     */
                    tickFormatter: function ( value, axis ) {

                        return ( value * 100 ).toFixed( Math.max( 0, axis.tickDecimals - 2 ) ) + '%';
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
                            + that.groupDigits( value.toFixed( axis.tickDecimals ) )
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

            default:

                // Counts. Thousands separated, the way the server writes them.
                return {
                    min: floor,
                    tickDecimals: 0,
                    tickFormatter: function ( value, axis ) {

                        return that.groupDigits( value.toFixed( axis.tickDecimals ) );
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

    /** 1234567.5 -> "1,234,567.5", the way the server's number_format writes it. */
    groupDigits : function ( value ) {

        var parts = String( value ).split( '.' );

        parts[0] = parts[0].replace( /\B(?=(\d{3})+(?!\d))/g, ',' );

        return parts.join( '.' );
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
            lines: { fill: true, fillColor: this.options.fillColor }
        };
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
    drawGranularityControl : function ( dom_id ) {

        var that = this;
        var $box = jQuery( '#' + dom_id + ' > .owa_chartControls' );

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

        var $select = jQuery( '<select class="owa_chartGranularity">' )
            .attr( 'title', 'Show this trend by day or by month' );

        this.options.granularities.forEach( function ( g ) {

            $select.append( jQuery( '<option>' ).attr( 'value', g.dimension )
                .prop( 'selected', g.dimension === current ).text( g.label ) );
        } );

        $select.on( 'change', function () {

            that.changeGranularity( jQuery( this ).val() );
        } );

        $box.append( $select );
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
        var key  = OWA.util.appNs( 'dimensions' );
        var dims = OWA.util.urldecode( url.getQueryParam( key ) || '' ).split( ',' );

        var was = this.xDimension;

        var swapped = dims.map( function ( name ) {

            return name.trim() === was ? dimension : name.trim();

        } ).filter( Boolean );

        url.setQueryParam( key, swapped.join( ',' ) );
        url.setQueryParam( OWA.util.appNs( 'sort' ), dimension );

        // A different grain is a different number of points, so the page the
        // reader was on no longer means anything.
        url.removeQueryParam( OWA.util.appNs( 'page' ) );

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
        var key  = OWA.util.appNs( 'dimensions' );

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
        url.removeQueryParam( OWA.util.appNs( 'page' ) );

        /*
         * A broken-out trend is one row per (x, value) pair, and the chart sums
         * those rows for its total and ranks them to pick its lines. Both are
         * wrong if the result set was paginated -- the same bound the renderer
         * puts on the first query, restated here because this rewrites it.
         */
        if ( dimension ) {

            url.setQueryParam( OWA.util.appNs( 'resultsPerPage' ), 1000 );
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

        var that = this;

        var plotted = this.dataseries.map( function ( s, i ) {

            var copy = jQuery.extend( {}, s );

            if ( that.selected !== null && that.selected !== i ) {

                copy.color = that.fade( s.color, that.options.dimmedOpacity );
            }

            return copy;
        } );

        jQuery.plot( jQuery( this.domSelector ), plotted, this.flotOptions );

        // What was actually handed to flot, so the drawn state is readable and
        // not only visible. The colours here ARE the dimming.
        this.plotted = plotted;

        this.markLegend();
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
     * Clicking a legend entry brings that line forward.
     *
     * Bound once per plot, on the container rather than on the entries, because
     * flot rebuilds the legend's contents on every draw -- and every draw is
     * what a click causes.
     */
    bindLegend : function () {

        var that = this;
        var $legend = jQuery( '#' + this.dom_id + ' > .owa_chartLegend' );

        $legend.addClass( 'owa_chartLegendInteractive' ).off( 'click.owaSeries' );

        if ( this.dataseries.length < 2 ) {

            // One line is always the selected one. Offering a control that can
            // only turn itself off is worse than offering none.
            $legend.removeClass( 'owa_chartLegendInteractive' );

            return;
        }

        $legend.on( 'click.owaSeries', '.legendLabel, .legendColorBox', function () {

            /*
             * The series index is stamped on the cell, not read from its row.
             *
             * The legend is laid out with one COLUMN per series so it reads
             * left to right, which means every entry is in the same single
             * <tr> -- so closest('tr').index() is 0 for all of them, and every
             * click selected the first series.
             */
            var index = parseInt( jQuery( this ).attr( 'data-series' ), 10 );

            if ( isNaN( index ) || index < 0 || index >= that.dataseries.length ) {

                return;
            }

            // Clicking the selected one again puts every line back, so there is
            // always a way out that does not need a second control.
            that.selected = ( that.selected === index ) ? null : index;

            that.draw();
        } );
    },

    /**
     * Number the legend entries, and say which one is selected.
     *
     * Run after every draw, because flot rebuilds the legend's contents each
     * time -- so the numbering has to be reapplied, not set once.
     *
     * The entries are cells rather than rows: the legend is one column per
     * series so that it reads left to right, which puts them all in a single
     * <tr>. Each label and its colour swatch carry the index, which is what the
     * click handler reads and what these classes hang on.
     */
    markLegend : function () {

        var $legend  = jQuery( '#' + this.dom_id + ' > .owa_chartLegend' );
        var selected = this.selected;

        $legend.find( '.legendLabel' ).each( function ( i ) {

            var $label = jQuery( this );
            var $swatch = $label.prevAll( '.legendColorBox' ).first();

            $label.add( $swatch )
                .attr( 'data-series', i )
                .toggleClass( 'owa_seriesSelected', selected === i )
                .toggleClass( 'owa_seriesDimmed', selected !== null && selected !== i );
        } );
    },

    // shows a tool tip for flot charts
    showTooltip : function(x, y, contents) {
    
        jQuery('<div id="tooltip">' + contents + '</div>').css( {
            position: 'absolute',
            display: 'none',
            top: y + 5,
            left: x + 5,
            border: '1px solid #cccccc',
            padding: '2px',
            'background-color': '#ffffff',
            opacity: 0.90
        }).appendTo("body").fadeIn(100);
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
            '<div class="owa_chartControls"></div>'
          + '<div class="owa_areaChart"></div>'
          + '<div class="owa_chartLegend"></div>' );

        jQuery(that.domSelector).css('width', this.getOption('width'));
        jQuery(that.domSelector).css('height', h);
        
        // binds a tooltip to plot points
        var previousPoint = null;
        jQuery(that.domSelector).bind("plothover", function (event, pos, item) {

            jQuery("#x").text(pos.x.toFixed(2));
            jQuery("#y").text(pos.y.toFixed(2));
            
            if (item) {
                if (previousPoint != item.datapoint) {
                    
                    previousPoint = item.datapoint;
                    
                    jQuery("#tooltip").remove();
                    var x = item.datapoint[0].toFixed(0),
                        y = item.datapoint[1].toFixed(0);
                        
                    if (that.options.xaxis.mode === 'time') {
                    
                        x = that.timestampFormatter(x);
                    }
                    
                    that.showTooltip(item.pageX -75, item.pageY -50,
                                x+'<BR><B>'+item.series.label + ":</B> " + y);
                }
            } else {
                jQuery("#tooltip").remove();
                previousPoint = null;            
            }
        });  
    }    
}
