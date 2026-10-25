// OWA is defined by owa.js; this module augments it (OWA.filterPills = ...).
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

/**
 * Filters as pills: a Filter button, a pill per filter with an X, and a small
 * form (dimension, condition, value) that Add filter turns into a pill. The
 * report editor's widget panel and the funnel builder both use it, over the
 * markup in filter_pills.php.
 *
 * The STORED format is the constraints string every report uses --
 * `name==value`, comma separated. parse() and stringify() are the only place
 * it is read or written, so the syntax is not something an author has to know.
 *
 *   ids         the element ids: pills, add, form, dimension, operator,
 *               value, addFilter, cancel
 *   dimensions  [{name, label}], what may be filtered on
 *   onChange    called whenever the filters or the form change
 */
OWA.filterPills = function ( ids, dimensions, onChange ) {

    this.ids        = ids;
    this.dimensions = dimensions || [];
    this.onChange   = onChange || function () {};
    this.filters    = [];

    var that = this;

    this.$( 'dimension' ).chosen( {
        width: '100%',
        no_results_text: 'Name not found.',
        placeholder_text_single: 'Choose a dimension'
    } );

    jQuery.each( jQuery.extend( {}, OWA.filterPills.OPERATORS, OWA.filterPills.EMPTY_TESTS ), function ( value, label ) {
        that.$( 'operator' ).append( jQuery( '<option>' ).attr( 'value', value ).text( label ) );
    } );

    this.$( 'add' ).on( 'click', function () {
        if ( that.isOpen() ) {
            that.close();
        } else {
            that.open();
        }
        that.onChange();
    } );

    this.$( 'dimension' ).add( this.$( 'operator' ) ).on( 'change', function () { that.sync(); } );

    this.$( 'value' )
        .on( 'input', function () {
            that.sync();
            that.onChange();
        } )
        .on( 'keydown', function ( e ) {
            if ( e.which === 13 ) {
                e.preventDefault();
                that.$( 'addFilter' ).trigger( 'click' );
            }
        } );

    this.$( 'addFilter' ).on( 'click', function () {

        var f = that.read();

        if ( ! f ) {
            return;
        }

        that.filters.push( f );
        that.render();
        that.close();
        that.onChange();
        that.$( 'add' ).trigger( 'focus' );
    } );

    this.$( 'cancel' ).on( 'click', function () {
        that.close();
        that.onChange();
        that.$( 'add' ).trigger( 'focus' );
    } );

    this.$( 'pills' ).on( 'click', '.owa_filterPillRemove', function () {
        that.filters.splice( Number( jQuery( this ).closest( '.owa_filterPill' ).attr( 'data-index' ) ), 1 );
        that.render();
        that.onChange();
        that.$( 'add' ).trigger( 'focus' );
    } );

    this.$( 'form' ).on( 'keydown', function ( e ) {

        if ( e.which !== 27 || jQuery( e.target ).closest( '.chosen-container' ).length ) {
            return;
        }

        // The form's Escape closes the form, not whatever holds it.
        e.preventDefault();
        e.stopPropagation();
        that.$( 'cancel' ).trigger( 'click' );
    } );
};

OWA.filterPills.OPERATORS = {
    '==': 'is',
    '!=': 'is not',
    '=@': 'contains',
    '>':  'is greater than',
    '<':  'is less than'
};

/*
 * The two tests on "(not set)", which the server reads as empty and has a
 * value. Stored as the operator plus that value; offered as their own
 * conditions, with no value to type.
 */
OWA.filterPills.EMPTY_TESTS = {
    '!=(not set)': 'is set',
    '==(not set)': 'is not set'
};

/** One stored clause as { name, op, value }. */
OWA.filterPills.split = function ( clause ) {

    // Longest first, so `!=` is not read as `=`.
    var ops = Object.keys( OWA.filterPills.OPERATORS ).sort( function ( a, b ) {
        return b.length - a.length;
    } );

    for ( var i = 0; i < ops.length; i++ ) {

        var at = clause.indexOf( ops[ i ] );

        if ( at > 0 ) {

            var part = {
                name:  jQuery.trim( clause.slice( 0, at ) ),
                op:    ops[ i ],
                value: jQuery.trim( clause.slice( at + ops[ i ].length ) )
            };

            if ( OWA.filterPills.EMPTY_TESTS.hasOwnProperty( part.op + part.value ) ) {
                part.op += part.value;
                part.value = '';
            }

            return part;
        }
    }

    return { name: jQuery.trim( clause ), op: '==', value: '' };
};

OWA.filterPills.parse = function ( str ) {

    return String( str || '' ).split( ',' )
        .filter( function ( c ) { return jQuery.trim( c ) !== ''; } )
        .map( OWA.filterPills.split );
};

OWA.filterPills.stringify = function ( filters ) {

    return filters.map( function ( f ) {
        return f.name + f.op + ( OWA.filterPills.EMPTY_TESTS.hasOwnProperty( f.op ) ? '' : f.value );
    } ).join( ',' );
};

/** "Page Path contains /blog/", with labelOf() naming the dimension. */
OWA.filterPills.words = function ( f, labelOf ) {

    var empty = OWA.filterPills.EMPTY_TESTS.hasOwnProperty( f.op );
    var words = OWA.filterPills.OPERATORS[ f.op ] || OWA.filterPills.EMPTY_TESTS[ f.op ] || f.op;

    return ( labelOf ? labelOf( f.name ) : f.name ) + ' ' + words + ( empty ? '' : ' ' + f.value );
};

OWA.filterPills.prototype = {

    $ : function ( key ) {
        return jQuery( '#' + this.ids[ key ] );
    },

    labelOf : function ( name ) {

        for ( var i = 0; i < this.dimensions.length; i++ ) {
            if ( this.dimensions[ i ].name === name ) {
                return this.dimensions[ i ].label;
            }
        }

        return name;
    },

    /** Show these filters, from the stored string, with the form shut. */
    set : function ( str ) {

        this.filters = OWA.filterPills.parse( str );
        this.close();
        this.render();
    },

    get : function () {
        return OWA.filterPills.stringify( this.filters );
    },

    render : function () {

        var that    = this;
        var $holder = this.$( 'pills' );

        $holder.children( '.owa_filterPill' ).remove();

        this.filters.forEach( function ( f, i ) {

            var empty = OWA.filterPills.EMPTY_TESTS.hasOwnProperty( f.op );

            var $pill = jQuery( '<span class="owa_filterPill">' ).attr( 'data-index', i )
                .append( jQuery( '<span>' ).text( that.labelOf( f.name ) + ' '
                    + ( OWA.filterPills.OPERATORS[ f.op ] || OWA.filterPills.EMPTY_TESTS[ f.op ] || f.op ) + ' ' ) );

            if ( ! empty ) {
                $pill.append( jQuery( '<strong>' ).text( f.value ) );
            }

            $pill.append( jQuery( '<button type="button" class="owa_filterPillRemove">' )
                .attr( {
                    'aria-label': 'Remove the filter ' + OWA.filterPills.words( f, that.labelOf.bind( that ) ),
                    title: 'Remove this filter'
                } )
                .append( '<i class="fas fa-times" aria-hidden="true"></i>' ) );

            $holder.append( $pill );
        } );
    },

    open : function () {

        var $dim = this.$( 'dimension' ).empty()
            .append( jQuery( '<option value="">' ).text( 'Choose a dimension' ) );

        this.dimensions.forEach( function ( choice ) {
            $dim.append( jQuery( '<option>' ).attr( 'value', choice.name )
                .text( choice.label + ' (' + choice.name + ')' ) );
        } );

        $dim.val( '' ).trigger( 'chosen:updated' );

        this.$( 'operator' ).val( '==' );
        this.$( 'value' ).val( '' );

        this.$( 'form' ).show();
        this.$( 'add' ).attr( 'aria-expanded', 'true' );

        this.sync();

        $dim.trigger( 'chosen:activate' );
    },

    close : function () {

        this.$( 'form' ).hide();
        this.$( 'add' ).attr( 'aria-expanded', 'false' );
    },

    isOpen : function () {
        return this.$( 'form' ).is( ':visible' );
    },

    /** The filter being written, or null if it is not finished. */
    read : function () {

        var f = {
            name:  this.$( 'dimension' ).val() || '',
            op:    this.$( 'operator' ).val() || '==',
            value: jQuery.trim( this.$( 'value' ).val() || '' )
        };

        if ( ! f.name || ( ! f.value && ! OWA.filterPills.EMPTY_TESTS.hasOwnProperty( f.op ) ) ) {
            return null;
        }

        // A comma would split the stored list into two clauses.
        if ( f.value.indexOf( ',' ) !== -1 ) {
            return null;
        }

        return f;
    },

    sync : function () {

        var empty = OWA.filterPills.EMPTY_TESTS.hasOwnProperty( this.$( 'operator' ).val() );

        this.$( 'value' ).toggle( ! empty );
        this.$( 'addFilter' ).prop( 'disabled', ! this.read() );
    },

    /**
     * Why the thing holding these cannot be applied or saved because of a
     * filter: one half written, or a value with a comma, which the stored
     * list cannot hold. '' when there is nothing open.
     */
    problem : function () {

        if ( ! this.isOpen() ) {
            return '';
        }

        if ( String( this.$( 'value' ).val() ).indexOf( ',' ) !== -1 ) {
            return 'A filter value cannot contain a comma.';
        }

        return 'Add the filter you started, or cancel it.';
    }
};
