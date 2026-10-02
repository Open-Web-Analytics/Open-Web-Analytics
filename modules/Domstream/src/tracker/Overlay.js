/**
 * Playback: the overlay's `loadPlayer` action (PLAN 2.24.3).
 *
 * Compiled into the tracker itself (`contributes` in this module's build
 * manifest), not into the recorder's lazy chunk. Playback is opened in an
 * overlay session on any page, recording or not, so it cannot wait for the
 * recorder: registered there, it existed only on pages that had started
 * recording. The player itself is still its own chunk, loaded only in an
 * overlay session.
 */
import { OWA_instance as OWA } from '../../../Base/src/common/owa.js';
import { Util } from '../../../Base/src/common/Util.js';

OWA.registerOverlayMode( 'loadPlayer', () => {

    Util.loadCss( OWA.getSetting( 'baseUrl' ) + 'public/base/css/owa.overlay.css', function () {} );

    import( /* webpackChunkName: "owa.player" */ './Player.js' ).then( ( { Player } ) => {

        OWA.overlay = new Player();
        OWA.overlay.init();
    } );
} );
