/**
 * The report period picker (owa.periodpicker.js).
 *
 * The date arithmetic is pinned against tests/fixtures/compare-ranges.json,
 * which TimePeriodCompareTest.php also reads, so the comparison the picker
 * previews is the one the server reports. The rest drives the control the way
 * a reader does: presets, Custom date range, the Compare switch, the calendar,
 * Apply.
 */

import {
    PeriodPicker, compareRange, initialState, settle, toParams, calendarTarget,
    pickDay, formatRange, toIso, fromIso, parseDateInput,
} from '../../modules/Base/src/reporting/v1/owa.periodpicker.js';

const fixture = require( '../fixtures/compare-ranges.json' );

const PRESETS = [
    { key: 'today', label: 'Today', startDate: '20261109', endDate: '20261109' },
    { key: 'yesterday', label: 'Yesterday', startDate: '20261108', endDate: '20261108' },
    { key: 'last_28_days', label: 'Last 28 days', startDate: '20261013', endDate: '20261109' },
    { key: 'last_month', label: 'Last month', startDate: '20261001', endDate: '20261031' },
];

const COMPARES = [
    { key: 'previous_period', label: 'Previous period' },
    { key: 'previous_period_dow', label: 'Previous period (same weekdays)' },
    { key: 'previous_year', label: 'Previous year' },
    { key: 'previous_year_dow', label: 'Previous year (same weekdays)' },
];

function config( extra ) {

    return Object.assign( {
        today: '20261109', presets: PRESETS, compares: COMPARES,
        period: 'last_28_days', startDate: '20261013', endDate: '20261109',
        compare: '', compareStartDate: '', compareEndDate: '',
    }, extra || {} );
}

describe( 'compareRange agrees with the server', () => {

    test.each( fixture.cases.map( ( c ) => [ c.key, c.start, c.end, c.want ] ) )(
        '%s %s-%s', ( key, start, end, want ) => {
            expect( compareRange( key, start, end ) ).toEqual( want );
        } );

    test( 'an unknown comparison gives no range', () => {
        expect( compareRange( 'same_month_last_year', '20261001', '20261031' ) ).toBeNull();
    } );
} );

describe( 'state', () => {

    test( 'a preset is read from the server, a period it does not list is a custom range', () => {
        expect( initialState( config() ) ).toMatchObject( { preset: 'last_28_days', main: [ '20261013', '20261109' ], cmpOn: false } );
        expect( initialState( config( { period: 'date_range', startDate: '20260101', endDate: '20260131' } ) ) )
            .toMatchObject( { preset: 'custom', main: [ '20260101', '20260131' ] } );
    } );

    test( 'a relative comparison follows the period; a custom one keeps its dates', () => {
        const s = initialState( config( { compare: 'previous_period' } ) );
        expect( s ).toMatchObject( { cmpOn: true, cmpKey: 'previous_period', cmp: [ '20260915', '20261012' ] } );

        s.preset = 'last_month';
        settle( s, PRESETS );
        expect( s.main ).toEqual( [ '20261001', '20261031' ] );
        expect( s.cmp ).toEqual( [ '20260831', '20260930' ] );

        const c = initialState( config( { compare: 'date_range', compareStartDate: '20250101', compareEndDate: '20250131' } ) );
        expect( c ).toMatchObject( { cmpKey: 'custom', cmp: [ '20250101', '20250131' ] } );
        c.preset = 'last_month';
        settle( c, PRESETS );
        expect( c.cmp ).toEqual( [ '20250101', '20250131' ] );
    } );

    test( 'toParams: a preset is `period`, a custom range is two dates; the comparison likewise', () => {
        expect( toParams( initialState( config() ) ) ).toEqual( {
            period: 'last_28_days', startDate: null, endDate: null,
            compare: null, compareStartDate: null, compareEndDate: null,
        } );

        expect( toParams( { preset: 'custom', main: [ '20260101', '20260131' ], cmpOn: true, cmpKey: 'previous_year', cmp: [ '20250101', '20250131' ] } ) )
            .toEqual( { period: null, startDate: '20260101', endDate: '20260131', compare: 'previous_year', compareStartDate: null, compareEndDate: null } );

        expect( toParams( { preset: 'today', main: [ '20261109', '20261109' ], cmpOn: true, cmpKey: 'custom', cmp: [ '20251109', '20251109' ] } ) )
            .toEqual( { period: 'today', startDate: null, endDate: null, compare: 'date_range', compareStartDate: '20251109', compareEndDate: '20251109' } );
    } );

    test( 'the calendar edits only a Custom date range', () => {
        const s = { preset: 'today', cmpOn: true, cmpKey: 'previous_year' };
        expect( calendarTarget( s, 'main' ) ).toBeNull();

        s.cmpKey = 'custom';
        expect( calendarTarget( s, 'main' ) ).toBe( 'cmp' );

        s.preset = 'custom';
        expect( calendarTarget( s, 'main' ) ).toBe( 'main' );
        expect( calendarTarget( s, 'cmp' ) ).toBe( 'cmp' );

        s.cmpOn = false;
        expect( calendarTarget( s, 'cmp' ) ).toBe( 'main' );
    } );

    test( 'a click sets the start, the next the end; an end before the start is refused', () => {
        const s = { main: [ '20261001', '20261031' ] };

        // The start; the end stays, as it is still after it.
        let edge = pickDay( s, 'main', '20261005', 0 );
        expect( s.main ).toEqual( [ '20261005', '20261031' ] );
        expect( edge ).toBe( 1 );

        // An end before the start changes nothing and still waits for the end.
        edge = pickDay( s, 'main', '20261003', edge );
        expect( s.main ).toEqual( [ '20261005', '20261031' ] );
        expect( edge ).toBe( 1 );

        edge = pickDay( s, 'main', '20261020', edge );
        expect( s.main ).toEqual( [ '20261005', '20261020' ] );
        expect( edge ).toBe( 0 );

        // A start after the end takes the end with it.
        edge = pickDay( s, 'main', '20261025', edge );
        expect( s.main ).toEqual( [ '20261025', '20261025' ] );

        expect( pickDay( s, null, '20261001', 0 ) ).toBe( 0 );
    } );

    test( 'a typed date is read in the forms people type', () => {
        expect( parseDateInput( 'Oct 13, 2026' ) ).toBe( '20261013' );
        expect( parseDateInput( '10/13/2026' ) ).toBe( '20261013' );
        expect( parseDateInput( '2026-10-13' ) ).toBe( '20261013' );
        expect( parseDateInput( ' 20261013 ' ) ).toBe( '20261013' );
        expect( parseDateInput( '2026-02-30' ) ).toBe( '' );
        expect( parseDateInput( 'next tuesday' ) ).toBe( '' );
        expect( parseDateInput( '' ) ).toBe( '' );
    } );

    test( 'dates convert to and from an <input type="date">', () => {
        expect( toIso( '20261109' ) ).toBe( '2026-11-09' );
        expect( fromIso( '2026-11-09' ) ).toBe( '20261109' );
        expect( fromIso( '11/09/2026' ) ).toBe( '' );
    } );

    test( 'a range reads as one span', () => {
        expect( formatRange( '20261013', '20261109' ) ).toMatch( /Oct 13.*Nov 9, 2026/ );
        expect( formatRange( '20261109', '20261109' ) ).toMatch( /Nov 9, 2026/ );
    } );
} );

describe( 'the control', () => {

    let el, applied;

    function make( extra ) {
        document.body.innerHTML = '<div id="pp"></div>';
        el = document.getElementById( 'pp' );
        applied = [];
        return new PeriodPicker( el, config( extra ), ( p ) => applied.push( p ) );
    }

    const $ = ( sel ) => el.querySelector( sel );
    const $$ = ( sel ) => Array.from( el.querySelectorAll( sel ) );
    const click = ( node ) => node.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
    const button = ( text ) => $$( '.owa_periodList button' ).find( ( b ) => b.textContent === text );

    test( 'the button names the preset and its dates; the panel opens on click', () => {
        make();
        expect( $( '.owa_periodBadge' ).textContent ).toBe( 'Last 28 days' );
        expect( $( '.owa_periodPanel' ) ).toBeNull();

        click( $( '.owa_periodTrigger' ) );
        expect( $( '.owa_periodPanel' ) ).not.toBeNull();
        expect( $( '.owa_periodTrigger' ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
    } );

    test( 'Custom date range is last, under Fixed, after the Relative presets', () => {
        make();
        click( $( '.owa_periodTrigger' ) );

        const items = $$( '.owa_periodList > li' ).map( ( li ) => li.textContent.trim() );
        expect( items[0] ).toMatch( /^Relative/ );
        expect( items.indexOf( 'Custom date range' ) ).toBe( items.indexOf( 'Compare' ) - 1 - 1 );
        expect( items[ items.indexOf( 'Custom date range' ) - 1 ] ).toMatch( /^Fixed/ );
    } );

    test( "a preset's dates are read-only; Custom date range makes them editable", () => {
        make();
        click( $( '.owa_periodTrigger' ) );

        // Text, not inputs: nothing in a read-only field can take focus or draw a focus ring.
        expect( $$( '.owa_periodField.is-readonly .owa_periodValue' ).map( ( v ) => v.textContent ) ).toEqual( [ 'Oct 13, 2026', 'Nov 9, 2026' ] );
        expect( $$( '.owa_periodField.is-readonly input, .owa_periodField.is-readonly [tabindex]' ) ).toHaveLength( 0 );
        expect( $( '.owa_periodKind' ).textContent ).toBe( 'Relative period' );
        expect( $( '.owa_periodCal' ).classList.contains( 'is-readonly' ) ).toBe( true );

        click( button( 'Custom date range' ) );

        // Text fields: a date input would bring the browser's own pop-up calendar.
        const inputs = $$( '.owa_periodField input' );
        expect( inputs.map( ( i ) => i.type ) ).toEqual( [ 'text', 'text' ] );
        expect( inputs.map( ( i ) => i.value ) ).toEqual( [ 'Oct 13, 2026', 'Nov 9, 2026' ] );
        expect( $( '.owa_periodKind' ).textContent ).toBe( 'Fixed period' );
        expect( $( '.owa_periodCal' ).classList.contains( 'is-readonly' ) ).toBe( false );
    } );

    test( 'Compare adds a select of the comparisons under the switch, and two fields', () => {
        make();
        click( $( '.owa_periodTrigger' ) );
        expect( $( '.owa_periodCmpSelect' ) ).toBeNull();

        click( $( '.owa_periodLeft .owa_periodSwitch' ) );

        // One select, not a list: a list of them made the panel as tall as both.
        expect( button( 'Previous year' ) ).toBeUndefined();
        const select = $( '.owa_periodLeft .owa_periodSwitchRow' ).closest( 'li' ).nextElementSibling.querySelector( 'select' );
        expect( select ).toBe( $( '.owa_periodCmpSelect' ) );
        expect( Array.from( select.options ).map( ( o ) => o.textContent ) )
            .toEqual( expect.arrayContaining( [ 'Previous year', 'Custom date range' ] ) );
        // Previous period first, and what Compare starts on.
        expect( select.options[0].value ).toBe( 'previous_period' );
        expect( select.value ).toBe( 'previous_period' );
        expect( $$( '.owa_periodField' ) ).toHaveLength( 4 );
        expect( $$( '.owa_periodKind' ).map( ( k ) => k.textContent ) ).toEqual( [ 'Relative period', 'Relative period' ] );
        expect( $$( '.owa_periodFieldCmp .owa_periodValue' ).map( ( v ) => v.textContent ) ).toEqual( [ 'Sep 15, 2026', 'Oct 12, 2026' ] );
    } );

    test( 'clicking days sets a custom range; a read-only calendar ignores clicks', () => {
        make();
        click( $( '.owa_periodTrigger' ) );

        expect( $( '[data-d="20261020"]' ).hasAttribute( 'data-act' ) ).toBe( false );

        click( button( 'Custom date range' ) );
        const target = () => $$( '.owa_periodField.is-target' ).map( ( f ) => f.getAttribute( 'data-f' ) );
        expect( target() ).toEqual( [ 'main0' ] );
        expect( $( '.owa_periodWhy' ).textContent ).toMatch( /set the start date/ );

        const day = $( '[data-d="20261002"]' );
        click( day );

        // Drawn in place: the day clicked is still the one in the page.
        expect( $( '[data-d="20261002"]' ) ).toBe( day );
        expect( $$( '.owa_periodField input' ).map( ( i ) => i.value ) ).toEqual( [ 'Oct 2, 2026', 'Nov 9, 2026' ] );
        expect( target() ).toEqual( [ 'main1' ] );
        expect( $( '.owa_periodWhy' ).textContent ).toMatch( /set the end date/ );
        // Still white and editable: only the outline moved.
        expect( $$( '.owa_periodField.is-readonly' ) ).toHaveLength( 0 );

        // Days before the start cannot be the end.
        expect( $( '[data-d="20261001"]' ).disabled ).toBe( true );
        click( $( '[data-d="20261020"]' ) );

        expect( $$( '.owa_periodField input' ).map( ( i ) => i.value ) ).toEqual( [ 'Oct 2, 2026', 'Oct 20, 2026' ] );
        expect( target() ).toEqual( [ 'main0' ] );
        expect( $( '[data-d="20261001"]' ).disabled ).toBe( false );
        expect( $( '[data-d="20261110"]' ).disabled ).toBe( true );

        click( $( '[data-act="apply"]' ) );
        expect( applied[0] ).toMatchObject( { period: null, startDate: '20261002', endDate: '20261020', compare: null } );
        expect( $( '.owa_periodPanel' ) ).toBeNull();
    } );

    test( 'a typed date is taken on Enter; an end before the start is refused and named', () => {
        make();
        click( $( '.owa_periodTrigger' ) );
        click( button( 'Custom date range' ) );

        const field = ( k ) => $( '[data-in="' + k + '"]' );
        const type = ( k, text ) => {
            field( k ).focus();
            field( k ).value = text;
            field( k ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
        };

        field( 'main1' ).dispatchEvent( new FocusEvent( 'focusin', { bubbles: true } ) );
        expect( $$( '.owa_periodField.is-target' ).map( ( f ) => f.getAttribute( 'data-f' ) ) ).toEqual( [ 'main1' ] );

        type( 'main0', '10/1/2026' );
        expect( field( 'main0' ).value ).toBe( 'Oct 1, 2026' );

        type( 'main1', 'Sep 3, 2026' );
        expect( field( 'main1' ).value ).toBe( 'Nov 9, 2026' );
        expect( $( '.owa_periodWhy' ).textContent ).toBe( 'The end date can’t be before the start date.' );

        type( 'main1', 'nonsense' );
        expect( $( '.owa_periodWhy' ).textContent ).toMatch( /Couldn’t read “nonsense” as a date/ );

        type( 'main1', '2026-10-15' );
        click( $( '[data-act="apply"]' ) );
        expect( applied[0] ).toMatchObject( { startDate: '20261001', endDate: '20261015' } );
    } );

    test( 'Apply hands back a preset and a relative comparison', () => {
        make();
        click( $( '.owa_periodTrigger' ) );
        click( button( 'Last month' ) );
        click( $( '.owa_periodLeft .owa_periodSwitch' ) );
        $( '.owa_periodCmpSelect' ).value = 'previous_year';
        $( '.owa_periodCmpSelect' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
        click( $( '[data-act="apply"]' ) );

        expect( applied ).toEqual( [ {
            period: 'last_month', startDate: null, endDate: null,
            compare: 'previous_year', compareStartDate: null, compareEndDate: null,
        } ] );
        expect( $( '.owa_periodTriggerCompare' ).textContent ).toMatch( /Oct 1.*31, 2025/ );
        expect( $( '.owa_periodTriggerCompare i' ) ).toBeNull();
    } );

    test( 'Cancel and Escape discard the changes', () => {
        make();
        click( $( '.owa_periodTrigger' ) );
        click( button( 'Today' ) );
        click( $( '.owa_periodFoot [data-act="cancel"]' ) );

        expect( applied ).toHaveLength( 0 );
        expect( $( '.owa_periodBadge' ).textContent ).toBe( 'Last 28 days' );

        click( $( '.owa_periodTrigger' ) );
        $( '.owa_periodPanel' ).dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
        expect( $( '.owa_periodPanel' ) ).toBeNull();
    } );

    test( 'the explanation names the choice in one line', () => {
        make( { compare: 'previous_period' } );
        click( $( '.owa_periodTrigger' ) );

        expect( $( '.owa_periodWhy' ).textContent )
            .toBe( 'Last 28 days, counted from today. Compared to previous period. Pick Custom date range to choose dates.' );
    } );
} );
