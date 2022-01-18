import { CommandQueue as OwaCommandQueue } from './CommandQueue.js';
import { Util as OwaUtil } from '../common/Util.js';


(function() {

    if ( OwaUtil.isBrowserTrackable() ) {

        // execute commands global owa_cmds command queue
        if ( typeof owa_cmds === 'undefined' ) {
            var q = new OwaCommandQueue();
        } else {
            if ( OwaUtil.is_array(owa_cmds) ) {
                var q = new OwaCommandQueue();
                q.loadCmds( owa_cmds );
            }
        }

        window['owa_cmds'] = q;
        window['owa_cmds'].process();
    }
})();
