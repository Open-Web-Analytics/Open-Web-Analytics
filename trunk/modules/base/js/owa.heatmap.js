var OWA = {};

OWA.heatmap = function(w, h) {

	this.docDimensions = this.getDim(document);
	
	w = w || this.docDimensions.width;
	h =h || this.docDimensions.height;
	this.createCanvas(w,h);
	this.canvas = document.getElementById('owa_heatmap');
	this.context = this.canvas.getContext('2d');
	
	this.calcRegions();
};

OWA.heatmap.prototype = {
	
	options: {'dotSize': 10, 'numRegions': 10, 'alphaIncrement':50},
	canvas: null,
	context: null,
	docDimensions: null,
	regions: new Array(),
	regionsMap: new Array(),
	regionWidth: null,
	regionHeight: null,
	dirtyRegions: new Array(),
	
	markRegionDirty: function(region_num) {
		
		this.dirtyRegions[region_num] = true;
		//console.log("marking region dirty: %s", region_num);
	},
	
	getRegion: function(num) {
		
		return this.regions[num];
	},
	
	setColor: function(num) {
	
		var dims = this.getRegion(num);
		//console.log("set color coords %s %s", dims.x, dims.y);
		
		var canvasData = this.context.getImageData(dims.x, dims.y, this.regionWidth, this.regionHeight);
		var pix = canvasData.data;
		
		// Loop over each pixel and invert the color.
		for (var i = 0, n = pix.length; i < n; i += 4) {
	    	var rgb = this.getRgbFromAlpha(pix[i+3]);
	    	pix[i  ] = Math.round(parseInt(rgb.r)); // red
	    	pix[i+1] = Math.round(parseInt(rgb.g)); // green
	    	pix[i+2] = Math.round(parseInt(rgb.b)); // blue
	    	
		}
	
		// Draw the ImageData object at the given (x,y) coordinates.
		this.context.putImageData(canvasData,dims.x,dims.y);
		
	},
	
	getRgbFromAlpha: function(a) {
		rgb = {'r': null, 'g': null, 'b': null}
		
		if (a > 0 && a <= 50) {
			rgb.b = 255/3;

		} else if (a>50 && a <= 100) {
			//lightblue
			rgb.b = (a/100)*255;
		} else if (a >100 && a <= 150) {
			//green
			rgb.g = (a/150)*255;
		} else if (a >150 && a <= 200) {
			//yellow
			rgb.r = (a/200)*255;
			rgb.g = (a/200)*255;
		} else if (a >200 && a <= 255) {
			// red
			rgb.r = (a/255)*255;
		} else if (a = 0 ) {
			// need to set this to a grey! conditional is not working.
			rgb.r = 255;
			rgb.g = 127;
			rgb.b = 127;
		}
		
		return rgb;
	},
	
	fillRegion: function(num) {
		
		this.fillRectangle(this.regions[num].x, this.regions[num].y, this.regionWidth, this.regionHeight, "rgba(0,0,0, 0.5)");
	},
	
	fillRectangle: function(x,y,w,h,rgba) {
		
		this.context.fillStyle = rgba;
		this.context.fillRect(x, y, w, h);
	},
	
	fillAllRegions: function() {
		
		for (var i=0, n = this.regions.length; i < n; i++) {
			//console.log("region %s", i);
			this.fillRegion(i);
		}
		
	},
	
	findRegion: function(x, y) {		
		//console.log("finding region for %s", x,y);
		for (i in this.regionsMap) {
			//console.log("regionmap i: %s", i);
			if (x < i) {
				//console.log("regionmap x chosen: %s", i);			
				for ( n in this.regionsMap[i]) {
					//console.log("what is this %s", n);	
					if (y < n) {
						//console.log("stopping on regionmap y: %s", n);	
						//console.log("regionmap y: %s", n);		
						//console.log("region chosen: %s", this.regionsMap[i][n]);
						return this.regionsMap[i][n];
					} 

				}
			} 
		}
	}, 
	
	calcRegions: function() {
		
		this.regionWidth = this.docDimensions.w / this.options.numRegions;
		this.regionHeight = this.docDimensions.h / this.options.numRegions;
		//console.log("Region dims: %s %s", this.regionWidth, this.regionHeight);
		
		var count = 0;
		
		// y loop
		for (var y = this.regionHeight, n = this.docDimensions.h; y <= n; y+=this.regionHeight) {
						
			// x loop
			for (var x = this.regionWidth, nn = this.docDimensions.w; x <= nn; x+=this.regionWidth) {
				
				// add region
				this.regions[count] = {'x': x - this.regionWidth, 'y': y - this.regionHeight};
				//create y map
				if (!this.regionsMap[x]) {
					this.regionsMap[x] = Array();
				}
				
				this.regionsMap[x][y] = count;
				//console.log("adding to map: %s %s %s",x,y,count); 
				count++;		
			}

			//console.log("x Count: %s", this.regions.length);		
		}		
		

	},
	
	getRandomData: function(count) {
		
		var data = Array();
		
		for (var li=0; li < count; li++) {
			var x = Math.round(Math.floor(Math.random()*(this.docDimensions.w-300)));
			var y = Math.round(Math.floor(Math.random()*(this.docDimensions.h-300)));
			
			data.push({'x':x,'y':y});
		}
		
		return data;
	},
	
	plotDots: function(data) {
		
		for( var i = 0; i < data.length; i++) {	
			
			// get current alpha channel
			//console.log("getting image data for %s %s", data[i].x, data[i].y);
			var canvasData = this.context.getImageData(data[i].x, data[i].y, this.options.dotSize, this.options.dotSize);
			var pix = canvasData.data;
			
			// Loop over each pixel and invert the color.
			var imgd = this.context.createImageData(this.options.dotSize, this.options.dotSize);
			for (var ii = 0, n = pix.length; ii < n; ii += 4) {
		    	alpha = pix[ii+3];
		    	//console.log("current alpha: %s", alpha);
		    	//return;
		    	if (alpha <= 255) {
		    		
		    		if ((255 - alpha) > this.options.alphaIncrement) {
		    			imgd.data[ii+3] = alpha+this.options.alphaIncrement;
		    			//imgd.data[ii+3] = alpha;
		    			//console.log("setting alpha to %s", imgd.data[ii+3]);
		    		} else {
		    			imgd.data[ii+3] = 255;
		    		}
		    		
		    	}
		 	   	
		    	//imgd.data[ii  ] = 255; // red
		   		//console.log("alpha %s", alpha);
			}
		
			// Draw the ImageData object at the given (x,y) coordinates.
			this.context.putImageData(imgd,data[i].x,data[i].y);
			
			// mark region dirty
			this.markRegionDirty(this.findRegion(data[i].x,data[i].y));
		}
		// color dirty Regions
		this.processDirtyRegions();
	},
	
	processDirtyRegions: function() {
	
		for (i in this.dirtyRegions) {
			this.setColor(i);
		}
	
	},
	
	applyBlur: function() {
		
		// apply gausian blur
		
		this.canvas.className = 'owa_blur';
	},
	

	getDim: function(d) {
        var w=200, h=200, scr_h, off_h;
        if( d.height ) { return {'w':d.width,'h':d.height}; }
        with( d.body ) {
            if( scrollHeight ) { h=scr_h=scrollHeight; w=scrollWidth; }
            if( offsetHeight ) { h=off_h=offsetHeight; w=offsetWidth; }
            if( scr_h && off_h ) h=Math.max(scr_h, off_h);
        }
        
        console.log("doc dims %s %s", w, h);
        
        return {'w': w,'h':h};
    },
    
    createCanvas: function(w, h) {
    
    	document.write('<style>.owa_blur{filter: url(owa/modules/base/i/test.svg#f1);}</style><canvas id="owa_heatmap" width="'+w+'px" height="'+h+'px" style="z-index:99;padding:0; margin:0;background: rgba(127, 127, 127, 0.5);"></canvas>');
    	
    },
    
    getDataPoints: function() {
    
    }

}