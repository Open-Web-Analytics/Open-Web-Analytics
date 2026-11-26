// OWA is defined by owa.format.js; this module augments it (OWA.filterPills = ...).
import * as jQuery from 'jquery';
import { OWA } from './owa.format.js';
import './owa.fieldchooser.js';

/**
 * Filters as pills: a Filter button, a pill per filter, and a small form
 * (field, condition, value) that Add filter turns into a pill. The field is
 * picked with OWA.fieldChooser; the conditions and the value box follow the
 * kind of value it takes (OWA.dataGrid.conditionsFor(), valueInput()). Pressing a
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
 *   dimensions  what may be filtered on: chooser fields ({name, label,
 *               description, group, group_label, type, kind}), or the report
 *               editor's lists ({name, label, group, ...}), taken as dimensions
 *   onChange    called whenever the filters or the form change
 */
OWA.filterPills = function ( ids, dimensions, onChange ) {

    this.ids        = ids;
    this.dimensions = ( dimensions || [] ).map( function ( d ) {
        return d.kind ? d : OWA.fieldChooser.fromChoices( 'dimension', [ d ] )[0];
    } );
    this.onChange   = onChange || function () {};
    this.filters    = [];
    this.editing    = null;
    this.addText    = this.$( 'addFilter' ).text();
    this.value      = null;

    var that = this;

    this.field = OWA.fieldChooser.attach( this.$( 'dimension' ), {
        fields: this.dimensions,
        placeholder: OWA.l( 'Choose a field' ),
        title: OWA.l( 'Filter on' ),
        filtering: true,
        onPick: function () {
            that.conditions( '' );
            that.valueBox( '' );
            that.sync();
            that.onChange();
        }
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

    this.$( 'operator' ).on( 'change', function () { that.sync(); } );

    this.$( 'form' ).on( 'keydown', 'input', function ( e ) {
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

        if ( e.key !== 'Escape' ) {
            return;
        }

        // The form's Escape closes the form, not whatever holds it.
        e.preventDefault();
        e.stopPropagation();
        that.$( 'cancel' ).trigger( 'click' );
    } );
};

/**
 * What may be filtered on, from a result set's lists by group (dimensions,
 * then metrics, as relatedDimensions and relatedMetrics give them), as chooser
 * fields, in the order given.
 */
OWA.filterPills.choices = function ( dimensions, metrics ) {

    return OWA.fieldChooser.fromGroups( 'dimension', dimensions )
        .concat( OWA.fieldChooser.fromGroups( 'metric', metrics ) );
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

    return OWA.dataGrid.formatConstraints( filters.map( OWA.filterPills.clause ) );
};

/** A pill's filter in the report grid's shape ({name, operator, value}); an empty test has no value. */
OWA.filterPills.clause = function ( f ) {

    return {
        name:     f.name,
        operator: f.op,
        value:    OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( f.op ) ? '' : f.value
    };
};

/** How an operator reads on a pill: as the report grid's chips say it, for field when given. */
OWA.filterPills.phrase = function ( op, field ) {

    return OWA.dataGrid.phrase( op, field || null );
};

/** A pill's sentence either side of its value: the report grid's (OWA.dataGrid.filterSentence()). */
OWA.filterPills.sentence = function ( f, labelOf, fieldOf ) {

    return OWA.dataGrid.filterSentence( OWA.filterPills.clause( f ), labelOf, fieldOf );
};

/** "Page Path contains /blog/", as the report grid's chip says it (OWA.dataGrid.filterWords()). */
OWA.filterPills.words = function ( f, labelOf, fieldOf ) {

    return OWA.dataGrid.filterWords( OWA.filterPills.clause( f ), labelOf, fieldOf );
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

        var f = this.fieldOf( name );

        return f ? f.label : name;
    },

    fieldOf : function ( name ) {

        return OWA.fieldChooser.find( this.dimensions, name );
    },

    /**
     * The conditions the field picked takes, op selected; a stored operator
     * the form does not offer for it is kept as one more.
     */
    conditions : function ( op ) {

        var $op = this.$( 'operator' ).empty();

        OWA.dataGrid.conditionsFor( this.field.field() ).forEach( function ( c ) {
            $op.append( jQuery( '<option>' ).attr( 'value', c[0] ).text( OWA.translate( c[1] ) ) );
        } );

        if ( op && ! this.hasOption( $op, op ) ) {
            $op.append( jQuery( '<option data-stored="">' ).attr( 'value', op ).text( OWA.filterPills.phrase( op ) ) );
        }

        $op.val( op || $op.children().first().val() );
    },

    /** The value box the field picked takes, holding value. */
    valueBox : function ( value ) {

        var that = this;

        this.value = OWA.dataGrid.valueInput( this.field.field(), this.ids.value );
        this.$( 'value' ).replaceWith( this.value.$el );
        this.value.set( value );
        this.value.on( function () {
            that.sync();
            that.onChange();
        } );
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

            var words = OWA.filterPills.words( f, that.labelOf.bind( that ), that.fieldOf.bind( that ) );
            var $pill = jQuery( '<span class="owa_filterPill">' ).attr( 'data-index', i );
            var $edit = jQuery( '<button type="button" class="owa_filterPillEdit" aria-expanded="false">' )
                .attr( {
                    'aria-label': OWA.util.sprintf( OWA.l( 'Change the filter %s' ), words ),
                    'aria-controls': that.ids.form,
                    title: OWA.l( 'Change this filter' )
                } );

            // The value in bold where the sentence puts it.
            OWA.filterPills.sentence( f, that.labelOf.bind( that ), that.fieldOf.bind( that ) ).forEach( function ( part, k ) {

                if ( k > 0 ) {
                    $edit.append( jQuery( '<strong>' ).text( OWA.dataGrid.shownValue( f.value, that.fieldOf( f.name ) ) ) );
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
        var f       = editing === null ? { name: '', op: '', value: '' } : this.filters[ editing ];

        // A stored filter may name a field the form does not offer; it shows by name.
        this.field.set( f.name );
        this.conditions( f.op );
        this.valueBox( f.value );

        this.editing = editing;
        this.$( 'addFilter' ).text( editing === null ? this.addText : OWA.l( 'Update filter' ) );
        this.$( 'form' ).show();
        this.$( 'add' ).attr( 'aria-expanded', editing === null ? 'true' : 'false' );
        this.$( 'pills' ).find( '.owa_filterPill' ).each( function ( i ) {
            jQuery( this ).children( '.owa_filterPillEdit' ).attr( 'aria-expanded', i === editing ? 'true' : 'false' );
        } );

        this.sync();

        this.$( 'dimension' ).trigger( 'focus' );
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
            name:  this.field.get(),
            op:    this.$( 'operator' ).val() || '==',
            value: this.value ? this.value.get() : ''
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
        this.$( 'value' ).prev( 'label' ).toggle( ! empty );
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

        if ( this.value && this.value.get().indexOf( ',' ) !== -1 ) {
            return OWA.l( 'A filter value cannot contain a comma.' );
        }

        return this.editing === null
            ? OWA.l( 'Add the filter you started, or cancel it.' )
            : OWA.l( 'Update the filter you are changing, or cancel it.' );
    }
};
