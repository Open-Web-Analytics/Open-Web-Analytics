
import { OWA_instance as OWA } from '../common/owa.js';
import { Util } from '../common/Util.js';
import { OWATracker } from './Tracker.js';

class CommandQueue {

	constructor() {
    	
    	OWA.debug('Command Queue object created');
		this.asyncCmds = [];
		this.is_paused = false;
	}

    push(cmd, callback) {

        //alert(func[0]);
        var args = Array.prototype.slice.call(cmd, 1);
        //alert(args);

        var obj_name = '';
        var method = '';
        var check = Util.strpos( cmd[0], '.' );

        if ( ! check ) {
            obj_name = 'OWATracker';
            method = cmd[0];
        } else {
            var parts = cmd[0].split( '.' );
            obj_name = parts[0];
            method = parts[1];
        }

        OWA.debug('cmd queue object name %s', obj_name);
        OWA.debug('cmd queue object method name %s', method);

        if ( method === "pause-owa" ) {

            this.pause();
        }
	    
	if ( method === "unpause-owa") {

            this.unpause();
        }

        // check to see if the command queue has been paused
        // used to stop tracking
        if ( ! this.is_paused && method !== "unpause-owa") {

            // is OWATracker created?
            if ( typeof window[obj_name] == "undefined" ) {
                OWA.debug('making global object named: %s', obj_name);
                window[obj_name] = new OWATracker( { globalObjectName: obj_name } );
            }

            window[obj_name][method].apply(window[obj_name], args);
        }

        if ( callback && ( typeof callback == 'function') ) {
            callback();
        }

    }

    loadCmds( cmds ) {

        this.asyncCmds = cmds;
    }

    process() {

        var that = this;
        var callback = function () {
            // when the handler says it's finished (i.e. runs the callback)
            // We check for more tasks in the queue and if there are any we run again
            if (that.asyncCmds.length > 0) {
                that.process();
             }
        }
        
        // give the first item in the queue & the callback to the handler
        if (this.asyncCmds.length > 0) {
	        
        	this.push(this.asyncCmds.shift(), callback);
        }
     
        /*
        for (var i=0; i < this.asyncCmds.length;i++) {
            this.push(this.asyncCmds[i]);
        }
        */
    }

    pause() {

        this.is_paused = true;
        OWA.debug('Pausing Command Queue');
    }

    unpause() {

        this.is_paused = false;
        OWA.debug('Un-pausing Command Queue');
    }
}


export { CommandQueue };