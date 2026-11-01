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
import { ElementAnchor } from './ElementAnchor.js';

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
	        /*
	         * How much narrower or wider than the reader's window a click with
	         * no element anchor may have been recorded in and still be drawn by
	         * its page coordinate. A centred layout moves by half the
	         * difference, so 24px keeps the error inside one dot.
	         */
	        widthTolerance: 24,
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

	    // Every row fetched so far: a resize redraws them all.
	    this.rows = [];
	    this.stats = Heatmap.emptyStats();
	}

    /**
     * What the Stats panel shows, all in clicks except the two element counts.
     *
     * clicks = drawn + notOnPage + otherWidth + noPosition + offPage, always.
     */
    static emptyStats() {

        return {
            clicks: 0,
            drawn: 0,
            elements: new Set(),
            elementsFound: new Set(),
            notOnPage: 0,
            otherWidth: 0,
            noPosition: 0,
            offPage: 0
        };
    }
	

    /**
     * The panel says whose overlay this is, and nothing more.
     *
     * It had Start, Stop and X. Start and Stop paused fetching the next page
     * of clicks -- from when clicks came a hundred at a time; one request now
     * brings them all, so the fetching is over before either could be
     * pressed. X called a cookie helper this file never imported, so it threw
     * and closed nothing; the reader closes the window.
     */
    showControlPanel() {
        var that = this;
        jQuery('body').append('<div id="owa_overlay"></div>');
        jQuery('#owa_overlay').append('<div id="owa_overlay_logo"></div>');

        // Stats: how many clicks there are, how many are drawn, and why the
        // rest are not.
        jQuery('#owa_overlay').append('<div class="owa_overlay_control" id="owa_overlay_stats_toggle" '
            + 'role="button" tabindex="0" aria-expanded="false" aria-controls="owa_overlay_stats">Stats</div>');
        jQuery('#owa_overlay').append('<div id="owa_overlay_stats" hidden></div>');
        jQuery('#owa_overlay_stats_toggle').on('click keydown', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            that.toggleStats();
        });
        this.renderStats();

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

        // Elements move when the window changes size, and late images push
        // them down after the first draw: place every click again.
        var that = this;
        var pending = null;
        var later = function() {
            clearTimeout(pending);
            pending = setTimeout(function() { that.redraw(); }, 250);
        };
        window.addEventListener('resize', later);
        if (document.readyState !== 'complete') {
            window.addEventListener('load', later);
        }

        if (this.options.liveMode === true) {

            this.startTimer();

        } else {

            this.map();
        }


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

            this.rows = this.rows.concat(this.clicks.resultsRows || []);
            this.redraw();
            this.lock = false;
            return true;
        } else {
            return false;
        }

    }

    /**
     * The last fetched page of rows as plottable points.
     */
    getClicks() {

        var rows = ( this.clicks && this.clicks.resultsRows ) ? this.clicks.resultsRows : [];

        return this.pointsFor( rows, Heatmap.emptyStats() );
    }

    /**
     * Rows as points on this page, counting into stats what could not be placed.
     *
     * A row is eventCount grouped by OverlayLauncher::HEATMAP_DIMENSIONS, so
     * the count comes back as the metric and is the weight a point is drawn
     * with. A row without the metric is drawn at weight 1: every point weighs
     * the same and the map flattens, so the name has to match the request.
     *
     * A row with an element anchor is drawn on that element, wherever it is in
     * this window. One whose element is not here, or has no box at this width,
     * is not drawn: its page coordinate was measured against a page that no
     * longer matches. A row with no anchor -- a click recorded before anchors,
     * or one with no pointer behind it -- is drawn by its page coordinate when
     * its window was within widthTolerance of this one, and not otherwise.
     *
     * @param {Array}  rows
     * @param {Object} stats  from Heatmap.emptyStats()
     * @return {Array} {x, y, weight}
     */
    pointsFor( rows, stats ) {

        var points = [];
        var int = function( cell ) {
            return cell ? parseInt( cell.value, 10 ) : NaN;
        };

        for ( var i = 0; i < rows.length; i++ ) {

            var row = rows[i];

            if ( ! row ) {
                continue;
            }

            var weight = row.eventCount ? parseInt( row.eventCount.value, 10 ) : 1;
            weight = weight > 0 ? weight : 1;
            stats.clicks += weight;

            var anchor = row.elementAnchor ? String( row.elementAnchor.value ) : '';

            if ( /^[0-9]+$/.test( anchor ) ) {

                stats.elements.add( anchor );

                var el = this.anchorIndex().get( anchor );
                var at = el ? ElementAnchor.place( el, int( row.anchorX ), int( row.anchorY ), window ) : null;

                if ( ! at || isNaN( at.x ) || isNaN( at.y ) ) {
                    stats.notOnPage += weight;
                    continue;
                }

                stats.elementsFound.add( anchor );
                points.push( { x: at.x, y: at.y, weight: weight } );
                continue;
            }

            var x = int( row.clickX );
            var y = int( row.clickY );

            if ( isNaN( x ) || isNaN( y ) ) {
                stats.noPosition += weight;
                continue;
            }

            var width = int( row.viewportWidth );

            if ( isNaN( width ) || Math.abs( width - window.innerWidth ) > this.options.widthTolerance ) {
                stats.otherWidth += weight;
                continue;
            }

            points.push( { x: x, y: y, weight: weight } );
        }

        return points;
    }

    /** This page's elements by anchor, as of the last redraw. */
    anchorIndex() {

        if ( ! this.anchors ) {
            this.anchors = ElementAnchor.index( document );
        }

        return this.anchors;
    }

    /**
     * Places every fetched row again and repaints: after a fetch, and when the
     * window changes size.
     */
    redraw() {

        // Measured with the canvas out of the way, or it holds the page at the
        // size it was drawn for.
        if ( this.canvas && this.canvas.style ) {
            this.canvas.style.display = 'none';
            this.docDimensions = this.getDim( document );
            this.canvas.style.display = '';
            this.canvas.width = this.shadowCanvas.width = this.docDimensions.w;
            this.canvas.height = this.shadowCanvas.height = this.docDimensions.h;
        }

        this.shadowContext.clearRect( 0, 0, this.shadowCanvas.width, this.shadowCanvas.height );
        this.context.clearRect( 0, 0, this.shadowCanvas.width, this.shadowCanvas.height );

        this.anchors = null;
        this.stats = Heatmap.emptyStats();
        this.plotDotsRound( this.pointsFor( this.rows, this.stats ) );
        this.renderStats();
    }

    toggleStats() {

        var panel = jQuery('#owa_overlay_stats');
        var open = panel.prop('hidden');

        panel.prop('hidden', ! open);
        jQuery('#owa_overlay_stats_toggle').toggleClass('active', open).attr('aria-expanded', open ? 'true' : 'false');
    }

    /** The Stats panel's lines. A reason nothing fell under is left out. */
    statsLines() {

        var s = this.stats;
        var lines = [
            [ 'Clicks', s.clicks ],
            [ 'Drawn', s.drawn ],
            [ 'Elements clicked', s.elements.size ],
            [ 'Elements found on this page', s.elementsFound.size ]
        ];
        var notDrawn = [
            [ 'Not drawn: element not on this page', s.notOnPage ],
            [ 'Not drawn: recorded at another window width', s.otherWidth ],
            [ 'Not drawn: outside the page', s.offPage ],
            [ 'Not drawn: no position recorded', s.noPosition ]
        ];

        for ( var i = 0; i < notDrawn.length; i++ ) {
            if ( notDrawn[i][1] > 0 ) {
                lines.push( notDrawn[i] );
            }
        }

        return lines;
    }

    renderStats() {

        var lines = this.statsLines();
        var table = jQuery('<table></table>');

        for ( var i = 0; i < lines.length; i++ ) {
            table.append( jQuery('<tr></tr>')
                .append( jQuery('<th scope="row"></th>').text( lines[i][0] ) )
                .append( jQuery('<td></td>').text( lines[i][1].toLocaleString() ) ) );
        }

        jQuery('#owa_overlay_stats').empty().append( table );
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

            // Off the page entirely: nothing sensible to draw.
            if ( x < 0 || y < 0 || x > w || y > h ) {

                OWA_instance.debug( "skipping click outside the canvas: %s %s", x, y );
                if ( this.stats ) this.stats.offPage += data[i].weight || 1;
                continue;
            }

            if ( this.stats ) this.stats.drawn += data[i].weight || 1;

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