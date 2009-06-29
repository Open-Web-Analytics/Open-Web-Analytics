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
    },
    
    getScript: function(file, path) {
    
    	jQuery.getScript(path + file);
    	
    	return;
    
    },
    
    makeUrl: function(template, uri, params) {
		var url = jQuery.sprintf(template, uri, jQuery.param(OWA.util.nsAll(params)));
		//alert(url);
		return url;
	}


	
}




