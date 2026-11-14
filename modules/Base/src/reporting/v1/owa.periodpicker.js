/*
 * The report period picker.
 *
 * A button showing the period, and a panel: the predefined periods in a
 * column on the left (Relative, then Fixed: Custom date range), a Compare
 * switch under them with a select of the comparisons, and on the right the
 * date fields above a calendar.
 *
 * A preset's dates come from the server (TimePeriod::getPresets), cut in the
 * installation's timezone. Its fields are read-only; only a Custom date range
 * can be typed into or picked from the calendar. A comparison works the same
 * way: a relative comparison is worked out from the period here, with the
 * arithmetic TimePeriod::compareRange() does on the server.
 *
 * Dates are yyyymmdd strings throughout, the form the server speaks. Date
 * arithmetic is done on UTC midnights, where every day is 24 hours long.
 */

import { OWA } from './owa.js';

const DAY = 86400000;

/** yyyymmdd -> UTC milliseconds, or NaN. */
export function toTime( ymd ) {

    const m = /^(\d{4})(\d{2})(\d{2})$/.exec( String( ymd || '' ) );

    return m ? Date.UTC( +m[1], +m[2] - 1, +m[3] ) : NaN;
}

/** UTC milliseconds -> yyyymmdd. */
export function toYmd( t ) {

    const d = new Date( t );

    return String( d.getUTCFullYear() ).padStart( 4, '0' )
        + String( d.getUTCMonth() + 1 ).padStart( 2, '0' )
        + String( d.getUTCDate() ).padStart( 2, '0' );
}

/** yyyymmdd -> yyyy-mm-dd, the value of an <input type="date">. */
export function toIso( ymd ) {

    return ymd ? ymd.substr( 0, 4 ) + '-' + ymd.substr( 4, 2 ) + '-' + ymd.substr( 6, 2 ) : '';
}

/** yyyy-mm-dd -> yyyymmdd, or '' if it is not a date. */
export function fromIso( iso ) {

    return /^\d{4}-\d{2}-\d{2}$/.test( String( iso || '' ) ) ? iso.replace( /-/g, '' ) : '';
}

function addDays( ymd, n ) {

    return toYmd( toTime( ymd ) + n * DAY );
}

/**
 * The dates a comparison covers. Must agree with TimePeriod::compareRange();
 * tests/fixtures/compare-ranges.json holds the cases both are tested against.
 *
 * @return {string[]|null} [start, end] as yyyymmdd
 */
export function compareRange( key, start, end ) {

    const s = toTime( start ), e = toTime( end );

    if ( isNaN( s ) || isNaN( e ) ) {

        return null;
    }

    const days = Math.round( ( e - s ) / DAY ) + 1;

    switch ( key ) {

        case 'previous_period':
            return [ addDays( start, -days ), addDays( end, -days ) ];

        case 'previous_period_dow': {
            const back = Math.ceil( days / 7 ) * 7;
            return [ addDays( start, -back ), addDays( end, -back ) ];
        }

        case 'previous_year_dow':
            return [ addDays( start, -364 ), addDays( end, -364 ) ];

        case 'previous_year': {
            const year = ( ymd ) => {
                const y = +ymd.substr( 0, 4 ) - 1, m = +ymd.substr( 4, 2 );
                const last = new Date( Date.UTC( y, m, 0 ) ).getUTCDate();
                return toYmd( Date.UTC( y, m - 1, Math.min( +ymd.substr( 6, 2 ), last ) ) );
            };
            return [ year( start ), year( end ) ];
        }
    }

    return null;
}

let dateFormat, monthFormat, dayFormat, longFormat, formatsLocale;

/** The date formats, in the install's locale (OWA.locale()). */
function formats() {

    const locale = OWA.locale();

    if ( ! dateFormat || formatsLocale !== String( locale ) ) {

        dateFormat    = new Intl.DateTimeFormat( locale, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' } );
        longFormat    = new Intl.DateTimeFormat( locale, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' } );
        monthFormat   = new Intl.DateTimeFormat( locale, { month: 'short', year: 'numeric', timeZone: 'UTC' } );
        dayFormat     = new Intl.DateTimeFormat( locale, { weekday: 'narrow', timeZone: 'UTC' } );
        formatsLocale = String( locale );
    }
}

/**
 * The regions whose week does not start on Monday, by first day (0 Sunday,
 * 6 Saturday, 5 Friday): CLDR's firstDay, which Intl.Locale's week
 * information is read from where a browser has it.
 */
const WEEK_STARTS = {
    0: 'AG AS BD BR BS BT BW BZ CA CO DM DO ET GT GU HK HN ID IL IN IS JM JP KE KH KR LA MH MM MO MT MX MZ NI NP PA PE PH PK PR PT PY SA SG SV TH TT TW UM US VE VI WS YE ZA ZW',
    6: 'AF BH DJ DZ EG IQ IR JO KW LY OM QA SD SY',
    5: 'MV',
};

/**
 * The first day of the week in a locale, as Date.getUTCDay() numbers it
 * (0 Sunday, 1 Monday). Intl.Locale's week information where the browser has
 * it; else the locale's region, or the region its language is most likely
 * spoken in (en: US, fr: FR), looked up in WEEK_STARTS. Sunday where Intl
 * cannot say.
 *
 * @param {string|undefined} locale  a language tag; the browser's when undefined
 * @return {number}
 */
export function weekStart( locale ) {

    try {

        const l    = new Intl.Locale( locale || Intl.DateTimeFormat().resolvedOptions().locale );
        const info = typeof l.getWeekInfo === 'function' ? l.getWeekInfo() : l.weekInfo;

        if ( info && info.firstDay >= 1 && info.firstDay <= 7 ) {

            return info.firstDay % 7;
        }

        const region = l.region || ( typeof l.maximize === 'function' ? l.maximize().region : '' );

        if ( region ) {

            const day = Object.keys( WEEK_STARTS ).find( ( d ) => WEEK_STARTS[ d ].split( ' ' ).includes( region ) );

            return day === undefined ? 1 : +day;
        }

    } catch ( e ) {

        // An Intl without Locale, or a tag it refuses.
    }

    return 0;
}

/** "Oct 13, 2026" */
export function formatDate( ymd ) {

    formats();

    return dateFormat.format( new Date( toTime( ymd ) ) );
}

/** "Tuesday, October 13, 2026": a calendar day's name for a screen reader. */
export function formatLongDate( ymd ) {

    formats();

    return longFormat.format( new Date( toTime( ymd ) ) );
}

/** "Oct 13 – Nov 9, 2026", or one date when the range is a single day. */
export function formatRange( start, end ) {

    formats();

    if ( start === end ) {

        return formatDate( start );
    }

    if ( typeof dateFormat.formatRange === 'function' ) {

        return dateFormat.formatRange( new Date( toTime( start ) ), new Date( toTime( end ) ) );
    }

    return formatDate( start ) + ' – ' + formatDate( end );
}

/**
 * A label set inside a sentence: lower-cased in English ("Compared to
 * previous period."), where a label is capitalised only as a heading; as
 * written in any other language, whose capitals may be grammar.
 */
function midSentence( label ) {

    return /^en\b/i.test( OWA.locale() || 'en' ) ? String( label ).toLowerCase() : String( label );
}

function esc( s ) {

    return String( s ).replace( /[&<>"']/g, ( c ) =>
        ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );
}

/**
 * The choice the picker holds, from the server's config.
 *
 * @return {{preset: string, main: string[], cmpOn: boolean, cmpKey: string, cmp: string[]}}
 *   preset is a preset key or 'custom'; cmpKey a relative comparison or 'custom'.
 */
export function initialState( config ) {

    const presets = config.presets || [];
    const known   = presets.some( ( p ) => p.key === config.period );
    const state   = {
        preset: known ? config.period : 'custom',
        main:   [ config.startDate, config.endDate ],
        cmpOn:  !! config.compare,
        // Compare turned on starts on the first comparison offered: Previous period.
        cmpKey: config.compare && config.compare !== 'date_range' ? config.compare
            : ( config.compare ? 'custom' : ( ( config.compares || [] )[0] || { key: 'previous_period' } ).key ),
        cmp:    [ config.compareStartDate || '', config.compareEndDate || '' ],
    };

    return settle( state, presets );
}

/**
 * Bring the dates in line with the choice: a preset's dates are the server's,
 * a relative comparison's are worked out from the period. A Custom date range
 * keeps whatever dates it holds.
 */
export function settle( state, presets ) {

    if ( state.preset !== 'custom' ) {

        const p = presets.find( ( x ) => x.key === state.preset );

        if ( p ) {

            state.main = [ p.startDate, p.endDate ];
        }
    }

    if ( state.cmpKey !== 'custom' || ! state.cmp[0] || ! state.cmp[1] ) {

        state.cmp = compareRange( state.cmpKey !== 'custom' ? state.cmpKey : 'previous_period', state.main[0], state.main[1] ) || [ '', '' ];
    }

    return state;
}

/**
 * The request parameters a state stands for. A preset is `period`; a Custom
 * date range is startDate and endDate with no period. A comparison is
 * `compare`, plus compareStartDate and compareEndDate when it is a Custom
 * date range. null removes a parameter.
 */
export function toParams( state ) {

    const custom    = state.preset === 'custom';
    const cmpCustom = state.cmpOn && state.cmpKey === 'custom';

    return {
        period:           custom ? null : state.preset,
        startDate:        custom ? state.main[0] : null,
        endDate:          custom ? state.main[1] : null,
        compare:          state.cmpOn ? ( cmpCustom ? 'date_range' : state.cmpKey ) : null,
        compareStartDate: cmpCustom ? state.cmp[0] : null,
        compareEndDate:   cmpCustom ? state.cmp[1] : null,
    };
}

/**
 * Which pair of dates the calendar edits: the one last focused if it is a
 * Custom date range, else whichever is; null when neither is.
 */
export function calendarTarget( state, focus ) {

    const p = state.preset === 'custom';
    const c = state.cmpOn && state.cmpKey === 'custom';

    if ( focus === 'cmp' && c ) return 'cmp';
    if ( focus === 'main' && p ) return 'main';

    return p ? 'main' : ( c ? 'cmp' : null );
}

/**
 * A click on a day. The first click starts a range on that day, the second
 * ends it, in either order.
 *
 * @param {number} edge which date the click sets: 0 the start, 1 the end
 * @return {number} which date the next click sets
 */
export function pickDay( state, target, ymd, edge ) {

    if ( ! target ) {

        return edge;
    }

    const r = state[ target ].slice();

    // The start; the end moves with it only if it would now be before it.
    if ( edge === 0 ) {

        r[0] = ymd;

        if ( r[1] < ymd ) r[1] = ymd;

        state[ target ] = r;

        return 1;
    }

    // The end, which cannot be before the start (those days are disabled
    // while an end is being picked; this guards the state as well).
    if ( ymd < r[0] ) {

        return 1;
    }

    r[1] = ymd;
    state[ target ] = r;

    return 0;
}

function validYmd( y, m, d ) {

    const t = Date.UTC( y, m - 1, d );
    const x = new Date( t );

    return y >= 1990 && x.getUTCFullYear() === y && x.getUTCMonth() === m - 1 && x.getUTCDate() === d ? toYmd( t ) : '';
}

/**
 * A date written the way a locale writes one, as yyyymmdd; '' if it is not
 * one. A month named in the locale ("13 oct. 2026", "Oct 13, 2026") with a
 * day and a four-digit year; or three numbers in the order the locale writes
 * a numeric date ("13/10/2026", "10/13/2026", "13.10.2026").
 */
function localDate( t, locale ) {

    const lower = t.toLocaleLowerCase( locale );
    const nums  = lower.match( /\d+/g ) || [];
    let month = 0, name = '';

    for ( let m = 1; m <= 12; m++ ) {

        for ( const style of [ 'long', 'short' ] ) {

            const n = new Intl.DateTimeFormat( locale, { month: style, timeZone: 'UTC' } )
                .format( new Date( Date.UTC( 2000, m - 1, 1 ) ) ).toLocaleLowerCase( locale ).replace( /\.$/, '' );

            // The longest name found: "june", not "jun".
            if ( n && ! /\d/.test( n ) && lower.includes( n ) && n.length > name.length ) {

                name  = n;
                month = m;
            }
        }
    }

    if ( month ) {

        const day  = nums.find( ( n ) => n.length <= 2 );
        const year = nums.find( ( n ) => n.length === 4 );

        return day && year ? validYmd( +year, month, +day ) : '';
    }

    if ( nums.length !== 3 ) {

        return '';
    }

    const v = {};

    new Intl.DateTimeFormat( locale, { day: 'numeric', month: 'numeric', year: 'numeric', timeZone: 'UTC' } )
        .formatToParts( new Date( 0 ) )
        .filter( ( p ) => p.type === 'day' || p.type === 'month' || p.type === 'year' )
        .forEach( ( p, i ) => { v[ p.type ] = +nums[ i ]; } );

    return v.year >= 1000 ? validYmd( v.year, v.month, v.day ) : '';
}

/**
 * A typed date as yyyymmdd, or '' if it is not one. Takes 2026-10-13,
 * 20261013, a date as the install's locale writes it (the fields show
 * formatDate()), and what the browser's Date reads.
 */
export function parseDateInput( text ) {

    const t = String( text || '' ).trim();
    let m;

    if ( ( m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec( t ) ) ) return validYmd( +m[1], +m[2], +m[3] );
    if ( ( m = /^(\d{4})(\d{2})(\d{2})$/.exec( t ) ) ) return validYmd( +m[1], +m[2], +m[3] );

    const local = t ? localDate( t, OWA.locale() ) : '';

    if ( local ) {

        return local;
    }

    const d = t ? new Date( t ) : null;

    return d && ! isNaN( d ) ? validYmd( d.getFullYear(), d.getMonth() + 1, d.getDate() ) : '';
}

/**
 * @param {Element} el         where the button goes
 * @param {object}  config     ReportController::periodPickerConfig()
 * @param {function(object)} onApply  given toParams() of the chosen state
 */
export function PeriodPicker( el, config, onApply ) {

    this.el       = el;
    this.config   = config || {};
    this.presets  = this.config.presets || [];
    this.compares = this.config.compares || [];
    this.today    = this.config.today || toYmd( Date.now() );
    this.onApply  = onApply || function () {};
    this.applied  = initialState( this.config );
    this.draft    = null;
    this.focus    = 'main';
    this.edge     = 0;
    this.badInput = '';
    this.page     = '';
    this.active   = '';
    this.uid      = 'owaPeriod' + ( ++PeriodPicker.seq );
    this.weekStart = weekStart( OWA.locale() );

    this.render();

    this._onDoc = ( e ) => {

        if ( this.draft && ! this.el.contains( e.target ) ) {

            this.close();
        }
    };

    el.addEventListener( 'click', ( e ) => this.onClick( e ) );
    el.addEventListener( 'change', ( e ) => this.onChange( e ) );
    el.addEventListener( 'focusin', ( e ) => this.onFocus( e ) );
    el.addEventListener( 'keydown', ( e ) => {

        if ( e.key === 'Escape' && this.draft ) {

            this.close();
        }

        // Enter commits a typed date, and the field keeps the focus.
        if ( e.key === 'Enter' && e.target.matches && e.target.matches( '[data-in]' ) ) {

            e.preventDefault();
            this.onChange( e );
        }

        if ( e.target.matches && e.target.matches( '.owa_periodDay[data-act="day"]' ) ) {

            this.onDayKey( e );
        }
    } );
}

PeriodPicker.seq = 0;

PeriodPicker.prototype = {

    presetLabel( key ) {

        const p = this.presets.find( ( x ) => x.key === key );

        return p ? p.label : '';
    },

    compareLabel( key ) {

        const c = this.compares.find( ( x ) => x.key === key );

        return c ? c.label : '';
    },

    open() {

        this.draft    = JSON.parse( JSON.stringify( this.applied ) );
        this.focus    = 'main';
        this.edge     = 0;
        this.badInput = '';
        this.active   = '';

        document.addEventListener( 'mousedown', this._onDoc );
        document.body.classList.add( 'owa_periodOpen' );

        this.render( true );
    },

    close( refocus = true ) {

        this.draft    = null;
        this.edge     = 0;
        this.badInput = '';

        document.removeEventListener( 'mousedown', this._onDoc );
        document.body.classList.remove( 'owa_periodOpen' );

        this.render();

        if ( refocus ) {

            const t = this.el.querySelector( '.owa_periodTrigger' );

            if ( t ) t.focus();
        }
    },

    apply() {

        this.applied = this.draft;
        this.close( false );
        this.onApply( toParams( this.applied ) );
    },

    // ----------------------------------------------------------------- render

    triggerHtml() {

        const a = this.applied;
        const badge = a.preset !== 'custom' ? '<span class="owa_periodBadge">' + esc( this.presetLabel( a.preset ) ) + '</span>' : '';

        return '<button type="button" class="owa_periodTrigger" data-act="toggle" aria-haspopup="dialog" aria-expanded="' + ( this.draft ? 'true' : 'false' ) + '" aria-controls="' + this.uid + '">'
            + '<span class="owa_periodTriggerMain">' + badge + '<span class="owa_periodTriggerRange">' + esc( formatRange( a.main[0], a.main[1] ) ) + '</span></span>'
            + ( a.cmpOn && a.cmp[0] ? '<span class="owa_periodTriggerCompare">' + esc( OWA.util.sprintf( OWA.l( 'Compare: %s' ), formatRange( a.cmp[0], a.cmp[1] ) ) ) + '</span>' : '' )
            + '<span class="owa_periodChevron" aria-hidden="true"></span></button>';
    },

    listHtml() {

        const d = this.draft;
        const item = ( act, key, on, label ) =>
            '<li><button type="button" data-act="' + act + '" data-k="' + key + '" aria-pressed="' + on + '">' + esc( label ) + '</button></li>';

        let h = '<ul class="owa_periodList"><li class="owa_periodGroup">' + OWA.l( 'Relative <small>to today</small>' ) + '</li>';

        this.presets.forEach( ( p ) => { h += item( 'preset', p.key, d.preset === p.key, p.label ); } );

        h += '<li class="owa_periodGroup">' + OWA.l( 'Fixed <small>dates you choose</small>' ) + '</li>'
            + item( 'preset', 'custom', d.preset === 'custom', OWA.l( 'Custom date range' ) )
            + '<li class="owa_periodRule" role="separator"></li>'
            + '<li>' + this.switchHtml( 'l' ) + '</li>';

        // One select, not a second list: the list made the panel as tall as both.
        if ( d.cmpOn ) {

            h += '<li class="owa_periodCmpRow">' + this.compareSelectHtml() + '</li>';
        }

        return h + '</ul>';
    },

    /** The comparisons, and Custom date range, as a select under the Compare switch. */
    compareSelectHtml() {

        const d = this.draft;
        const option = ( key, label ) => '<option value="' + esc( key ) + '"' + ( d.cmpKey === key ? ' selected' : '' ) + '>' + esc( label ) + '</option>';

        return '<select class="owa_periodCmpSelect" data-cmp-select aria-label="' + esc( OWA.l( 'Compared to' ) ) + '">'
            + this.compares.map( ( c ) => option( c.key, c.label ) ).join( '' )
            + option( 'custom', OWA.l( 'Custom date range' ) ) + '</select>';
    },

    switchHtml( where ) {

        const id = this.uid + where;

        return '<div class="owa_periodSwitchRow"><span id="' + id + '">' + esc( OWA.l( 'Compare' ) ) + '</span>'
            + '<button type="button" class="owa_periodSwitch" role="switch" aria-labelledby="' + id + '" aria-checked="' + this.draft.cmpOn + '" data-act="cmpToggle"></button></div>';
    },

    chipsHtml( cmp ) {

        const d = this.draft;
        const chip = ( act, key, on, label ) =>
            '<button type="button" data-act="' + act + '" data-k="' + key + '" aria-pressed="' + on + '">' + esc( label ) + '</button>';

        if ( cmp ) {

            return '<div class="owa_periodChips owa_periodChipsCmp">'
                + this.compares.map( ( c ) => chip( 'cmpKey', c.key, d.cmpKey === c.key, c.label ) ).join( '' )
                + chip( 'cmpKey', 'custom', d.cmpKey === 'custom', OWA.l( 'Custom date range' ) ) + '</div>';
        }

        return '<div class="owa_periodChips">'
            + this.presets.map( ( p ) => chip( 'preset', p.key, d.preset === p.key, p.label ) ).join( '' )
            + chip( 'preset', 'custom', d.preset === 'custom', OWA.l( 'Custom date range' ) ) + '</div>';
    },

    fieldHtml( which, i, ymd, editable, outlined ) {

        const label = esc( i === 0 ? OWA.l( 'Start date' ) : OWA.l( 'End date' ) );
        const cls   = 'owa_periodField' + ( which === 'cmp' ? ' owa_periodFieldCmp' : '' ) + ( editable ? ( outlined ? ' is-target' : '' ) : ' is-readonly' );

        // A preset's date is text, not a read-only input: nothing to focus,
        // so a click on it draws no focus ring on a field that cannot change.
        if ( ! editable ) {

            return '<div class="' + cls + '"><span>' + label + '</span><span class="owa_periodValue" data-out="' + which + i + '">' + esc( formatDate( ymd ) ) + '</span></div>';
        }

        // A text field, not <input type="date">: a date input brings the
        // browser's own pop-up calendar, a second calendar beside this one.
        return '<label class="' + cls + '" data-f="' + which + i + '"><span>' + label + '</span>'
            + '<input type="text" autocomplete="off" spellcheck="false" data-in="' + which + i + '" value="' + esc( formatDate( ymd ) ) + '"></label>';
    },

    fieldsHtml( target ) {

        const d = this.draft;
        const pEd = d.preset === 'custom';
        const cEd = d.cmpKey === 'custom';
        const kind = ( fixed ) => '<span class="owa_periodKind">' + esc( fixed ? OWA.l( 'Fixed period' ) : OWA.l( 'Relative period' ) ) + '</span>';

        let h = '<div class="owa_periodFields">'
            + '<div class="owa_periodLabel"><i aria-hidden="true"></i>' + esc( OWA.l( 'Date range' ) ) + kind( pEd ) + '</div>'
            + '<div class="owa_periodFieldRow">' + this.fieldHtml( 'main', 0, d.main[0], pEd, target === 'main' && this.edge === 0 ) + '<span aria-hidden="true">–</span>' + this.fieldHtml( 'main', 1, d.main[1], pEd, target === 'main' && this.edge === 1 ) + '</div>'
            + '<div class="owa_periodPhoneCompare">' + this.switchHtml( 'p' ) + ( d.cmpOn ? this.chipsHtml( true ) : '' ) + '</div>';

        if ( d.cmpOn ) {

            h += '<div class="owa_periodLabel owa_periodLabelCmp"><i aria-hidden="true"></i>' + esc( OWA.l( 'Compared to' ) ) + kind( cEd ) + '</div>'
                + '<div class="owa_periodFieldRow">' + this.fieldHtml( 'cmp', 0, d.cmp[0], cEd, target === 'cmp' && this.edge === 0 ) + '<span aria-hidden="true">–</span>' + this.fieldHtml( 'cmp', 1, d.cmp[1], cEd, target === 'cmp' && this.edge === 1 ) + '</div>';
        }

        return h + '</div>';
    },

    calendarHtml( target ) {

        const d = this.draft;
        const r = d.main, c = d.cmpOn ? d.cmp : null;

        // From a year before the earliest date shown to this month.
        const earliest = [ r[0], c ? c[0] : r[0], this.today ].sort()[0];
        let y = +earliest.substr( 0, 4 ) - 1, m = +earliest.substr( 4, 2 ) - 1;
        const endY = +this.today.substr( 0, 4 ), endM = +this.today.substr( 4, 2 ) - 1;
        const page = this.page || this.today.substr( 0, 6 );

        formats();

        let wk = '';

        for ( let i = 0; i < 7; i++ ) {

            // 4 January 1970 was a Sunday.
            wk += '<span>' + esc( dayFormat.format( new Date( Date.UTC( 1970, 0, 4 + this.weekStart + i ) ) ) ) + '</span>';
        }

        const pageT = Date.UTC( +page.substr( 0, 4 ), +page.substr( 4, 2 ) - 1, 1 );
        const first = Date.UTC( y, m, 1 ), last = Date.UTC( endY, endM, 1 );
        const roving = target ? this.rovingDay( target, first ) : '';

        let h = '<div class="owa_periodCal' + ( target ? '' : ' is-readonly' ) + '">'
            + '<div class="owa_periodCalNav"><button type="button" data-act="mprev" aria-label="' + esc( OWA.l( 'Previous month' ) ) + '"' + ( pageT <= first ? ' disabled' : '' ) + '>‹</button>'
            + '<span>' + esc( monthFormat.format( new Date( pageT ) ) ) + '</span>'
            + '<button type="button" data-act="mnext" aria-label="' + esc( OWA.l( 'Next month' ) ) + '"' + ( pageT >= last ? ' disabled' : '' ) + '>›</button></div>'
            + '<div class="owa_periodCalWeek">' + wk + '</div><div class="owa_periodCalMonths">';

        while ( y < endY || ( y === endY && m <= endM ) ) {

            const key   = String( y ) + String( m + 1 ).padStart( 2, '0' );
            const start = Date.UTC( y, m, 1 );
            const days  = new Date( Date.UTC( y, m + 1, 0 ) ).getUTCDate();

            h += '<div class="owa_periodMonth' + ( key === page ? ' is-page' : '' ) + '" data-month="' + key + '">'
                + '<div class="owa_periodMonthName">' + esc( monthFormat.format( new Date( start ) ) ) + '</div><div class="owa_periodDays">';

            for ( let b = 0; b < ( new Date( start ).getUTCDay() - this.weekStart + 7 ) % 7; b++ ) {

                h += '<span></span>';
            }

            for ( let i = 1; i <= days; i++ ) {

                const ymd = key + String( i ).padStart( 2, '0' );

                h += '<button type="button" class="' + this.dayClass( ymd ) + '" data-d="' + ymd + '"'
                    + ( target ? ' data-act="day" tabindex="' + ( ymd === roving ? '0' : '-1' ) + '"' : ' tabindex="-1" aria-disabled="true"' )
                    + ( this.dayDisabled( ymd, target ) ? ' disabled' : '' )
                    + ' aria-pressed="' + this.dayPressed( ymd ) + '"'
                    + ' aria-label="' + esc( this.dayLabel( ymd ) ) + '"><span>' + i + '</span></button>';
            }

            h += '</div></div>';

            m++;

            if ( m > 11 ) {

                m = 0;
                y++;
            }
        }

        return h + '</div></div>';
    },

    /**
     * A day that cannot be clicked: after today, or, while an end date is
     * being picked, before the start date.
     */
    dayDisabled( ymd, target ) {

        return ymd > this.today || ( !! target && this.edge === 1 && ymd < this.draft[ target ][0] );
    },

    /** A day's classes: where it falls in the range and the comparison. */
    dayClass( ymd ) {

        const r = this.draft.main, c = this.draft.cmpOn ? this.draft.cmp : null;
        const cl = [ 'owa_periodDay' ];

        if ( ymd >= r[0] && ymd <= r[1] ) cl.push( 'in-r' );
        if ( ymd === r[0] ) cl.push( 'r-start' );
        if ( ymd === r[1] ) cl.push( 'r-end' );
        if ( c && ymd >= c[0] && ymd <= c[1] ) cl.push( 'in-c' );
        if ( c && ymd === c[0] ) cl.push( 'c-start' );
        if ( c && ymd === c[1] ) cl.push( 'c-end' );
        if ( ymd === this.today ) cl.push( 'is-today' );

        return cl.join( ' ' );
    },

    /** A day is pressed when it is in the date range. */
    dayPressed( ymd ) {

        const r = this.draft.main;

        return ymd >= r[0] && ymd <= r[1];
    },

    /** A day's name: its full date, and where it falls in the range and the comparison. */
    dayLabel( ymd ) {

        const r = this.draft.main, c = this.draft.cmpOn ? this.draft.cmp : null;
        const said = [ formatLongDate( ymd ) ];

        if ( ymd === r[0] ) said.push( OWA.l( 'start date', 'calendar day' ) );
        if ( ymd === r[1] ) said.push( OWA.l( 'end date', 'calendar day' ) );

        if ( c && ymd === c[0] ) said.push( OWA.l( 'comparison start date', 'calendar day' ) );
        else if ( c && ymd === c[1] ) said.push( OWA.l( 'comparison end date', 'calendar day' ) );
        else if ( c && ymd > c[0] && ymd < c[1] ) said.push( OWA.l( 'in the comparison', 'calendar day' ) );

        if ( ymd === this.today ) said.push( OWA.l( 'today', 'calendar day' ) );

        return said.join( ', ' );
    },

    /**
     * The one day of the calendar in the tab order: the one last clicked or
     * moved to while it can be picked; else the date the next click sets,
     * the range's own dates or today -- the first of them that can be picked
     * and is in the month showing, then the first that can be picked at all.
     *
     * @param {string} target  the range being edited
     * @param {number} first   UTC time of the first month drawn
     * @return {string} yyyymmdd, or '' when no day can be picked
     */
    rovingDay( target, first ) {

        const d = this.draft[ target ];
        const page = this.page || this.today.substr( 0, 6 );
        const drawn = ( ymd ) => !! ymd && toTime( ymd ) >= first && ymd <= this.today;
        const ok = ( ymd ) => drawn( ymd ) && ! this.dayDisabled( ymd, target );

        if ( ok( this.active ) ) {

            return this.active;
        }

        const want = [ d[ this.edge ], d[0], d[1], this.today ].filter( ok );

        return want.find( ( ymd ) => ymd.substr( 0, 6 ) === page ) || want[0] || '';
    },

    /** Put the calendar's one tab stop on rovingDay(), without drawing it again. */
    setRoving( target ) {

        const days = this.el.querySelectorAll( '.owa_periodDay[data-act="day"]' );

        if ( ! days.length ) {

            return;
        }

        const first = toTime( days[0].getAttribute( 'data-d' ) );
        const roving = this.rovingDay( target, first );

        days.forEach( ( b ) => b.setAttribute( 'tabindex', b.getAttribute( 'data-d' ) === roving ? '0' : '-1' ) );
    },

    /**
     * The arrow keys move between days: a day either side, a week up or down,
     * Home and End to the week's ends, Page Up and Page Down a month. A day
     * that cannot be picked is not moved to.
     */
    onDayKey( e ) {

        const from = e.target.getAttribute( 'data-d' );
        const t = toTime( from );
        // The day's place in the week, which starts on the locale's first day.
        const dow = ( new Date( t ).getUTCDay() - this.weekStart + 7 ) % 7;
        let to = null;

        switch ( e.key ) {

            case 'ArrowLeft':  to = addDays( from, -1 ); break;
            case 'ArrowRight': to = addDays( from, 1 ); break;
            case 'ArrowUp':    to = addDays( from, -7 ); break;
            case 'ArrowDown':  to = addDays( from, 7 ); break;
            case 'Home':       to = addDays( from, -dow ); break;
            case 'End':        to = addDays( from, 6 - dow ); break;
            case 'PageUp':
            case 'PageDown': {
                const x = new Date( t );
                const m = x.getUTCMonth() + ( e.key === 'PageUp' ? -1 : 1 );
                const last = new Date( Date.UTC( x.getUTCFullYear(), m + 1, 0 ) ).getUTCDate();
                to = toYmd( Date.UTC( x.getUTCFullYear(), m, Math.min( x.getUTCDate(), last ) ) );
                break;
            }
            default:
                return;
        }

        e.preventDefault();

        const target = calendarTarget( this.draft, this.focus );
        let day = this.el.querySelector( '.owa_periodDay[data-d="' + to + '"]' );

        if ( ! day || day.disabled || this.dayDisabled( to, target ) ) {

            return;
        }

        this.active = to;

        // Another month: on a phone only the page's month shows, so draw it.
        if ( to.substr( 0, 6 ) !== ( this.page || this.today.substr( 0, 6 ) ) ) {

            this.page = to.substr( 0, 6 );
            this.render();
            day = this.el.querySelector( '.owa_periodDay[data-d="' + to + '"]' );
        }

        this.setRoving( target );

        if ( day ) day.focus();
    },

    whyHtml( target ) {

        const d = this.draft;
        const pEd = d.preset === 'custom';
        const cEd = d.cmpOn && d.cmpKey === 'custom';

        if ( this.badInput ) {

            return esc( this.badInput );
        }

        const bold = ( text ) => '<b>' + esc( text ) + '</b>';

        // Whole sentences, joined by a space.
        const said = [ pEd ? bold( OWA.l( 'Fixed dates.' ) )
            : OWA.util.sprintf( OWA.l( '%s, counted from today.' ), bold( this.presetLabel( d.preset ) ) ) ];

        if ( d.cmpOn ) {

            said.push( cEd ? OWA.util.sprintf( OWA.l( 'Compared to %s.' ), bold( OWA.l( 'fixed dates' ) ) )
                : OWA.util.sprintf( OWA.l( 'Compared to %s.' ), bold( midSentence( this.compareLabel( d.cmpKey ) ) ) ) );
        }

        if ( target ) {

            said.push( esc( this.edge === 0 ? OWA.l( 'Click a day to set the start date.' ) : OWA.l( 'Click a day to set the end date.' ) ) );

        } else {

            said.push( esc( OWA.l( 'Pick Custom date range to choose dates.' ) ) );
        }

        return said.join( ' ' );
    },

    panelHtml() {

        const target = calendarTarget( this.draft, this.focus );

        return '<div class="owa_periodPanel" id="' + this.uid + '" role="dialog" aria-label="' + esc( OWA.l( 'Date range' ) ) + '" tabindex="-1">'
            + '<div class="owa_periodSheetHead">' + esc( OWA.l( 'Date range' ) ) + '<button type="button" data-act="cancel" aria-label="' + esc( OWA.l( 'Close' ) ) + '">×</button></div>'
            + '<div class="owa_periodBody"><div class="owa_periodLeft">' + this.listHtml() + '</div>'
            + '<div class="owa_periodRight">' + this.chipsHtml( false ) + this.fieldsHtml( target ) + this.calendarHtml( target ) + '</div></div>'
            + '<div class="owa_periodFoot"><div class="owa_periodWhy" aria-live="polite">' + this.whyHtml( target ) + '</div>'
            + '<div class="owa_periodButtons"><button type="button" class="owa_periodBtn" data-act="cancel">' + esc( OWA.l( 'Cancel' ) ) + '</button>'
            + '<button type="button" class="owa_periodBtn is-primary" data-act="apply">' + esc( OWA.l( 'Apply' ) ) + '</button></div></div></div>';
    },

    /**
     * Draw the button, and the panel when open. jump moves the calendar to
     * the range being edited; otherwise its scroll position is kept.
     */
    render( jump ) {

        const months = this.el.querySelector( '.owa_periodCalMonths' );
        const top    = months ? months.scrollTop : null;
        const body   = this.el.querySelector( '.owa_periodBody' );
        const btop   = body ? body.scrollTop : null;
        const chips  = Array.from( this.el.querySelectorAll( '.owa_periodChips' ) ).map( ( c ) => c.scrollLeft );

        if ( jump && this.draft ) {

            const t = calendarTarget( this.draft, this.focus );

            this.page = ( t === 'cmp' ? this.draft.cmp[1] : this.draft.main[1] ).substr( 0, 6 );
        }

        const had = this.focusedControl();

        this.el.innerHTML = this.triggerHtml() + ( this.draft ? this.panelHtml() : '' );

        if ( ! this.draft ) {

            return;
        }

        const nm = this.el.querySelector( '.owa_periodCalMonths' );

        if ( nm ) {

            if ( jump || top === null ) {

                this.scrollCalendarTo( calendarTarget( this.draft, this.focus ) === 'cmp' ? this.draft.cmp[1] : this.draft.main[1] );

            } else {

                nm.scrollTop = top;
            }
        }

        const nb = this.el.querySelector( '.owa_periodBody' );

        if ( nb && btop !== null ) nb.scrollTop = btop;

        this.el.querySelectorAll( '.owa_periodChips' ).forEach( ( c, i ) => {

            if ( chips[ i ] !== undefined ) {

                c.scrollLeft = chips[ i ];

            } else {

                const on = c.querySelector( '[aria-pressed="true"]' );

                if ( on ) c.scrollLeft = on.offsetLeft - 16;
            }
        } );

        if ( had ) this.restoreFocus( had );
    },

    /**
     * The control holding the keyboard focus, as what finds its equivalent
     * once the panel is drawn again: a selector and which match it was.
     *
     * @return {{sel: string, i: number}|null}
     */
    focusedControl() {

        const a = document.activeElement;

        if ( ! a || ! this.el.contains( a ) ) {

            return null;
        }

        let sel = null;

        if ( a.hasAttribute( 'data-d' ) ) {

            sel = '[data-d="' + a.getAttribute( 'data-d' ) + '"]';

        } else if ( a.hasAttribute( 'data-act' ) ) {

            sel = '[data-act="' + a.getAttribute( 'data-act' ) + '"]'
                + ( a.hasAttribute( 'data-k' ) ? '[data-k="' + a.getAttribute( 'data-k' ) + '"]' : '' );

        } else if ( a.hasAttribute( 'data-in' ) ) {

            sel = '[data-in="' + a.getAttribute( 'data-in' ) + '"]';

        } else if ( a.hasAttribute( 'data-cmp-select' ) ) {

            sel = '[data-cmp-select]';
        }

        return sel ? { sel: sel, i: Array.from( this.el.querySelectorAll( sel ) ).indexOf( a ) } : null;
    },

    /**
     * Focus the control that stands where the focused one stood. One that is
     * gone or disabled (the first month's Previous) leaves the focus on the
     * other month button, or on the panel.
     */
    restoreFocus( had ) {

        const all = this.el.querySelectorAll( had.sel );
        let el = all[ had.i ] || all[0] || null;

        if ( ! el || el.disabled ) {

            el = /mprev|mnext/.test( had.sel )
                ? this.el.querySelector( '.owa_periodCalNav button:not(:disabled)' )
                : null;
        }

        el = el || this.el.querySelector( '.owa_periodPanel' );

        if ( el ) el.focus( { preventScroll: true } );
    },

    /** Scroll the calendar so the week holding ymd is its bottom row. */
    scrollCalendarTo( ymd ) {

        const box = this.el.querySelector( '.owa_periodCalMonths' );

        if ( ! box || box.scrollHeight <= box.clientHeight ) {

            return;
        }

        const day = box.querySelector( '[data-d="' + ymd + '"]' );

        if ( day ) {

            box.scrollTop = Math.max( 0, box.scrollTop + day.getBoundingClientRect().bottom - box.getBoundingClientRect().bottom + 6 );
        }
    },

    // ----------------------------------------------------------------- events

    onClick( e ) {

        const el = e.target.closest( '[data-act]' );

        if ( ! el || ! this.el.contains( el ) ) {

            return;
        }

        const act = el.getAttribute( 'data-act' );
        const k   = el.getAttribute( 'data-k' );
        const d   = this.draft;
        let jump  = false;

        switch ( act ) {

            case 'toggle':
                if ( d ) this.close(); else this.open();
                return;

            case 'cancel':
                this.close();
                return;

            case 'apply':
                this.apply();
                return;

            case 'preset':
                d.preset = k;
                this.focus = 'main';
                jump = true;
                break;

            case 'cmpToggle':
                d.cmpOn = ! d.cmpOn;
                break;

            case 'cmpKey':
                this.chooseCompare( k );
                return;

            case 'day':
                this.active = el.getAttribute( 'data-d' );
                this.edge = pickDay( d, calendarTarget( d, this.focus ), el.getAttribute( 'data-d' ), this.edge );
                this.badInput = '';
                settle( d, this.presets );
                this.refresh();
                return;

            case 'mprev':
            case 'mnext': {
                const y = +this.page.substr( 0, 4 ), m = +this.page.substr( 4, 2 ) - 1 + ( act === 'mprev' ? -1 : 1 );
                this.page = toYmd( Date.UTC( y, m, 1 ) ).substr( 0, 6 );
                this.render();
                return;
            }

            default:
                return;
        }

        // Choosing a period or comparison starts the calendar on its start date.
        this.edge     = 0;
        this.badInput = '';
        settle( d, this.presets );
        this.render( jump );
    },

    /** A comparison chosen: its dates, and the calendar starts on them. */
    chooseCompare( k ) {

        const d = this.draft;

        d.cmpKey      = k;
        this.focus    = k === 'custom' ? 'cmp' : 'main';
        this.edge     = 0;
        this.badInput = '';
        settle( d, this.presets );
        this.render( true );
    },

    onChange( e ) {

        if ( e.target.matches && e.target.matches( '[data-cmp-select]' ) && this.draft ) {

            this.chooseCompare( e.target.value );

            return;
        }

        const el = e.target.closest( '[data-in]' );

        if ( ! el || ! this.draft ) {

            return;
        }

        const k    = el.getAttribute( 'data-in' );
        const key  = k.indexOf( 'cmp' ) === 0 ? 'cmp' : 'main';
        const i    = +k.slice( -1 );
        const ymd  = parseDateInput( el.value );
        const r    = this.draft[ key ].slice();
        const text = el.value.trim();

        this.focus = key;

        if ( ! ymd ) {

            this.badInput = OWA.util.sprintf( OWA.l( 'Couldn’t read “%1$s” as a date. Try a date like %2$s.' ), text, formatDate( this.today ) );

        } else if ( ymd > this.today ) {

            this.badInput = OWA.util.sprintf( OWA.l( '%s is after today.' ), formatDate( ymd ) );

        } else if ( i === 1 && ymd < r[0] ) {

            this.badInput = OWA.l( 'The end date can’t be before the start date.' );

        } else {

            r[ i ] = ymd;

            // A start after the end moves the end with it.
            if ( r[0] > r[1] ) r[1] = ymd;

            this.draft[ key ] = r;
            this.badInput = '';
            this.edge = i === 0 ? 1 : 0;
            settle( this.draft, this.presets );
        }

        // Back to what the field holds; a refused date is named in the footer.
        el.value = formatDate( this.draft[ key ][ i ] );

        if ( ! this.badInput && ! this.el.querySelector( '[data-d="' + ymd + '"]' ) ) {

            // A date before the first month drawn: draw the calendar again.
            this.page = ymd.substr( 0, 6 );
            this.render( true );

            return;
        }

        this.refresh();

        if ( ! this.badInput ) {

            this.page = ymd.substr( 0, 6 );

            // Only when the day is out of sight. The change often comes from
            // the mousedown on a day; scrolling then would move the days
            // under the pointer and the click would land on another one.
            if ( ! this.dayInView( ymd ) ) {

                this.scrollCalendarTo( ymd );
            }
        }
    },

    /** Is the day on screen in the calendar's scrolling box? */
    dayInView( ymd ) {

        const box = this.el.querySelector( '.owa_periodCalMonths' );
        const day = box && box.querySelector( '[data-d="' + ymd + '"]' );

        if ( ! day ) {

            return false;
        }

        const b = box.getBoundingClientRect(), r = day.getBoundingClientRect();

        return r.top >= b.top && r.bottom <= b.bottom;
    },

    /** Clicking into a field picks which date the next day clicked sets. */
    onFocus( e ) {

        const el = e.target.closest && e.target.closest( '[data-in]' );

        if ( ! el || ! this.draft ) {

            return;
        }

        const k = el.getAttribute( 'data-in' );

        this.focus = k.indexOf( 'cmp' ) === 0 ? 'cmp' : 'main';
        this.edge  = +k.slice( -1 );

        this.refresh();
    },

    /**
     * Bring the open panel in line with the draft without drawing it again:
     * the days' classes and which can be clicked, the dates in the fields,
     * which field the next click sets, and the line beside the buttons.
     *
     * Not a render: a typed date is committed when its field loses focus,
     * which is often the mousedown on a day. Redrawing then would replace
     * that day before its click arrived, and the click would be lost.
     */
    refresh() {

        if ( ! this.draft ) {

            return;
        }

        const d      = this.draft;
        const target = calendarTarget( d, this.focus );

        this.el.querySelectorAll( '.owa_periodDay' ).forEach( ( b ) => {

            const ymd = b.getAttribute( 'data-d' );

            b.className = this.dayClass( ymd );
            b.disabled  = this.dayDisabled( ymd, target );
            b.setAttribute( 'aria-pressed', String( this.dayPressed( ymd ) ) );
            b.setAttribute( 'aria-label', this.dayLabel( ymd ) );
        } );

        this.setRoving( target );

        this.el.querySelectorAll( '[data-in]' ).forEach( ( input ) => {

            const k = input.getAttribute( 'data-in' );

            if ( input !== document.activeElement ) {

                input.value = formatDate( d[ k.slice( 0, -1 ) ][ +k.slice( -1 ) ] );
            }
        } );

        this.el.querySelectorAll( '[data-out]' ).forEach( ( out ) => {

            const k = out.getAttribute( 'data-out' );

            out.textContent = formatDate( d[ k.slice( 0, -1 ) ][ +k.slice( -1 ) ] );
        } );

        this.el.querySelectorAll( '[data-f]' ).forEach( ( f ) => {

            f.classList.toggle( 'is-target', f.getAttribute( 'data-f' ) === target + this.edge );
        } );

        const why = this.el.querySelector( '.owa_periodWhy' );

        if ( why ) why.innerHTML = this.whyHtml( target );
    },
};



/**
 * How a compared metric reads: the change and its direction, and the
 * comparison's own value with its dates for a title. One wording for the
 * metric boxes and the grid cells.
 *
 * @param {object} cell     a metric cell with compare_value,
 *                          compare_formatted_value and change (CompareMerger)
 * @param {object} compare  the result set's compare: startDate, endDate
 * @return {{cls: string, text: string, title: string}}
 */
export function describeChange( cell, compare ) {

    const range = formatRange( compare.startDate, compare.endDate );
    const ch    = cell.change;
    let cls     = 'is-flat';
    let text    = '—';

    if ( ch !== null && ch !== undefined && isFinite( ch ) ) {

        cls  = ch > 0 ? 'is-up' : ( ch < 0 ? 'is-down' : 'is-flat' );
        text = ( ch > 0 ? '▲ ' : ( ch < 0 ? '▼ ' : '' ) ) + OWA.util.formatPercent( Math.abs( ch ), 1 );
    }

    const was = cell.compare_formatted_value === null || cell.compare_formatted_value === undefined
        ? OWA.l( 'no data' ) : cell.compare_formatted_value;

    return { cls: cls, text: text, title: OWA.util.sprintf( OWA.l( '%1$s: %2$s', 'comparison dates: value' ), range, was ) };
}
