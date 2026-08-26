/**
 * Javascript Heatmap Library
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */
 
import { OWA_instance } from '../common/owa.js'; 
import * as jQuery from 'jquery';

class Heatmap {
	
	constructor(w, h) {
		
	    this.docDimensions = this.getDim(document);
	
	    w = w || this.docDimensions.w;
	    h = h || this.docDimensions.h;
	    OWA_instance.debug("Canvas size: %s by %s", w, h);
	    
	    this.createCanvas(w,h);
	    this.canvas = document.getElementById('owa_heatmap');
	    this.context = this.canvas.getContext('2d');

	    /*
	     * Gradients accumulate OFF screen, and the visible canvas is painted
	     * from it.
	     *
	     * They used to be drawn straight onto the visible canvas, which was
	     * then recoloured a dot at a time. That is wrong twice over. Once a
	     * region has been recoloured its pixels hold colour, not the black
	     * alpha ramp the next dot composites against -- so wherever two clicks
	     * overlapped, the second one blended into the first one's colour and
	     * the result was neither click's intensity. And it cost a
	     * getImageData/putImageData round trip PER CLICK: on a page with
	     * 345,620 of them that is 345,620 canvas reads.
	     *
	     * Accumulating alpha off screen and colourising the whole canvas once
	     * per fetch is the standard way round, and makes overlap mean what it
	     * should -- more clicks, hotter.
	     */
	    this.shadowCanvas = document.createElement('canvas');
	    this.shadowCanvas.width = w;
	    this.shadowCanvas.height = h;
	    this.shadowContext = this.shadowCanvas.getContext('2d', { willReadFrequently: true });
	
	    this.options = {
		    
	        dotSize: 12,
	        // Alpha a single click contributes at the centre of its dot. Points
	        // arrive grouped with a count, so N clicks on one pixel stack to
	        // N * this -- which is what makes a hot spot hot rather than every
	        // dot being identically faint.
	        dotAlpha: 0.08,
	        numRegions: 40,
	        alphaIncrement:50,
	        demoMode: false,
	        liveMode: true,
	        mapInterval: 1000,
	        randomDataCount: 200,
	        rowsPerFetch: 100,
	        strokeRegions: false,
	        baseUrl: '',
	        apiUrl: ''
	    };
	    
	   
	    
	    this.regions = [];
	    this.regionsMap = [];
	    this.regionWidth = null;
	    this.regionHeight = null;
	    this.dirtyRegions = {};
	    this.timer = '';
	    this.clicks = '';
	    this.nextPage = 1;
	    this.more = true;
	    this.lock = false;
	}
	

    showControlPanel() {
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
        //jQuery(window).unload(function() {OWA_instance.endOverlaySession()});
        jQuery(window).on('unload', function() {OWA_instance.endOverlaySession()});
    }

    /**
     * Main generation method. kicks off the timer if in liveMode
     */
    generate() {

        this.showControlPanel();
        this.applyBlur();

        if (this.options.liveMode === true) {

            this.startTimer();

        } else {

            this.map();
        }


    }

    endSession() {

        Util.eraseCookie(OWA_instance.getSetting('ns') + 'overlay', document.domain);
        window.close();
    }

    startTimer() {
	    
        var that = this;
        this.timer = setInterval(function(){that.map()}, this.options.mapInterval);
    }

    stopTimer() {
	    
        if (!this.timer) return false;
          clearInterval(this.timer);
    }

    /**
     * Gets data and plots it
     */
    map() {

        if (this.lock == true) {
            OWA_instance.debug("skipping data fetch due to lock.");
            return;
        } else {
            this.lock = true;
        }

        if (this.options.liveMode === true) {

            var more = this.checkForMoreClicks();
            if (more === true) {
                OWA_instance.debug('there are more clicks to fetch.');
                var data = this.getData();
            } else {
                OWA_instance.debug('there are no more clicks to fetch.');
                this.stopTimer();
            }
        } else {
            var data = this.getData();
        }
    }

    /**
     * Gets data, random if in demoMode
     */
    getData() {

        // get data
        if (this.options.demoMode === true) {
            return this.getRandomData(this.options.randomDataCount);
        } else {
            var data = this.fetchData(this.getNextPage());

            return;
        }
    }

    checkForMoreClicks() {

        return this.more;
    }

    getNextPage() {

        return this.nextPage;
    }

    setNextPage(page) {
        OWA_instance.debug("setNextpage received page as %d", page);
        this.nextPage++;
        OWA_instance.debug("setNextpage is setting page as %d", this.nextPage);
    }

    setMore(bool) {

        this.more = bool;
    }

    /**
     * Fetches data via ajax request
     */
    fetchData(page) {
		
        OWA_instance.debug("fetchData will fetch page %s", page);
 
        if ( page != 1 ) {
	        // get the next url from the last result set
	        var url = this.clicks.next;
	        
        } else {
	        // Overlay params live in memory for this page's lifetime;
	        // they are never written to a cookie on the tracked site.
	        var params = OWA_instance.getOverlayParams() || {};
	        var url = params.api_url;
        }
        
        OWA_instance.debug( 'fetch data using api url: ' + url);
        
        //closure
        var that = this;
        
        jQuery.ajax({
            url: url,
           
            // A plain cross-origin GET, not JSONP.
            //
            // JSONP returns the body as a <script> the browser executes, which
            // makes the endpoint readable by any page on the internet. It was
            // used here only because these run on the tracked site and call
            // back to the OWA origin, and CORS did not work -- addCorsHeaders()
            // never emitted a header, and isHttps() let a client's Origin flip
            // the server's scheme and break the request signature. Both fixed.
            //
            // Credentials travel in the query string, so this stays a CORS
            // "simple request" and costs no preflight round trip.
            dataType: 'json',
            success: function(data) {
                that.plotClickData(data);
            }
        });    
    }

    plotClickData(data) {

        if (data) {
            //OWA.debug('setClicks says data is defined');
            this.clicks = data.data;

            //set more flag
            if (data.data.more === true && data.data.more != null) {
                OWA_instance.debug("plotClickData says more flag was set to true");
                this.setMore(true);
                //set next page
                this.setNextPage(data.data.page);
            } else {
                OWA_instance.debug("plotClickData says more flag was set to false");
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

    }

    /**
     * The fetched rows as plottable points.
     *
     * A heatmap is now an ordinary dimensional query -- domClicks grouped by
     * clickX and clickY -- so a row is {clickX:{value},clickY:{value},
     * domClicks:{value}} rather than the flat {x,y} the bespoke clicks report
     * used to alias into being. The count comes back as the metric, which is
     * the weight each point is drawn with.
     */
    getClicks() {

        var rows = ( this.clicks && this.clicks.resultsRows ) ? this.clicks.resultsRows : [];
        var points = [];

        for ( var i = 0; i < rows.length; i++ ) {

            var row = rows[i];

            if ( ! row || ! row.clickX || ! row.clickY ) {
                continue;
            }

            var x = parseInt( row.clickX.value, 10 );
            var y = parseInt( row.clickY.value, 10 );

            if ( isNaN( x ) || isNaN( y ) ) {
                continue;
            }

            var weight = row.domClicks ? parseInt( row.domClicks.value, 10 ) : 1;

            points.push( { x: x, y: y, weight: ( weight > 0 ? weight : 1 ) } );
        }

        return points;
    }



    getRgbFromAlpha(alpha) {
		
		var tmp = 0;
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
    }

    /**
     * Fills a region with grey
     * DEPRICATED
     */
    fillRegion(num) {

        this.fillRectangle(this.regions[num].x, this.regions[num].y, this.regionWidth, this.regionHeight, "rgba(0,0,0, 0.5)");
    }

    strokeRegion(num) {

        this.context.strokeRect(this.regions[num].x, this.regions[num].y, this.regionWidth, this.regionHeight);

    }

    /**
     * Fills a rectangle with an rgba value
     */
    fillRectangle(x,y,w,h,rgba) {

        this.context.fillStyle = rgba;
        this.context.fillRect(x, y, w, h);
    }

    /**
     * Fils all regions
     * DEPRICATED
     */
    fillAllRegions() {

        for (var i=0, n = this.regions.length; i < n; i++) {
            //OWA_instance.debug("region %s", i);
            this.fillRegion(i);
        }
    }



    /**
     * Generates random data
     * Takes an int
     */
    getRandomData(count) {

        var data = Array();

        for (var li=0; li < count; li++) {
            var x = Math.round(Math.floor(Math.random()*(this.docDimensions.w-this.options.dotSize)));
            var y = Math.round(Math.floor(Math.random()*(this.docDimensions.h-this.options.dotSize)));

            data.push({'x':x,'y':y});
        }

        return data;
    }

    /**
     * Plots dots on a the canvas
     *
     */
    /**
     * Plots a page of points onto the accumulation canvas, then paints.
     *
     * Every point contributes a radial alpha gradient scaled by how many clicks
     * landed there. Overlapping gradients ADD, which is the whole idea of a
     * heatmap and is what the old per-dot recolouring destroyed.
     */
    plotDotsRound(data) {

        var size = this.options.dotSize;
        var w    = this.docDimensions.w;
        var h    = this.docDimensions.h;

        for ( var i = 0; i < data.length; i++ ) {

            var x = data[i].x;
            var y = data[i].y;

            // Off the page entirely: nothing sensible to draw. Clicks recorded
            // at a wider viewport than the one being viewed land here.
            if ( x < 0 || y < 0 || x > w || y > h ) {

                OWA_instance.debug( "skipping click outside the canvas: %s %s", x, y );
                continue;
            }

            /*
             * Alpha rises with the click count but saturates, so one very hot
             * point cannot flatten the rest of the map into a single colour.
             * Anything at or above the cap is simply "as hot as it gets".
             */
            var weight = data[i].weight || 1;
            var alpha  = Math.min( 1, this.options.dotAlpha * weight );

            var rgr = this.shadowContext.createRadialGradient( x, y, 0, x, y, size );
            rgr.addColorStop( 0, 'rgba(0,0,0,' + alpha + ')' );
            rgr.addColorStop( 1, 'rgba(0,0,0,0)' );

            this.shadowContext.fillStyle = rgr;
            this.shadowContext.fillRect( x - size, y - size, 2 * size, 2 * size );
        }

        this.colorize();
    }

    /**
     * Paints the visible canvas from the accumulated alpha, in ONE pass.
     *
     * This replaces setColorForDot(), which did a getImageData, a pixel loop
     * and a putImageData for every single click.
     */
    colorize() {

        var w = this.shadowCanvas.width;
        var h = this.shadowCanvas.height;

        if ( w < 1 || h < 1 ) {

            return;
        }

        var image = this.shadowContext.getImageData( 0, 0, w, h );
        var pix   = image.data;

        for ( var i = 0, n = pix.length; i < n; i += 4 ) {

            var alpha = pix[ i + 3 ];

            // Untouched pixels stay transparent; colouring them would paint the
            // whole page the coldest colour rather than leaving it alone.
            if ( alpha === 0 ) {

                continue;
            }

            var rgb = this.getRgbFromAlpha( alpha );

            pix[ i ]     = Math.round( parseInt( rgb.r ) );
            pix[ i + 1 ] = Math.round( parseInt( rgb.g ) );
            pix[ i + 2 ] = Math.round( parseInt( rgb.b ) );
        }

        this.context.putImageData( image, 0, 0 );
    }


    applyBlur() {

        // apply gausian blur

        this.canvas.className = 'owa_blur';
    }

    getDocHeight() {
        var D = document;
        return Math.max(
            Math.max(D.body.scrollHeight, D.documentElement.scrollHeight),
            Math.max(D.body.offsetHeight, D.documentElement.offsetHeight),
            Math.max(D.body.clientHeight, D.documentElement.clientHeight)
        );
    }

    getDim(d) {

        var w=200, h=200, scr_h, off_h;
        //OWA_instance.setSetting('debug', true);
        if( d.height ) { 
            //OWA_instance.debug("doc dims %s %s", d.width, d.height);
            //return {'w':d.width,'h':d.height};
        }
        
        if( d.body ) {

            if( d.body.scrollHeight ) { h=scr_h=d.body.scrollHeight; w=d.body.scrollWidth; }
            if( d.body.offsetHeight ) { h=off_h=d.body.offsetHeight; w=d.body.offsetWidth; }
            if( scr_h && off_h ) h=Math.max(scr_h, off_h);
        }
        
        h = this.getDocHeight();
        OWA_instance.debug("doc dims %s %s", w, h);
        
        return {'w': w,'h':h};
    }
    
    createCanvas(w, h) {

        var that = this;
        jQuery("body").append('<canvas id="owa_heatmap" width="'+w+'px" height="'+h+'px" style="position:absolute; top:0px; left:0px; z-index:999999;padding:0; margin:0;background: rgba(127, 127, 127, 0.5);"></canvas>');
    }
    

}

export { Heatmap };