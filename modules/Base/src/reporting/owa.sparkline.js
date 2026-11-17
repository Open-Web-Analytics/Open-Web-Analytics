//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2010 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * OWA Sparkline Implementation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

// OWA is defined by owa.format.js; this module augments it (OWA.sparkline = ...). jQuery is
// only the result-set event a box listens for; the line itself is plain SVG.
import * as jQuery from 'jquery';
import { OWA } from './owa.format.js';

var SVG_NS = 'http://www.w3.org/2000/svg';

OWA.sparkline = function(dom_id) {

    this.config = OWA.config || '';

    this.dom_id = dom_id || '';

    this.data = '';

    // Pixels. The line and its fill take their colours from the stylesheet
    // (--owa-sparkline-line, --owa-sparkline-fill).
    this.options = {
        lineWidth: 2,
        width: 100,
        height: 20,
        fill: true
    };

}

/** A value the line passes through: a finite number. Anything else is a gap. */
OWA.sparkline.value = function ( v ) {

    if ( v === null || v === undefined || v === '' ) {

        return null;
    }

    var n = Number( v );

    return isFinite( n ) ? n : null;
};

/**
 * The line through data inside a width x height box, as runs of [x, y] points.
 *
 * Values are spaced evenly by position and scaled so the lowest touches the
 * bottom and the highest the top, inset by `inset` so a stroke that wide is
 * not clipped. A value that is not a number breaks the line, so each run is
 * the points between two gaps. A flat series sits at mid-height, and a single
 * value is drawn flat across the box.
 */
OWA.sparkline.runs = function ( data, width, height, inset ) {

    var values = ( data || [] ).map( OWA.sparkline.value );
    var ys = values.filter( function ( v ) { return v !== null; } );

    if ( ! ys.length ) {

        return [];
    }

    inset = inset || 0;

    if ( values.length === 1 ) {

        values = [ values[0], values[0] ];
    }

    var min = Math.min.apply( null, ys );
    var max = Math.max.apply( null, ys );
    var left = inset, right = width - inset, top = inset, bottom = height - inset;
    var step = ( right - left ) / ( values.length - 1 );

    var y = function ( v ) {

        return max === min ? height / 2 : bottom - ( v - min ) / ( max - min ) * ( bottom - top );
    };

    var runs = [], run = [];

    values.forEach( function ( v, i ) {

        if ( v === null ) {

            if ( run.length ) {

                runs.push( run );
            }

            run = [];
            return;
        }

        run.push( [ left + i * step, y( v ) ] );
    } );

    if ( run.length ) {

        runs.push( run );
    }

    return runs;
};

/** Path data for the runs: the line, or (closed) the area under it down to `floor`. */
OWA.sparkline.path = function ( runs, floor ) {

    var r = function ( n ) {

        return Math.round( n * 100 ) / 100;
    };

    return runs.map( function ( run ) {

        // A lone point is a zero-length segment; the round cap draws it as a dot.
        var pts = run.length === 1 ? [ run[0], run[0] ] : run;
        var d = pts.map( function ( p, i ) {

            return ( i ? 'L' : 'M' ) + r( p[0] ) + ' ' + r( p[1] );
        } ).join( ' ' );

        if ( floor === undefined ) {

            return d;
        }

        var first = pts[0], last = pts[ pts.length - 1 ];

        return 'M' + r( first[0] ) + ' ' + r( floor ) + ' L' + d.slice( 1 )
            + ' L' + r( last[0] ) + ' ' + r( floor ) + ' Z';
    } ).join( ' ' );
};

/**
 * The sparkline as an <svg>: an image named by `label`, or hidden from
 * assistive technology when no label is given.
 */
OWA.sparkline.svg = function ( data, options, label ) {

    var o = options || {};
    var width = parseFloat( o.width ) || 100;
    var height = parseFloat( o.height ) || 20;
    var lineWidth = o.lineWidth === undefined ? 2 : Number( o.lineWidth );
    var runs = OWA.sparkline.runs( data, width, height, lineWidth / 2 );

    var el = function ( name, attrs ) {

        var node = document.createElementNS( SVG_NS, name );

        for ( var a in attrs ) {

            node.setAttribute( a, attrs[ a ] );
        }

        return node;
    };

    var svg = el( 'svg', {
        width: width,
        height: height,
        viewBox: '0 0 ' + width + ' ' + height,
        focusable: 'false',
        'data-ui': 'sparkline'
    } );

    if ( label ) {

        svg.setAttribute( 'role', 'img' );
        svg.setAttribute( 'aria-label', label );
    } else {

        svg.setAttribute( 'aria-hidden', 'true' );
    }

    if ( ! runs.length ) {

        return svg;
    }

    if ( o.fill !== false ) {

        svg.appendChild( el( 'path', { 'class': 'owa_sparklineFill', 'data-ui': 'sparkline-fill', d: OWA.sparkline.path( runs, height ) } ) );
    }

    svg.appendChild( el( 'path', {
        'class': 'owa_sparklineLine',
        'data-ui': 'sparkline-line',
        d: OWA.sparkline.path( runs ),
        fill: 'none',
        'stroke-width': lineWidth
    } ) );

    return svg;
};

OWA.sparkline.prototype = {

    mergeOptions: function ( options ) {

        for (var option in options) {

            if ( options.hasOwnProperty( option ) ) {
                this.options[ option ] = options[ option ];
            }
        }
    },

    setDomId: function( dom_id ) {

        this.dom_id = dom_id;
        this.domSelector = this.dom_id + ' > .owa_sparkline';
        // listen for data change events
        var that = this;
        jQuery( '#' + that.dom_id ).on( 'new_result_set', function( event, resultSet ) {
            jQuery( '#' + that.domSelector ).remove();
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

    generate : function(resultSet, dom_id, options) {

        if ( dom_id ) {

            this.setDomId( dom_id );
        }

        if ( options ) {

            this.mergeOptions( options );
        }

        var data = resultSet.getSeries(this.options.metric, '', this.options.filter);

        if ( ! data) {
            data = [0,0,0];
        }

        this.loadFromArray(data);
    },

    /** The line in words, for a reader who cannot see it: its lowest and highest value. */
    summary : function ( data ) {

        var ys = ( data || [] ).map( OWA.sparkline.value ).filter( function ( v ) { return v !== null; } );

        var num = function ( n ) {
            return OWA.util.formatNumber( n, { maximumFractionDigits: 2 } );
        };

        return ys.length
            ? OWA.util.sprintf( OWA.l( 'Trend: lowest %1$s, highest %2$s' ), num( Math.min.apply( null, ys ) ), num( Math.max.apply( null, ys ) ) )
            : OWA.l( 'Trend' );
    },

    /** Draws data into the box, after anything already in it. */
    loadFromArray :function(data) {

        var box = document.getElementById( this.dom_id );

        if ( ! box ) {

            return;
        }

        this.data = data;

        var p = document.createElement( 'p' );

        p.className = 'owa_sparkline';
        p.appendChild( OWA.sparkline.svg( data, this.options, this.summary( data ) ) );
        box.appendChild( p );
    },

    setHeight: function(height) {

        this.options.height = height;
    },

    setWidth: function(width) {

        this.options.width = width;
    }
}
