import { Util } from '../common/Util.js';

/**
 * OWA Generic Event Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
 */
class Event {
	
	constructor() {
		

	    this.properties = {};
		this.id = '';
		this.siteId = '';
		this.set('timestamp', Util.getCurrentUnixTimestamp() );
	}

    get(name) {

        if ( this.properties.hasOwnProperty(name) ) {

            return this.properties[name];
        }
    }

    set(name, value) {

        this.properties[name] = value;
    }

    setEventType(event_type) {

        this.set("event_type", event_type);
    }

    getProperties() {

        return this.properties;
    }

    merge(properties) {

        for( var param in properties) {

            if (properties.hasOwnProperty(param)) {

                this.set(param, properties[param]);
            }
        }
    }

    isSet( name ) {

        if ( this.properties.hasOwnProperty( name ) ) {

            return true;
        }
    }
}

export { Event };