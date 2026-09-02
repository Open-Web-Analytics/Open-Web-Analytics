import $ from 'jquery';
import { OWA } from './owa.js';

/**
 * The add-a-Profile form shows only the fields the answers make relevant.
 *
 * Two questions drive it. Which Property -- an existing one, or a new one that
 * needs a name. And what the Profile observes -- a website, which is known by
 * its domain, or an app, which is known by a bundle id and has no domain at all.
 *
 * Progressive enhancement: with no JavaScript every field is visible and the
 * form still works, because the server validates by TYPE rather than trusting
 * what was rendered. Hiding a field never decides anything.
 */
OWA.profileForm = {

    apply: function () {

        var $property = $( '#owa_profilePropertyId' );
        var $stream = $( '#owa_streamType' );

        if ( ! $property.length && ! $stream.length ) {

            return;
        }

        // A new Property needs a name; an existing one already has one.
        $( '#owa_newPropertyFields' ).toggle( $property.val() === '' );

        var isApp = $stream.val() === 'app';

        $( '#owa_streamWebFields' ).toggle( ! isApp );
        $( '#owa_streamAppFields' ).toggle( isApp );
    }
};

$( function () {

    OWA.profileForm.apply();

    $( document ).on( 'change', '#owa_profilePropertyId, #owa_streamType', function () {

        OWA.profileForm.apply();
    } );
} );

export { OWA };
