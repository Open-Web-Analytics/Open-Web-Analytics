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
        no_results_text: OWA.l( 'Name not found.' ),
        placeholder_text_single: OWA.l( 'Choose a dimension' )
    } );

    jQuery.each( jQuery.extend( {}, OWA.filterPills.OPERATORS, OWA.filterPills.EMPTY_TESTS ), function ( value, label ) {
        that.$( 'operator' ).append( jQuery( '<option>' ).attr( 'value', value ).text( OWA.translate( label ) ) );
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

/*
 * The conditions the form offers. A stored clause may use any operator the
 * server parses, so split() reads with those, not with these. The labels are
 * catalogued from here (Strings::LABEL_TABLES) and shown translated.
 */
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

/**
 * One stored clause as { name, op, value }.
 *
 * Split where the server splits it, and where the report grid's chips do
 * (OWA.dataGrid.parseConstraints()): at the first operator, in the server's
 * order, found in the clause. Read when a clause is split, not when this file
 * loads: the bundle is on the page before OWA.config is filled in.
 */
OWA.filterPills.split = function ( clause ) {

    var ops = OWA.dataGrid.operators();

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

/**
 * How an operator reads on a pill. A stored clause may use one the form does
 * not offer (`>=`, `=~`), which reads as the report grid's chips say it.
 */
OWA.filterPills.phrase = function ( op ) {

    var phrase = OWA.filterPills.OPERATORS[ op ] || OWA.filterPills.EMPTY_TESTS[ op ]
        || OWA.dataGrid.OPERATOR_PHRASES[ op ];

    return phrase ? OWA.translate( phrase ) : op;
};

/**
 * A filter as the report grid's chip words it: "%1$s %2$s %3$s" (field,
 * condition, value), or "%1$s %2$s" for an empty test -- as the parts of the
 * sentence either side of the value, which the caller puts between them.
 */
OWA.filterPills.sentence = function ( f, labelOf ) {

    var empty = OWA.filterPills.EMPTY_TESTS.hasOwnProperty( f.op );
    var form  = empty
        ? OWA.l( '%1$s %2$s', 'filter chip: field, condition' )
        : OWA.l( '%1$s %2$s %3$s', 'filter chip: field, condition, value' );

    var label  = labelOf ? labelOf( f.name ) : f.name;
    var phrase = OWA.filterPills.phrase( f.op );

    return form.split( '%3$s' ).map( function ( part ) {
        return OWA.util.sprintf( part, label, phrase );
    } );
};

/** "Page Path contains /blog/", with labelOf() naming the dimension. */
OWA.filterPills.words = function ( f, labelOf ) {

    return OWA.filterPills.sentence( f, labelOf ).join( f.value );
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

            var $pill = jQuery( '<span class="owa_filterPill">' ).attr( 'data-index', i );

            // The value in bold where the sentence puts it.
            OWA.filterPills.sentence( f, that.labelOf.bind( that ) ).forEach( function ( part, k ) {

                if ( k > 0 ) {
                    $pill.append( jQuery( '<strong>' ).text( f.value ) );
                }

                if ( part !== '' ) {
                    $pill.append( jQuery( '<span>' ).text( part ) );
                }
            } );

            $pill.append( jQuery( '<button type="button" class="owa_filterPillRemove">' )
                .attr( {
                    'aria-label': OWA.util.sprintf( OWA.l( 'Remove the filter %s' ), OWA.filterPills.words( f, that.labelOf.bind( that ) ) ),
                    title: OWA.l( 'Remove this filter' )
                } )
                .append( '<i class="fas fa-times" aria-hidden="true"></i>' ) );

            $holder.append( $pill );
        } );
    },

    open : function () {

        var $dim = this.$( 'dimension' ).empty()
            .append( jQuery( '<option value="">' ).text( OWA.l( 'Choose a dimension' ) ) );

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
            return OWA.l( 'A filter value cannot contain a comma.' );
        }

        return OWA.l( 'Add the filter you started, or cancel it.' );
    }
};
