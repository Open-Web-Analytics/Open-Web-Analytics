//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2010 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * Javascript Heatmap Library
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.2.1
 */
OWA.heatmap = function(w, h) {

    this.docDimensions = this.getDim(document);

    w = w || this.docDimensions.w;
    h = h || this.docDimensions.h;
    OWA.debug("Canvas size: %s by %s", w, h);
    this.createCanvas(w,h);
    this.canvas = document.getElementById('owa_heatmap');
    this.context = this.canvas.getContext('2d');
    //this.calcRegions();

};

OWA.heatmap.prototype = {

    options: {
        dotSize: 12,
        numRegions: 40,
        alphaIncrement:50,
        demoMode: false,
        liveMode: true,
        mapInterval: 1000,
        randomDataCount: 200,
        rowsPerFetch: 100,
        strokeRegions: false,
        svgUrl: OWA.getSetting('baseUrl')+'/modules/base/i/test.svg#f1',
        baseUrl: '',
        apiUrl: ''
    },
    canvas: null,
    context: null,
    docDimensions: null,
    regions: new Array(),
    regionsMap: new Array(),
    regionWidth: null,
    regionHeight: null,
    dirtyRegions: new Object(),
    timer: '',
    clicks: '',
    nextPage: 1,
    more: true,
    lock: false,

    /**
     * Marks a region as dirty so that it can be re-rendered
     * @depricated
     */
    markRegionDirty: function(region_num) {
        if (region_num >= 0) {
            this.dirtyRegions[region_num] = true;
            OWA.debug("marking region dirty: %s", region_num);
        } else {
            OWA.debug("no region to mark dirty!");
        }
    },

    showControlPanel: function() {
        var that = this;
        jQuery('body').append('<div id="owa_overlay"></div>');
        jQuery('#owa_overlay').append('<div id="owa_overlay_logo"></div>');
        jQuery('#owa_overlay').append('<div class="owa_overlay_control" id="owa_overlay_start">Start</div>');
        jQuery('#owa_overlay_start').toggleClass('active');
        jQuery('#owa_overlay').append('<div class="owa_overlay_control" id="owa_overlay_stop">Stop</div>');
        jQuery('#owa_overlay').append('<div class="owa_overlay_control" id="owa_overlay_end">X</div>');
        jQuery('#owa_overlay_start').click(function(){that.startTimer()});
        jQuery('#owa_overlay_stop').click(function(){that.stopTimer()});
        jQuery('.owa_overlay_control').bind('click', function(){
            jQuery(".owa_overlay_control").removeClass('active');
            jQuery(this).addClass('active');
        });
        jQuery('#owa_overlay_end').click(function(){that.endSession()});
        //eliminate session cookie when window closes.
        jQuery(window).unload(function() {OWA.endOverlaySession()});
    },

    /**
     * Main generation method. kicks off the timer if in liveMode
     */
    generate: function() {

        this.showControlPanel();
        this.applyBlur();

        if (this.options.liveMode === true) {

            this.startTimer();

        } else {

            this.map();
        }


    },

    endSession: function() {

        OWA.util.eraseCookie(OWA.getSetting('ns') + 'overlay', document.domain);
        window.close();
    },

    startTimer: function() {
        var that = this;
        this.timer = setInterval(function(){that.map()}, this.options.mapInterval);
    },

    stopTimer: function() {
        if (!this.timer) return false;
          clearInterval(this.timer);
    },

    /**
     * Gets data and plots it
     */
    map: function() {

        if (this.lock == true) {
            OWA.debug("skipping data fetch due to lock.");
            return;
        } else {
            this.lock = true;
        }

        if (this.options.liveMode === true) {

            var more = this.checkForMoreClicks();
            if (more === true) {
                OWA.debug('there are more clicks to fetch.');
                var data = this.getData();
            } else {
                OWA.debug('there are no more clicks to fetch.');
                this.stopTimer();
            }
        } else {
            var data = this.getData();
        }
    },

    /**
     * Gets data, random if in demoMode
     */
    getData: function() {

        // get data
        if (this.options.demoMode === true) {
            return this.getRandomData(this.options.randomDataCount);
        } else {
            var data = this.fetchData(this.getNextPage());

            return;
        }
    },

    checkForMoreClicks: function() {

        return this.more;
    },

    getNextPage: function() {

        return this.nextPage;
    },

    setNextPage: function(page) {
        OWA.debug("setNextpage received page as %d", page);
        this.nextPage++;
        OWA.debug("setNextpage is setting page as %d", this.nextPage);
    },

    setMore: function(bool) {

        this.more = bool;
    },

    /**
     * Fetches data via ajax request
     */
    fetchData: function(page) {
		
        OWA.debug("fetchData will fetch page %s", page);
 
        if ( page != 1 ) {
	        // get the next url from the last result set
	        var url = this.clicks.next;
	        
        } else {
	        // get data urlr from overlay cookie
	        var p = unescape( OWA.state.getStateFromCookie('overlay') );
			var params = JSON.parse( p );
	        var url = params.api_url;
        }
        
        OWA.debug( 'fetch data using api url: ' + url);
        
        //closure
        var that = this;
        
        jQuery.ajax({
            url: url,
           
            dataType: 'jsonp',
            jsonp: 'owa_jsonpCallback',
            success: function(data) {
                that.plotClickData(data);
            }
        });    
    },

    plotClickData: function(data) {

        if (data) {
            //OWA.debug('setClicks says data is defined');
            this.clicks = data.data;

            //set more flag
            if (data.data.more === true && data.data.more != null) {
                OWA.debug("plotClickData says more flag was set to true");
                this.setMore(true);
                //set next page
                this.setNextPage(data.data.page);
            } else {
                OWA.debug("plotClickData says more flag was set to false");
                this.setMore(false);
            }

            //plot dots
            //this.plotDots(this.getClicks());
            this.plotDotsRound(this.getClicks());
            this.lock = false;
            return true;
        } else {
            return false;
        }

    },

    getClicks: function() {
        //OWA.debug("getClicks is logging %s", this.clicks['page']);
        return this.clicks.resultsRows;
    },

    /**
     * Looks up the a region's top lower right corner plot points
     */
    getRegion: function(num) {
        //OWA.debug("Getting dims for region %s", num);
        return this.regions[num];
    },

    /**
     * Sets the color of a pixels a region based on their alpha values
     * @depircated
     */
    setColor: function(num) {
        OWA.debug("About to set color for region %s", num);
        var dims = this.getRegion(num);
        OWA.debug("set color coords %s %s", dims.x, dims.y);

        // get the actual pixel data from the region
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

    getRgbFromAlpha : function(alpha) {

        var rgb = {'r': null, 'g': null, 'b': null};

        // set colors based on current alpha value
        if( alpha <= 255 && alpha >= 235 ){
            tmp = 255 - alpha;
            rgb.r = 255 - tmp;
            rgb.g = tmp * 12;
        } else if ( alpha <= 234 && alpha >= 200 ){
            tmp = 234 - alpha;
            rgb.r = 255 - ( tmp * 8 );
            rgb.g = 255;
        } else if ( alpha <= 199 && alpha >= 150 ){
            tmp = 199 - alpha;
            rgb.g = 255;
            rgb.b = tmp * 5;
        } else if ( alpha <= 149 && alpha >= 100 ){
            tmp = 149 - alpha;
            rgb.g = 255 - ( tmp * 5 );
            rgb.b = 255;
        } else {
            rgb.b = 255;
        }

        return rgb;
    },

    /**
     * Fills a region with grey
     * DEPRICATED
     */
    fillRegion: function(num) {

        this.fillRectangle(this.regions[num].x, this.regions[num].y, this.regionWidth, this.regionHeight, "rgba(0,0,0, 0.5)");
    },

    strokeRegion: function(num) {

        this.context.strokeRect(this.regions[num].x, this.regions[num].y, this.regionWidth, this.regionHeight);

    },

    /**
     * Fills a rectangle with an rgba value
     */
    fillRectangle: function(x,y,w,h,rgba) {

        this.context.fillStyle = rgba;
        this.context.fillRect(x, y, w, h);
    },

    /**
     * Fils all regions
     * DEPRICATED
     */
    fillAllRegions: function() {

        for (var i=0, n = this.regions.length; i < n; i++) {
            //OWA.debug("region %s", i);
            this.fillRegion(i);
        }

    },

    /**
     * Find the region that a set of coordinates falls into
     */
    findRegion: function(x, y) {
        x = parseFloat(x);
        y = parseFloat(y);
        // walk the outer x map in ascending order
        OWA.debug("finding region for %s", x,y);
        for (i in this.regionsMap) {
            // look for the first value that is greater that or equals to the x coordinate
            if (this.regionsMap.hasOwnProperty(i)) {
                OWA.debug("regionmap i: %s", i);
                if (x <= i) {
                    // For that x coordinate walk the inner map in ascending order
                    OWA.debug("regionmap x chosen: %s. x was: %s", i, x);
                    for ( n in this.regionsMap[i]) {
                        // find the first value that is greater than or equals to the y coordinate
                        if (this.regionsMap[i].hasOwnProperty(n)) {
                            //OWA.debug("what is this %s", n);
                            if (y <= n) {
                                // Return the region number
                                OWA.debug("stopping on regionmap y: %s", n);
                                OWA.debug("regionmap y: %s", n);
                                OWA.debug("region chosen: %s (i = %s, n = %s)", this.regionsMap[i][n], i , n);
                                return this.regionsMap[i][n];
                            }
                        }

                    }
                }
            }
        }
        // Something went wrong as the coordinate does not fit into any region
        //OWA.debug("can't find region for %s %s", x, y);
    },

    /**
     * Chop the document up into a set of regions
     */
    calcRegions: function() {

        // Calculate the region dimensions. This is controlled by the option numRegion.
        // More regions will increase the speed of rendering.
        this.regionWidth = Math.round((this.docDimensions.w / this.options.numRegions) * 100)/100;
        this.regionHeight = Math.round((this.docDimensions.h / this.options.numRegions) * 100)/100;
        OWA.debug("Region dims: %s %s", this.regionWidth, this.regionHeight);

        var count = 0;

        // y loop
        for (var y = this.regionHeight, n = this.docDimensions.h; y <= n; y+=this.regionHeight) {
            y = Math.round(y  * 100)/100 -.00;
            OWA.debug("calcregions y value", y);
            // x loop
            for (var x = this.regionWidth, nn = this.docDimensions.w; x <= nn; x+=this.regionWidth) {
                x = Math.round(x * 100)/100 -.00;
                // add region
                this.regions[count] = {'x': x - this.regionWidth, 'y': y - this.regionHeight};
                //create inner y map
                if (!this.regionsMap[x]) {
                    this.regionsMap[x] = Array();
                }
                //add region to inner map
                this.regionsMap[x][y] = count;
                //OWA.debug("adding to map: %s %s %s",x,y,count);

                if (this.options.strokeRegions === true) {
                    this.strokeRegion(count);
                }

                count++;
            }

            //OWA.debug("x Count: %s", this.regions.length);
        }


    },

    /**
     * Generates random data
     * Takes an int
     */
    getRandomData: function(count) {

        var data = Array();

        for (var li=0; li < count; li++) {
            var x = Math.round(Math.floor(Math.random()*(this.docDimensions.w-this.options.dotSize)));
            var y = Math.round(Math.floor(Math.random()*(this.docDimensions.h-this.options.dotSize)));

            data.push({'x':x,'y':y});
        }

        return data;
    },

    /**
     * Plots dots on a the canvas
     *
     */
    plotDotsRound: function(data) {

        for( var i = 0; i < data.length; i++) {

            if ((data[i].x + this.options.dotSize) > this.docDimensions.w) {
                 data[i].x = data[i].x - this.options.dotSize;
            }

            if ((data[i].y + this.options.dotSize) > this.docDimensions.h) {
                 data[i].y = data[i].y - this.options.dotSize;
            }


            if ((data[i].x <= this.docDimensions.w) && (data[i].y <= this.docDimensions.h)) {
                OWA.debug("plotting %s %s", data[i].x, data[i].y);
            } else {
                OWA.debug("not getting image data. coordinates %s %s are outside the canvas", data[i].x, data[i].y);
                continue;
            }

            if ((data[i].x >= 0) && (data[i].y >= 0)) {
                OWA.debug("plotting %s %s", data[i].x, data[i].y);
            } else {
                OWA.debug("not getting image data. coordinates %s %s less than zero.", data[i].x, data[i].y);
                continue;
            }

            // create a radial gradient with the defined parameters. we want to draw an alphamap
            var rgr = this.context.createRadialGradient(data[i].x,data[i].y,7,data[i].x,data[i].y,this.options.dotSize);
            // the center of the radial gradient has .1 alpha value
            rgr.addColorStop(0, 'rgba(0,0,0,0.1)');
            // and it fades out to 0
            rgr.addColorStop(1, 'rgba(0,0,0,0)');
            // drawing the gradient
            this.context.fillStyle = rgr;
            this.context.fillRect(data[i].x-this.options.dotSize,data[i].y-this.options.dotSize,2*this.options.dotSize,2*this.options.dotSize);

            // mark region dirty
            //this.markRegionDirty(this.findRegion(data[i].x,data[i].y));
            this.setColorForDot(data[i].x, data[i].y);
        }
        // color dirty Regions
        //this.processDirtyRegions();

    },

    /**
     * Sets the color of a pixels a region based on their alpha values
     */
    setColorForDot: function( x, y ) {

        OWA.debug("About to set color for %s, %s", x, y);


        var radius = this.options.dotSize * 1.3; // little extra just in case
        x = x - radius;
        y = y - radius;
        var width = this.docDimensions.w;
        var height = this.docDimensions.h;
        var x2 = this.options.dotSize * 2;
                
        if( x + x2 > width ) {
            x = width - x2;
        }
        
        if( x < 0 ) {
            x = 0;
        }
        
        if(y < 0) {
            y = 0;
        }
        
        if( y + x2 > height ) {
            y = height - x2;
        }

        // get the actual pixel data from the region
        var canvasData = this.context.getImageData(x, y, x2, x2);
        var pix = canvasData.data;

        // Loop over each pixel and invert the color.
        for (var i = 0, n = pix.length; i < n; i += 4) {
            var rgb = this.getRgbFromAlpha(pix[i+3]);
            pix[i  ] = Math.round(parseInt(rgb.r)); // red
            pix[i+1] = Math.round(parseInt(rgb.g)); // green
            pix[i+2] = Math.round(parseInt(rgb.b)); // blue

        }

        // Draw the ImageData object at the given (x,y) coordinates.
        this.context.putImageData(canvasData,x,y);
    },


    processDirtyRegions: function() {

        for (i in this.dirtyRegions) {
            if (this.dirtyRegions.hasOwnProperty(i)) {
                this.setColor(i);
            }
        }

        this.dirtyRegions = new Array();

    },

    applyBlur: function() {

        // apply gausian blur

        this.canvas.className = 'owa_blur';
    },

    getDocHeight : function() {
        var D = document;
        return Math.max(
            Math.max(D.body.scrollHeight, D.documentElement.scrollHeight),
            Math.max(D.body.offsetHeight, D.documentElement.offsetHeight),
            Math.max(D.body.clientHeight, D.documentElement.clientHeight)
        );
    },

    getDim: function(d) {

        var w=200, h=200, scr_h, off_h;
        //OWA.setSetting('debug', true);
        if( d.height ) { 
            //OWA.debug("doc dims %s %s", d.width, d.height);
            //return {'w':d.width,'h':d.height};
        }
        
        if( d.body ) {

            if( d.body.scrollHeight ) { h=scr_h=d.body.scrollHeight; w=d.body.scrollWidth; }
            if( d.body.offsetHeight ) { h=off_h=d.body.offsetHeight; w=d.body.offsetWidth; }
            if( scr_h && off_h ) h=Math.max(scr_h, off_h);
        }
        
        h = this.getDocHeight();
        OWA.debug("doc dims %s %s", w, h);
        
        return {'w': w,'h':h};
    },
    
    createCanvas: function(w, h) {

        var that = this;
        jQuery("body").append('<canvas id="owa_heatmap" width="'+w+'px" height="'+h+'px" style="position:absolute; top:0px; left:0px; z-index:999999;padding:0; margin:0;background: rgba(127, 127, 127, 0.5);"></canvas>');
    },
    
    getDataPoints: function() {
    
    }

}