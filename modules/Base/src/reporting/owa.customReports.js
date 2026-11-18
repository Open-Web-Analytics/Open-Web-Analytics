import jQuery from 'jquery';
import { OWA } from './owa.format.js';

/**
 * The custom reports list (custom_reports.php): New asks what kind of report
 * to make, in a dialog of tiles.
 *
 * Each tile is a link to its builder, so with no JavaScript the dialog never
 * opens and New goes to the report builder.
 */

/** The dialog's markup, drawn only for someone who may author reports. */
export const NEW_DIALOG_SELECTOR = '[data-ui="dialog:new-report"]';

/**
 * Open the dialog from every New control.
 *
 * @return {Object|null} the OWA.dialog, or null where the page has none
 */
export function startNewReportDialog() {

    var el = jQuery( NEW_DIALOG_SELECTOR );

    if ( ! el.length ) {
        return null;
    }

    var dialog = OWA.dialog( el, {
        title: OWA.l( 'What do you want to make?' ),
        width: 640,
        className: 'owa_typeDialogFrame',
        closeButton: true
    } );

    jQuery( document ).on( 'click', '.owa_newCustomReport', function ( e ) {

        e.preventDefault();

        dialog.open( this );
    } );

    return dialog;
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', startNewReportDialog );
    } else {
        startNewReportDialog();
    }
}

export { OWA };
