// OWA is defined by owa.format.js; this module augments it (OWA.filterPills = ...).
import * as jQuery from 'jquery';
import { OWA } from './owa.format.js';

/**
 * Filters as pills: a Filter button, a pill per filter, and a small form
 * (dimension, condition, value) that Add filter turns into a pill. Pressing a
 * pill opens the form on that filter, to change it; its X removes it. Used by
 * the report editor's widget panel, the funnel builder, and the segment
 * filters of the funnel and recordings reports, over the markup in
 * filter_pills.php.
 *
 * The STORED format is the constraints string every report uses --
 * `name==value`, comma separated. parse() and stringify() are the only place
 * it is read or written, so the syntax is not something an author has to know.
 *
 *   ids         the element ids: pills, add, form, dimension, operator,
 *               value, addFilter, cancel
 *   dimensions  [{name, label, group}], what may be filtered on; group is
 *               optional, and puts the choice under that heading
 *   onChange    called whenever the filters or the form change
 */
OWA.filterPills = function ( ids, dimensions, onChange ) {

    this.ids        = ids;
    this.dimensions = dimensions || [];
    this.onChange   = onChange || function () {};
    this.filters    = [];
    this.editing    = null;
    this.addText    = this.$( 'addFilter' ).text();

    var that = this;

    this.$( 'dimension' ).chosen( {
        width: '100%',
        no_results_text: OWA.l( 'Name not found.' ),
        placeholder_text_single: OWA.l( 'Choose a dimension' )
    } );

    // chosen draws its own control after the <select>.
    this.$( 'dimension' ).next( '.chosen-container' ).attr( 'data-ui', 'filter:dimension' );

    OWA.filterPills.OFFERED.concat( Object.keys( OWA.dataGrid.EMPTY_TESTS ) ).forEach( function ( op ) {
        that.$( 'operator' ).append( jQuery( '<option>' ).attr( 'value', op ).text( OWA.filterPills.phrase( op ) ) );
    } );

    // While a pill is being changed, Filter starts a new filter instead.
    this.$( 'add' ).on( 'click', function () {
        if ( that.isOpen() && that.editing === null ) {
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
            if ( e.key === 'Enter' ) {
                e.preventDefault();
                that.$( 'addFilter' ).trigger( 'click' );
            }
        } );

    this.$( 'addFilter' ).on( 'click', function () {

        var f = that.read();

        if ( ! f ) {
            return;
        }

        if ( that.editing === null ) {
            that.filters.push( f );
        } else {
            that.filters[ that.editing ] = f;
        }

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
        that.filters.splice( that.indexOf( this ), 1 );

        if ( that.editing !== null ) {
            that.close();
        }

        that.render();
        that.onChange();
        that.$( 'add' ).trigger( 'focus' );
    } );

    this.$( 'pills' ).on( 'click', '.owa_filterPillEdit', function () {

        var i = that.indexOf( this );

        if ( that.isOpen() && that.editing === i ) {
            that.close();
        } else {
            that.open( i );
        }

        that.onChange();
    } );

    this.$( 'form' ).on( 'keydown', function ( e ) {

        if ( e.key !== 'Escape' || jQuery( e.target ).closest( '.chosen-container' ).length ) {
            return;
        }

        // The form's Escape closes the form, not whatever holds it.
        e.preventDefault();
        e.stopPropagation();
        that.$( 'cancel' ).trigger( 'click' );
    } );
};

/*
 * The operators the form offers, then the empty tests
 * (OWA.dataGrid.EMPTY_TESTS), each worded as the grid's chips word it. A
 * stored clause may use any operator the server parses.
 */
OWA.filterPills.OFFERED = [ '==', '!=', '=@', '>', '<' ];

/**
 * What may be filtered on, from lists by group as a result set gives them
 * ({group: [{name, label}]}): [{name, label, group}], in the order given.
 */
OWA.filterPills.choices = function () {

    var out = [];

    Array.prototype.forEach.call( arguments, function ( groups ) {

        jQuery.each( groups || {}, function ( group, list ) {

            ( list || [] ).forEach( function ( c ) {
                out.push( { name: c.name, label: c.label, group: group } );
            } );
        } );
    } );

    return out;
};

/**
 * One stored clause as { name, op, value }: OWA.dataGrid.parseClause(), the
 * report grid's reader, with the name and value trimmed. A clause with no
 * operator is a name with nothing to match yet.
 */
OWA.filterPills.split = function ( clause ) {

    var c = OWA.dataGrid.parseClause( clause );

    if ( ! c ) {
        return { name: jQuery.trim( clause ), op: '==', value: '' };
    }

    return { name: jQuery.trim( c.name ), op: c.operator, value: jQuery.trim( c.value ) };
};

OWA.filterPills.parse = function ( str ) {

    return String( str || '' ).split( ',' )
        .filter( function ( c ) { return jQuery.trim( c ) !== ''; } )
        .map( OWA.filterPills.split );
};

OWA.filterPills.stringify = function ( filters ) {

    return filters.map( function ( f ) {
        return f.name + f.op + ( OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( f.op ) ? ''
            : OWA.dataGrid.constraintValue( f.value ) );
    } ).join( ',' );
};

/** How an operator reads on a pill and in the form: as the report grid's chips say it. */
OWA.filterPills.phrase = function ( op ) {

    var phrase = OWA.dataGrid.EMPTY_TESTS[ op ] || OWA.dataGrid.OPERATOR_PHRASES[ op ];

    return phrase ? OWA.translate( phrase ) : op;
};

/**
 * A filter as the report grid's chip words it: "%1$s %2$s %3$s" (field,
 * condition, value), or "%1$s %2$s" for an empty test -- as the parts of the
 * sentence either side of the value, which the caller puts between them.
 */
OWA.filterPills.sentence = function ( f, labelOf ) {

    var empty = OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( f.op );
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

    /** The index of the filter whose pill holds this element. */
    indexOf : function ( el ) {
        return Number( jQuery( el ).closest( '.owa_filterPill' ).attr( 'data-index' ) );
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

            var words = OWA.filterPills.words( f, that.labelOf.bind( that ) );
            var $pill = jQuery( '<span class="owa_filterPill">' ).attr( 'data-index', i );
            var $edit = jQuery( '<button type="button" class="owa_filterPillEdit" aria-expanded="false">' )
                .attr( {
                    'aria-label': OWA.util.sprintf( OWA.l( 'Change the filter %s' ), words ),
                    'aria-controls': that.ids.form,
                    title: OWA.l( 'Change this filter' )
                } );

            // The value in bold where the sentence puts it.
            OWA.filterPills.sentence( f, that.labelOf.bind( that ) ).forEach( function ( part, k ) {

                if ( k > 0 ) {
                    $edit.append( jQuery( '<strong>' ).text( f.value ) );
                }

                if ( part !== '' ) {
                    $edit.append( jQuery( '<span>' ).text( part ) );
                }
            } );

            $pill.append( $edit, jQuery( '<button type="button" class="owa_filterPillRemove">' )
                .attr( {
                    'aria-label': OWA.util.sprintf( OWA.l( 'Remove the filter %s' ), words ),
                    title: OWA.l( 'Remove this filter' )
                } )
                .append( '<i class="fas fa-times" aria-hidden="true"></i>' ) );

            $holder.append( $pill );
        } );
    },

    /** The form, empty for a new filter, or holding filter `index` to change it. */
    open : function ( index ) {

        var editing = index === undefined ? null : index;
        var f       = editing === null ? { name: '', op: '==', value: '' } : this.filters[ editing ];
        var groups  = Object.create( null );
        var $dim    = this.$( 'dimension' ).empty()
            .append( jQuery( '<option value="">' ).text( OWA.l( 'Choose a dimension' ) ) );

        this.dimensions.forEach( function ( choice ) {

            var $option = jQuery( '<option>' ).attr( 'value', choice.name )
                .text( choice.label + ' (' + choice.name + ')' );

            if ( ! choice.group ) {
                $dim.append( $option );
                return;
            }

            if ( ! groups[ choice.group ] ) {
                groups[ choice.group ] = jQuery( '<optgroup>' ).attr( 'label', choice.group ).appendTo( $dim );
            }

            groups[ choice.group ].append( $option );
        } );

        // A stored filter may name a dimension, or use an operator, the form does not offer.
        if ( f.name && ! this.hasOption( $dim, f.name ) ) {
            $dim.append( jQuery( '<option>' ).attr( 'value', f.name ).text( f.name ) );
        }

        var $op = this.$( 'operator' );

        $op.children( '[data-stored]' ).remove();

        if ( ! this.hasOption( $op, f.op ) ) {
            $op.append( jQuery( '<option data-stored="">' ).attr( 'value', f.op ).text( OWA.filterPills.phrase( f.op ) ) );
        }

        $dim.val( f.name ).trigger( 'chosen:updated' );
        $op.val( f.op );
        this.$( 'value' ).val( f.value );

        this.editing = editing;
        this.$( 'addFilter' ).text( editing === null ? this.addText : OWA.l( 'Update filter' ) );
        this.$( 'form' ).show();
        this.$( 'add' ).attr( 'aria-expanded', editing === null ? 'true' : 'false' );
        this.$( 'pills' ).find( '.owa_filterPill' ).each( function ( i ) {
            jQuery( this ).children( '.owa_filterPillEdit' ).attr( 'aria-expanded', i === editing ? 'true' : 'false' );
        } );

        this.sync();

        $dim.trigger( 'chosen:activate' );
    },

    close : function () {

        this.editing = null;
        this.$( 'form' ).hide();
        this.$( 'addFilter' ).text( this.addText );
        this.$( 'add' ).attr( 'aria-expanded', 'false' );
        this.$( 'pills' ).find( '.owa_filterPillEdit' ).attr( 'aria-expanded', 'false' );
    },

    hasOption : function ( $select, value ) {

        return $select.find( 'option' ).filter( function () {
            return this.value === value;
        } ).length > 0;
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

        if ( ! f.name || ( ! f.value && ! OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( f.op ) ) ) {
            return null;
        }

        // A comma would split the stored list into two clauses.
        if ( f.value.indexOf( ',' ) !== -1 ) {
            return null;
        }

        return f;
    },

    sync : function () {

        var empty = OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( this.$( 'operator' ).val() );

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

        return this.editing === null
            ? OWA.l( 'Add the filter you started, or cancel it.' )
            : OWA.l( 'Update the filter you are changing, or cancel it.' );
    }
};
