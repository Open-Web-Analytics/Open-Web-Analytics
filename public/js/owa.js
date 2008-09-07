var OWA = {

	items: new Object,
	config: new Object

};


OWA.util =  {

	ns: function(string) {
	
		return OWA.config.ns + string;
	
	},
	
	nsAll: function(obj) {
	
		var nsObj = new Object();
		
		for(param in obj) {  // print out the params
	       
			nsObj[OWA.config.ns+param] = obj[param];
			
		}
		
		return nsObj;
    }

	
}


