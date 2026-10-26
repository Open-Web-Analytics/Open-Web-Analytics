// OWA is defined by owa.js; this module augments it (OWA.shareChart = ...).
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

/**
 * A share chart: each value of one dimension as its share of one metric, as a
 * ranked list. A row is the value's name and its percentage, with a bar under
 * it against the largest -- the bars a card draws (owa.grid.css), so the two
 * read as one kind of thing.
 *
 * It replaced a pie. A pie's slices are angles, which are hard to compare,
 * and its names were a legend beside the circle that had to be fitted into a
 * fixed box. Here the names are the rows and the shares are printed.
 *
 * The widget type is still `pie` in a report definition, so a saved report
 * keeps working; only what it draws changed.
 */
OWA.shareChart = function ( options ) {

    this.options = {
        dimension: '',
        metric: '',

        /*
         * How many values get a row of their own. The rest are summed into
         * Other, which is always last whatever its size, so the list stays a
         * ranking of named values.
         */
        numSlices: 5,

        // Raw dimension value -> row label, set by a report definition.
        valueLabels: null,

        // The widget's own constraints; see OWA.dataGrid.emptyText.
        ownConstraints: ''
    };

    if ( options ) {
        this.mergeOptions( options );
    }

    this.dom_id = '';
};

OWA.shareChart.prototype = {

    mergeOptions : function ( options ) {

        for ( var option in options ) {

            if ( options.hasOwnProperty( option ) ) {
                this.options[ option ] = options[ option ];
            }
        }
    },

    setDomId : function ( dom_id ) {

        var that = this;

        this.dom_id = dom_id;

        // Namespaced, so a second makeShareChart on the same element replaces
        // the listener rather than drawing twice per refetch.
        jQuery( '#' + dom_id )
            .off( 'new_result_set.owaShareChart' )
            .on( 'new_result_set.owaShareChart', function ( event, resultSet ) {
                that.generate( resultSet );
            } );
    },

    /**
     * A row's name: the report's own label for the raw value, then the
     * formatted value, then the raw one.
     */
    label : function ( cell ) {

        var labels = this.options.valueLabels;
        var raw    = ( cell.value === null || cell.value === undefined ) ? '' : String( cell.value );

        if ( labels && Object.prototype.hasOwnProperty.call( labels, raw ) ) {
            return String( labels[ raw ] );
        }

        if ( cell.formatted_value === null || cell.formatted_value === undefined || cell.formatted_value === '' ) {
            return raw;
        }

        return String( cell.formatted_value );
    },

    /**
     * The rows to draw: { label, value, share } in rank order, Other last.
     *
     * Rows that carry the same label are one row with their sum -- a
     * valueLabels map may fold two raw values onto one name. Shares are of the
     * metric's total for the period when the result set has one, and of the
     * rows' own sum when it does not.
     */
    rows : function ( resultSet ) {

        var dimension = this.options.dimension;
        var metric    = this.options.metric;
        var byLabel   = Object.create( null );
        var named     = [];
        var all       = 0;

        ( resultSet.resultsRows || [] ).forEach( function ( row ) {

            var v = parseFloat( row[ metric ] ? row[ metric ].value : 0 ) || 0;

            all += v;

            var label = this.label( row[ dimension ] || { value: '' } );

            if ( byLabel[ label ] ) {
                byLabel[ label ].value += v;
            } else {
                byLabel[ label ] = { label: label, value: v };
                named.push( byLabel[ label ] );
            }
        }, this );

        named.sort( function ( a, b ) { return b.value - a.value; } );

        var agg   = resultSet.aggregates && resultSet.aggregates[ metric ];
        var total = agg ? parseFloat( agg.value ) : NaN;

        if ( isNaN( total ) || total < all ) {
            total = all;
        }

        var shown = named.slice( 0, this.options.numSlices );
        var sum   = shown.reduce( function ( t, r ) { return t + r.value; }, 0 );

        // The rest of the total: the rows past the cut, and any the query
        // did not return.
        if ( total - sum > 0 && ( named.length > shown.length || total > all ) ) {
            shown.push( { label: OWA.l( 'Other' ), value: total - sum, other: true } );
        }

        shown.forEach( function ( r ) {
            r.share = total > 0 ? r.value / total : 0;
        } );

        return shown;
    },

    generate : function ( resultSet, dom_id, options ) {

        if ( dom_id ) {
            this.setDomId( dom_id );
        }

        if ( options ) {
            this.mergeOptions( options );
        }

        var $box  = jQuery( '#' + this.dom_id );
        var rows  = this.rows( resultSet || {} );
        var max   = rows.reduce( function ( m, r ) { return Math.max( m, r.share ); }, 0 );

        $box.children( '.owa_shareChart, .owa_gridEmpty' ).remove();

        if ( ! rows.length || max <= 0 ) {

            $box.append( jQuery( '<p class="owa_gridEmpty"></p>' )
                .text( OWA.dataGrid.emptyText( resultSet, this.options.ownConstraints ) ) );
            return;
        }

        var $list = jQuery( '<ol class="owa_shareChart"></ol>' );

        rows.forEach( function ( r ) {

            var $row = jQuery( '<li class="owa_shareRow"></li>' ).toggleClass( 'is-other', !! r.other );

            $row.append( jQuery( '<span class="owa_shareHead"></span>' )
                .append( jQuery( '<span class="owa_shareLabel"></span>' ).text( r.label ).attr( 'title', r.label ) )
                .append( jQuery( '<span class="owa_shareValue"></span>' ).text( OWA.shareChart.percent( r.share ) ) ) );

            $row.append( jQuery( '<span class="owa_gridBar" aria-hidden="true"></span>' )
                .append( jQuery( '<span class="owa_gridBarFill"></span>' )
                    .css( 'width', ( r.share / max * 100 ).toFixed( 1 ) + '%' ) ) );

            $list.append( $row );
        } );

        $box.append( $list );
    }
};

/**
 * A share as a whole percent, the way it is read. Under half a percent is
 * "<1%" rather than "0%", which would say the value is not there.
 */
OWA.shareChart.percent = function ( share ) {

    if ( share > 0 && share < 0.005 ) {
        return '<1%';
    }

    return Math.round( share * 100 ) + '%';
};
