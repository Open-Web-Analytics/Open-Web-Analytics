// OWA is defined by owa.format.js; this module augments it (OWA.fieldChooser = ...).
import * as jQuery from 'jquery';
import { OWA } from './owa.format.js';

/**
 * The metric and dimension chooser: one control for every place a person picks
 * a field (the filter pills, the grid's Filter, the report editor's widget
 * panel, the goal and funnel builders).
 *
 * It opens on the group names, each with its field count and a right chevron;
 * a group shows its fields under a back row; the search, on the group names,
 * covers every group. Clicking a field's name picks it and closes the chooser.
 * There are no buttons.
 *
 * On a desktop it is a popover under its trigger, fixed to the window so no
 * box it sits in clips it (the grid's Filter is itself a popover): a 320px list, and once a
 * group is open a detail pane on the list's right describing the field under
 * the pointer or the arrow keys. Below PHONE it is a sheet from the bottom with
 * the detail panel under the list: a row's info icon shows that field in it,
 * and the panel's chevron folds its secondary lines (type, how it adds up, how
 * it filters, API name) so the panel stays short.
 *
 *   fields   [{name, label, description, group, group_label, type, kind}],
 *            kind 'dimension' or 'metric', in the order to show them; groups
 *            appear in the order their first field does. Both kinds in one
 *            list are shown under a Dimensions and a Metrics heading.
 *   options  title      what the chooser is for, its accessible name
 *            selected   names to mark as chosen
 *            filtering  true to say how each field filters
 *            onPick     function ( name ), on a pick; the chooser then closes
 *            onClose    function (), whenever it closes
 */
OWA.fieldChooser = function ( fields, options ) {

    this.fields   = fields || [];
    this.options  = jQuery.extend( { title: '', selected: [], filtering: false, onPick: null, onClose: null }, options || {} );
    this.q        = '';
    this.group    = null;
    this.active   = null;
    this.more     = false;
    this.root     = null;
    this.id       = 'owa_fieldChooser' + ( ++OWA.fieldChooser.count );
};

OWA.fieldChooser.count = 0;

/** Below this width the chooser is a sheet. */
OWA.fieldChooser.PHONE = '(max-width: 699px)';

/** What a field's type is called in the detail. */
OWA.fieldChooser.TYPES = {
    string: 'Text',
    integer: 'Number',
    decimal: 'Number',
    boolean: 'Yes / No',
    datetime: 'Date and time',
    yyyymmdd: 'Date',
    yyyymm: 'Month',
    yyyymmddhh: 'Date and hour',
    count: 'Count',
    distinct_count: 'Distinct count',
    sum: 'Sum',
    ratio: 'Ratio',
    difference: 'Difference'
};

/** Whether a metric's rows add up to its total, by metric type. */
OWA.fieldChooser.ADDS = {
    count: 'Rows add up to the total.',
    sum: 'Rows add up to the total.',
    difference: 'Rows add up to the total.',
    distinct_count: 'One can be counted in several rows, so rows can add up to more than the total.',
    ratio: 'Each row is a rate of its own, so rows do not add up.'
};

/**
 * Fields from lists by group, as a result set gives them (relatedDimensions,
 * relatedMetrics): {group: [{name, label, description, group_label,
 * data_type | metric_type}]}.
 */
OWA.fieldChooser.fromGroups = function ( kind, groups ) {

    var out = [];

    jQuery.each( groups || {}, function ( group, list ) {

        ( list || [] ).forEach( function ( f ) {

            if ( f && f.name ) {
                out.push( {
                    name: f.name,
                    label: f.label || f.name,
                    description: f.description || '',
                    group: group,
                    group_label: f.group_label || group,
                    type: f.data_type || f.metric_type || f.type || '',
                    kind: kind
                } );
            }
        } );
    } );

    return out;
};

/** Fields from the report editor's lists ({name, label, description, group, group_label, type}). */
OWA.fieldChooser.fromChoices = function ( kind, choices ) {

    return ( choices || [] ).map( function ( c ) {
        return jQuery.extend( { description: '', group: '', group_label: c.group || '', type: '' }, c, { kind: kind } );
    } );
};

/** The field called name in fields, or null. */
OWA.fieldChooser.find = function ( fields, name ) {

    for ( var i = 0; i < ( fields || [] ).length; i++ ) {
        if ( fields[ i ].name === name ) {
            return fields[ i ];
        }
    }

    return null;
};

/**
 * Make button pick a field: it shows the field picked (or placeholder), and
 * opens the chooser under itself. Returns {get, set, field}.
 *
 *   options  fields (or a function returning them), value, placeholder,
 *            title, filtering, onPick ( name )
 */
OWA.fieldChooser.attach = function ( button, options ) {

    var $button = jQuery( button );
    var value   = options.value || '';
    var open    = null;

    $button.attr( { 'aria-haspopup': 'listbox', 'aria-expanded': 'false' } ).addClass( 'owa_fieldTrigger' );

    var fields = function () {
        return typeof options.fields === 'function' ? options.fields() : options.fields;
    };

    var draw = function () {

        var f = OWA.fieldChooser.find( fields(), value );

        $button.empty()
            .append( jQuery( '<span class="owa_fieldTriggerText"></span>' )
                .toggleClass( 'is-placeholder', ! value )
                .text( f ? f.label : ( value || options.placeholder || '' ) ) )
            .append( '<i class="fas fa-caret-down" aria-hidden="true"></i>' );
    };

    $button.on( 'click', function () {

        if ( open ) {
            open.close();
            return;
        }

        open = new OWA.fieldChooser( fields(), {
            title: options.title,
            selected: value ? [ value ] : [],
            filtering: options.filtering,
            onPick: function ( name ) {
                value = name;
                draw();
                if ( options.onPick ) {
                    options.onPick( name );
                }
            },
            onClose: function () { open = null; }
        } );

        open.open( $button[0] );
    } );

    draw();

    return {
        get: function () { return value; },
        set: function ( name ) { value = name || ''; draw(); },
        field: function () { return OWA.fieldChooser.find( fields(), value ); },
        refresh: draw
    };
};

OWA.fieldChooser.prototype = {

    /** Open under trigger: a popover, or a sheet on a phone. */
    open : function ( trigger ) {

        var that = this;

        this.trigger = trigger || null;
        this.sheet   = !! ( window.matchMedia && window.matchMedia( OWA.fieldChooser.PHONE ).matches );
        this.mount();

        if ( this.sheet ) {

            this.scrim = jQuery( '<div class="owa_fieldScrim"></div>' ).on( 'click', function () { that.close(); } );
            this.$root.addClass( 'owa_fieldSheet' ).attr( { role: 'dialog', 'aria-label': this.options.title } );
            jQuery( 'body' ).append( this.scrim, this.$root );

        } else {

            this.$root.addClass( 'owa_fieldPop' );
            jQuery( 'body' ).append( this.$root );
            this.place = function () { that.fit(); };
            this.fit();
            window.addEventListener( 'scroll', this.place, true );
            window.addEventListener( 'resize', this.place );
        }

        if ( this.trigger ) {
            jQuery( this.trigger ).attr( 'aria-expanded', 'true' );
        }

        this.outside = function ( e ) {
            if ( ! that.$root[0].contains( e.target ) && ! ( that.trigger && that.trigger.contains( e.target ) ) ) {
                that.close();
            }
        };

        setTimeout( function () { document.addEventListener( 'mousedown', that.outside ); }, 0 );

        if ( ! this.sheet ) {
            this.$search.trigger( 'focus' );
        }
    },

    close : function () {

        if ( ! this.$root ) {
            return;
        }

        document.removeEventListener( 'mousedown', this.outside );

        if ( this.place ) {
            window.removeEventListener( 'scroll', this.place, true );
            window.removeEventListener( 'resize', this.place );
        }

        this.$root.remove();
        this.$root = null;

        if ( this.scrim ) {
            this.scrim.remove();
        }

        if ( this.trigger ) {
            jQuery( this.trigger ).attr( 'aria-expanded', 'false' ).trigger( 'focus' );
        }

        if ( this.options.onClose ) {
            this.options.onClose();
        }
    },

    isOpen : function () {
        return !! this.$root;
    },

    /**
     * Place the popover under its trigger, or above it when there is more room
     * there, and inside the window: shifted left by what would overhang the
     * right edge (the detail pane widens it once a group is open).
     */
    fit : function () {

        if ( ! this.$root || this.sheet || ! this.trigger ) {
            return;
        }

        var el    = this.$root[0];
        var r     = this.trigger.getBoundingClientRect();
        var vw    = document.documentElement.clientWidth;
        var width = el.offsetWidth;
        var below = window.innerHeight - r.bottom - 8;
        var up    = below < 300 && r.top > below;

        el.style.left   = Math.round( Math.max( 8, Math.min( r.left, vw - width - 8 ) ) ) + 'px';
        el.style.top    = up ? 'auto' : Math.round( r.bottom + 4 ) + 'px';
        el.style.bottom = up ? Math.round( window.innerHeight - r.top + 4 ) + 'px' : 'auto';
    },

    mount : function () {

        var that = this;

        this.$root = jQuery( '<div class="owa_fieldChooser"></div>' )
            .attr( 'data-ui', 'field:chooser' )
            .attr( 'data-layout', this.sheet ? 'phone' : 'desktop' );

        var $main = jQuery( '<div class="owa_fieldMain"></div>' ).appendTo( this.$root );

        if ( this.sheet ) {
            $main.append( '<div class="owa_fieldGrip" aria-hidden="true"></div>' )
                .append( jQuery( '<div class="owa_fieldTitle"></div>' ).text( this.options.title ) );
        }

        this.$search = jQuery( '<input type="search" class="owa_fieldSearch" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="true">' )
            .attr( { id: this.id + '-q', placeholder: OWA.l( 'Search every group' ), 'aria-controls': this.id + '-list', 'aria-label': this.options.title } )
            .on( 'input', function () {
                that.q = jQuery.trim( this.value );
                that.active = null;
                that.draw();
            } )
            .on( 'keydown', function ( e ) { that.key( e ); } )
            .appendTo( $main );

        // Inside a group the search is hidden, so the list takes the keys.
        this.$list = jQuery( '<div class="owa_fieldList" role="listbox" tabindex="0"></div>' )
            .attr( { id: this.id + '-list', 'aria-label': this.options.title } )
            .on( 'keydown', function ( e ) {
                if ( e.target === this ) {
                    that.key( e );
                }
            } )
            .appendTo( $main );

        this.$detail = jQuery( '<div class="owa_fieldDetail" aria-live="polite"></div>' ).appendTo( this.$root );

        this.$root.on( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) {
                e.preventDefault();
                e.stopPropagation();
                that.close();
            }
        } );

        this.draw();
    },

    key : function ( e ) {

        var keys    = this.keys || [];
        var i       = keys.indexOf( this.active );
        var onGroup = this.active && this.active.indexOf( 'g:' ) === 0;

        switch ( e.key ) {

            case 'ArrowDown':
                e.preventDefault();
                this.setActive( keys[ Math.min( keys.length - 1, i + 1 ) ], true );
                break;

            case 'ArrowUp':
                e.preventDefault();
                this.setActive( keys[ Math.max( 0, i - 1 ) ], true );
                break;

            case 'Enter':
                if ( this.active ) {
                    e.preventDefault();
                    if ( onGroup ) {
                        this.enter( this.active );
                    } else {
                        this.pick( this.active );
                    }
                }
                break;

            case 'ArrowRight':
                if ( onGroup && ! this.q ) {
                    e.preventDefault();
                    this.enter( this.active );
                }
                break;

            case 'ArrowLeft':
            case 'Backspace':
                if ( this.group !== null && ! this.q ) {
                    e.preventDefault();
                    this.back();
                }
                break;
        }
    },

    /** Into one group, and back out to the group names. */
    enter : function ( key ) {

        this.group  = key.slice( 2 );
        this.active = null;
        this.draw();
        this.$list.trigger( 'focus' );
    },

    back : function () {

        var was = 'g:' + this.group;

        this.group  = null;
        this.active = was;
        this.draw();
        this.setActive( was, true );
        this.$search.trigger( 'focus' );
    },

    pick : function ( name ) {

        if ( this.options.onPick ) {
            this.options.onPick( name );
        }

        this.close();
    },

    /** The groups, in the order their first field comes: [{key, kind, label, fields}]. */
    groups : function () {

        var out = [];
        var at  = {};

        this.fields.forEach( function ( f ) {

            var key = f.kind + '|' + f.group;

            if ( ! at.hasOwnProperty( key ) ) {
                at[ key ] = out.length;
                out.push( { key: key, kind: f.kind, label: f.group_label || f.group, fields: [] } );
            }

            out[ at[ key ] ].fields.push( f );
        } );

        return out;
    },

    /** Both kinds in one list: a heading over each. */
    mixed : function () {

        var kinds = {};

        this.fields.forEach( function ( f ) { kinds[ f.kind ] = true; } );

        return Object.keys( kinds ).length > 1;
    },

    matches : function ( f ) {

        var hay = ( f.label + ' ' + f.description + ' ' + f.name + ' ' + ( f.group_label || '' ) ).toLowerCase();

        return this.q.toLowerCase().split( /\s+/ ).filter( Boolean ).every( function ( t ) {
            return hay.indexOf( t ) !== -1;
        } );
    },

    /** The list: the groups, one group's fields, or every group's matches. */
    draw : function () {

        var that  = this;
        var level = this.q ? 'search' : ( this.group !== null ? 'group' : 'groups' );
        var keep  = level === this.level ? this.$list.scrollTop() : 0;
        var mixed = this.mixed();
        var kindHeading = function ( kind ) {
            return kind === 'metric' ? OWA.l( 'Metrics' ) : OWA.l( 'Dimensions' );
        };

        this.level = level;
        this.keys  = [];
        this.$root.toggleClass( 'is-atGroups', level === 'groups' );
        this.$search.prop( 'hidden', level === 'group' );
        this.$list.empty();

        if ( level === 'groups' ) {

            var lastKind = null;

            this.groups().forEach( function ( g ) {

                if ( mixed && g.kind !== lastKind ) {
                    that.$list.append( jQuery( '<div class="owa_fieldHeading"></div>' ).text( kindHeading( g.kind ) ) );
                    lastKind = g.kind;
                }

                var key    = 'g:' + g.key;
                var chosen = g.fields.filter( function ( f ) { return that.options.selected.indexOf( f.name ) !== -1; } ).length;

                that.keys.push( key );

                var $row = jQuery( '<div class="owa_fieldOption owa_fieldGroup" role="option" aria-selected="false"></div>' )
                    .attr( { id: that.id + '-' + that.keys.length, 'data-key': key, 'aria-label': OWA.util.sprintf(
                        OWA.l( '%1$s, %2$s fields' ), g.label, g.fields.length ) } )
                    .append( jQuery( '<span class="owa_fieldLabel"></span>' ).text( g.label ) )
                    .append( jQuery( '<span class="owa_fieldCount"></span>' ).text( ( chosen ? '✓ ' : '' ) + g.fields.length ) )
                    .append( '<i class="fas fa-chevron-right owa_fieldChevron" aria-hidden="true"></i>' )
                    .on( 'click', function () { that.enter( key ); } );

                if ( ! that.sheet ) {
                    $row.on( 'mousemove', function () {
                        if ( that.active !== key ) {
                            that.setActive( key, false );
                        }
                    } );
                }

                that.$list.append( $row );
            } );

        } else if ( level === 'group' ) {

            var group = this.groups().filter( function ( g ) { return g.key === that.group; } )[0];

            if ( group ) {

                this.$list.append( jQuery( '<div class="owa_fieldBack" role="button"></div>' )
                    .attr( 'aria-label', OWA.l( 'Back to all groups' ) )
                    .append( '<i class="fas fa-chevron-left" aria-hidden="true"></i>' )
                    .append( jQuery( '<span></span>' ).text( group.label ) )
                    .append( mixed ? jQuery( '<span class="owa_fieldCount"></span>' ).text( kindHeading( group.kind ) ) : '' )
                    .on( 'click', function () { that.back(); } ) );

                group.fields.forEach( function ( f ) { that.$list.append( that.row( f ) ); } );
            }

        } else {

            var lastHeadingKind = null;

            this.groups().forEach( function ( g ) {

                var hits = g.fields.filter( function ( f ) { return that.matches( f ); } );

                if ( ! hits.length ) {
                    return;
                }

                if ( mixed && g.kind !== lastHeadingKind ) {
                    that.$list.append( jQuery( '<div class="owa_fieldHeading"></div>' ).text( kindHeading( g.kind ) ) );
                    lastHeadingKind = g.kind;
                }

                that.$list.append( jQuery( '<div class="owa_fieldGroupHead"></div>' ).text( g.label ) );
                hits.forEach( function ( f ) { that.$list.append( that.row( f ) ); } );
            } );

            if ( ! this.keys.length ) {
                this.$list.append( jQuery( '<div class="owa_fieldEmpty"></div>' )
                    .text( OWA.util.sprintf( OWA.l( 'Nothing matches “%s”.' ), this.q ) ) );
            }
        }

        if ( ! this.active || this.keys.indexOf( this.active ) === -1 ) {
            this.active = this.keys[0] || null;
        }

        this.$list.scrollTop( keep );
        this.setActive( this.active, false );
        this.fit();
    },

    /** One field's row: a click on its name picks it; on a phone its icon describes it. */
    row : function ( f ) {

        var that = this;
        var on   = this.options.selected.indexOf( f.name ) !== -1;

        this.keys.push( f.name );

        var $row = jQuery( '<div class="owa_fieldOption" role="option"></div>' )
            .attr( { id: this.id + '-' + this.keys.length, 'data-key': f.name, 'data-name': f.name, 'aria-selected': on ? 'true' : 'false' } )
            .append( jQuery( '<span class="owa_fieldCheck" aria-hidden="true"></span>' ).html( on ? '&#10003;' : '' ) )
            .append( jQuery( '<span class="owa_fieldLabel"></span>' ).text( f.label ) );

        if ( this.sheet ) {

            $row.append( jQuery( '<button type="button" class="owa_fieldInfo" aria-pressed="false"></button>' )
                .attr( 'aria-label', OWA.util.sprintf( OWA.l( 'About %s' ), f.label ) )
                .append( '<i class="fas fa-info-circle" aria-hidden="true"></i>' ) );

            $row.on( 'click', function ( e ) {
                if ( jQuery( e.target ).closest( '.owa_fieldInfo' ).length ) {
                    that.setActive( f.name, false );
                } else {
                    that.pick( f.name );
                }
            } );

        } else {

            $row.on( 'mousemove', function () {
                if ( that.active !== f.name ) {
                    that.setActive( f.name, false );
                }
            } ).on( 'click', function () { that.pick( f.name ); } );
        }

        return $row;
    },

    setActive : function ( key, scroll ) {

        this.active = key;

        var $rows = this.$list.children( '.owa_fieldOption' );

        $rows.removeClass( 'is-active' ).find( '.owa_fieldInfo' ).attr( 'aria-pressed', 'false' );

        var $row = $rows.filter( function () { return this.getAttribute( 'data-key' ) === key; } );

        if ( $row.length ) {

            $row.addClass( 'is-active' ).find( '.owa_fieldInfo' ).attr( 'aria-pressed', 'true' );
            this.$search.attr( 'aria-activedescendant', $row.attr( 'id' ) );
            this.$list.attr( 'aria-activedescendant', $row.attr( 'id' ) );

            if ( scroll ) {
                var top = $row[0].offsetTop;
                var h   = this.$list[0].clientHeight;

                if ( top < this.$list[0].scrollTop + 32 || top + $row[0].offsetHeight > this.$list[0].scrollTop + h ) {
                    this.$list[0].scrollTop = Math.max( 0, top - h / 3 );
                }
            }
        }

        this.describe();
    },

    /** The detail: the field the pane or panel is on, nothing at the group names. */
    describe : function () {

        var that = this;
        var f    = this.active && this.active.indexOf( 'g:' ) !== 0
            ? OWA.fieldChooser.find( this.fields, this.active ) : null;

        this.$detail.empty();

        if ( ! f ) {
            return;
        }

        var on = this.options.selected.indexOf( f.name ) !== -1;

        var $head = jQuery( '<div class="owa_fieldDetailHead"></div>' )
            .append( jQuery( '<div class="owa_fieldDetailLabel"></div>' ).text( f.label ) );

        if ( this.sheet ) {
            $head.append( jQuery( '<button type="button" class="owa_fieldMore"></button>' )
                .attr( { 'aria-expanded': this.more ? 'true' : 'false', 'aria-label': this.more ? OWA.l( 'Show less' ) : OWA.util.sprintf( OWA.l( 'More about %s' ), f.label ) } )
                .append( '<i class="fas fa-chevron-down" aria-hidden="true"></i>' )
                .on( 'click', function () {
                    that.more = ! that.more;
                    that.describe();
                } ) );
        }

        this.$detail.append( $head );

        var $badges = jQuery( '<div class="owa_fieldBadges"></div>' )
            .append( jQuery( '<span class="owa_fieldBadge"></span>' ).text( f.group_label || f.group ) );

        if ( OWA.fieldChooser.TYPES[ f.type ] ) {
            $badges.append( jQuery( '<span class="owa_fieldBadge"></span>' ).text( OWA.translate( OWA.fieldChooser.TYPES[ f.type ] ) ) );
        }

        var $secondary = jQuery( '<div class="owa_fieldSecondary"></div>' ).append( $badges );

        if ( f.kind === 'metric' && OWA.fieldChooser.ADDS[ f.type ] ) {
            $secondary.append( jQuery( '<div class="owa_fieldNote"></div>' ).text( OWA.translate( OWA.fieldChooser.ADDS[ f.type ] ) ) );
        }

        if ( this.options.filtering && OWA.dataGrid && OWA.dataGrid.conditionsFor ) {
            $secondary.append( jQuery( '<div class="owa_fieldNote"></div>' ).text( OWA.util.sprintf( OWA.l( 'Filters with: %s' ),
                OWA.dataGrid.conditionsFor( f ).map( function ( c ) { return OWA.translate( c[1] ); } ).join( ', ' ) ) ) );
        }

        $secondary.append( jQuery( '<div class="owa_fieldNote"></div>' ).text( OWA.l( 'API name' ) + ' ' )
            .append( jQuery( '<code></code>' ).text( f.name ) ) );

        if ( f.description ) {
            this.$detail.append( jQuery( '<div class="owa_fieldDescription"></div>' ).text( f.description ) );
        }

        // On a phone the secondary lines fold behind the chevron.
        if ( ! this.sheet || this.more ) {
            this.$detail.append( $secondary );
        }

        if ( on ) {
            this.$detail.append( jQuery( '<div class="owa_fieldNote"></div>' ).text( OWA.l( 'Chosen.' ) ) );
        }
    }
};
