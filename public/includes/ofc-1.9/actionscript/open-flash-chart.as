
// rectangle with rounded corners
//#include "rrectangle.as"
#include "prototype.drawCircle.as"
#include "prototype.fillCircle.as"
#include "String.prototype.replace.as"

MovieClip.prototype.rect = function( x:Number, y:Number, width:Number, height:Number, colour:Number, alpha:Number )
{

	this.beginFill( colour, 100 );
    this.moveTo( x, y );
    this.lineTo( x+width, y );
    this.lineTo( x+width, y+height );
    this.lineTo( x, y+height );
	this.lineTo( x, y );
    this.endFill();
	
	this._alpha = alpha;
	this._alpha_original = alpha;	// <-- remember our original alpha while tweening
	
}

MovieClip.prototype.rect2 = function( x:Number, y:Number, width:Number, height:Number, colour:Number, alpha:Number )
{
	this.beginFill( colour, alpha );
    this.moveTo( x, y );
    this.lineTo( x+width, y );
    this.lineTo( x+width, y+height );
    this.lineTo( x, y+height );
	this.lineTo( x, y );
    this.endFill();
}

function get_colour( col:String )
{
	if( col.substr(0,2) == '0x' )
		return Number(col);
		
	if( col.substr(0,1) == '#' )
		return Number( '0x'+col.substr(1,col.length) );
		
	if( col.length=6 )
		return Number( '0x'+col );
		
	// not recognised as a valid colour, so?
	return Number( col );
		
}

// why isn't this built into flash?
// make a number 1000 = 1,000
function format( i:Number )
{
	return NumberUtils.formatNumber (i);
}

function formatTime( sval:String )
{
	var val:Number = parseFloat(sval);
	var hours:Number = Math.floor(val);
	var minutes:Number = Math.round((val - hours) * 60);
	var rval:String = "" + hours;
	if (rval.length < 2) {
		rval = "0" + rval;
	}
	if (minutes < 10) {
		rval = rval + ":0" + minutes;
	} else {
		rval = rval + ":" + minutes;
	}
	return rval;
}

// added by NetVicious June 2007
function setContextualMenu()
{
	var contextual_menu:ContextMenu = new ContextMenu();
	var About:ContextMenuItem = new ContextMenuItem("About Open Flash Chart...");
	About.onSelect = function(obj, item) {
		// Go to project url
        getURL("javascript:popup=window.open('http://teethgrinder.co.uk/open-flash-chart/','ofc', 'toolbar=Yes,location=Yes,scrollbars=Yes,menubar=Yes,status=Yes,resizable=Yes,fullscreen=No'); popup.focus()");
	};
	/*
	If you want to remove default items of Flash (except conf and about) uncomment this line
	contextual_menu.hideBuiltInItems();
	
	
	createClassObject
	_root.menu = contextual_menu;
	*/
	contextual_menu.customItems.push(About);
	
	//
	// added by J. Vandervort <jvandervort@users.sourceforge.net> ( 15th Nov 2007 )
	//
	var MyPrint:ContextMenuItem = new ContextMenuItem("Print Chart...");
	MyPrint.onSelect = function(obj, item)
	{
		var pj:PrintJob = new PrintJob();
		if (pj.start()) {

			// save original _root size
			var r:Object = {
		  		width: _root._width,
				height: _root._height
		  	};
			
		  	// choose scalefactor from larger dimension
			if(r.width > r.height){
				var scaleFactor = pj.pageWidth/r.width;
			} else {
				var scaleFactor = pj.pageWidth/r.height;
			}
			// do the _root scaling
			_root._xscale = (scaleFactor*100) - 1;
		  	_root._yscale = (scaleFactor*100) - 1;
		  
			// add the page to the job and print
			if (pj.addPage(0, {xMin:0, xMax:Stage.width, yMin:0, yMax:Stage.height})) {
				pj.send();  // print page
			}
		  	// set original size back
		  	with(_root){
				_width = r.width;
				_height = r.height;
		  	}
	 	}
		delete pj;
	};	
	 
	contextual_menu.customItems.push(MyPrint);

	//If you want to remove default items of Flash (except conf
	// and about) uncomment this line
	contextual_menu.hideBuiltInItems();

	 
	createClassObject
	_root.menu = contextual_menu;
}

function TxtFormat(size:Number,colour:Number)
{
	var fmt:TextFormat = new TextFormat();
	fmt.color = colour;
	fmt.font = "Verdana";
	fmt.size = size;
	fmt.align = "center";
	return fmt;
}



function FadeIn()
{
	this.onEnterFrame = function ()
    {

		_root.show_tip(
			this,
			this._x,
			this._y-20,
			this.tooltip
			);
		
        if( this._alpha < 100 )
        {
            this._alpha += 10;
        }
        else
        {
			this._alpha = 100;
            delete this.onEnterFrame;
        }
    }
}

function FadeIn2()
{
	this.onEnterFrame = function ()
    {
        if( this._alpha < 100 )
        {
            this._alpha += 10;
        }
        else
        {
			this._alpha = 100;
            delete this.onEnterFrame;
        }
    }
}

function FadeOut()
{
	this.onEnterFrame = function ()
    {
			
        if( (this._alpha-5) > this._alpha_original )
        {
            this._alpha -= 5;
        }
        else
        {
			this._alpha = this._alpha_original;
			_root.hide_tip( this );
            delete this.onEnterFrame;
        }
    }
}


function hide_tip( owner:Object )
{
	if( _root.tooltip._owner == owner )
		removeMovieClip("tooltip");
}

function get_x_legend()
{
	if( _root._x_legend != undefined )
		return _root._x_legend.get_legend();
}

function get_tooltip_string()
{
	return _root.tool_tip_wrapper;
}

function get_x_axis_label( i:Number )
{
	return _root._x_axis_labels.get( i );
}

function get_background_colour()
{
	return _root._background.colour;
}

function format_y_axis_label( val:Number )
{
	if( _root._y_format != undefined )
	{
		var tmp:String = _root._y_format.replace('#val#',_root.format(val));
		tmp = tmp.replace('#val:time#',_root.formatTime(val));
		tmp = tmp.replace('#val:none#',String(val));
		tmp = tmp.replace('#val:number#', NumberUtils.formatNumber (Number(val)));		
		return tmp;
	}
	else
		return _root.format(val);
}

function show_tip( owner:Object, x:Number, y:Number, tip_obj:Object )
{
	if( ( _root.tooltip != undefined ) )
	{
		if(_root.tooltip._owner==owner)
			return;	// <-- it's our tooltip and it is showing
		else
			removeMovieClip("tooltip");	// <-- it is someone elses tootlip - remove it
	}
	
	var tmp:String;
	var lines:Array = [];
	//
	// Dirty hack. Takes a tool_tip_wrapper, and replaces the #val# with the
	// tool_tip text, so noew you can do: "My Val = $#val#%", which turns into:
	// "My Val = $12.00%"
	//
	if( _root.tool_tip_wrapper != undefined )
	{
		tmp = _root.tool_tip_wrapper.replace('#val#',tip_obj.value);
		tmp = tmp.replace('#key#',tip_obj.key);
		tmp = tmp.replace('#x_label#',tip_obj.x_label);
		tmp = tmp.replace('#val:time#',formatTime(tip_obj.value));
		
		if( _root._x_legend != undefined )
			tmp = tmp.replace('#x_legend#',_root._x_legend.get_legend());
	}
	else
	{
		if( tip_obj.x_label == undefined )
			tmp = tip_obj.value;
		else
			tmp = tip_obj.x_label+'<br>'+tip_obj.value;
	}
		
	lines = tmp.split( '<br>' );
	
	var tooltip = _root.createEmptyMovieClip( "tooltip", this.getNextHighestDepth() );
		
	// let the tooltip know who owns it, else we get weird race conditions where one
	// bar has onRollOver fired, then another has onRollOut and deletes the tooltip
	tooltip._owner = owner;

	tooltip.createTextField( "txt_title", tooltip.getNextHighestDepth(), 5, 5, 100, 100);
	if( lines.length > 1 )
		tooltip.txt_title.text = lines.shift();

	var fmt:TextFormat = new TextFormat();
	fmt.color = 0x0000F0;
	fmt.font = "Verdana";
	
	// this needs to be an option:
	fmt.bold = true;
	fmt.size = 12;
	fmt.align = "right";
	tooltip.txt_title.setTextFormat(fmt);
	tooltip.txt_title.autoSize="left";
	
	tooltip.createTextField( "txt", tooltip.getNextHighestDepth(), 5, tooltip.txt_title._height, 100, 100);
	
	tooltip.txt.text = lines.join( '\n' );
	
	var fmt2:TextFormat = new TextFormat();
	fmt2.color = 0x000000;
	fmt2.font = "Verdana";
	fmt2.size = 12;
	fmt2.align = "left";
	tooltip.txt.setTextFormat(fmt2);
	tooltip.txt.autoSize="left";

	var max_width:Number = Math.max( tooltip.txt_title._width, tooltip.txt._width );
	var y_pos:Number = y - tooltip.txt_title._height - tooltip.txt._height;
	
	if( y_pos < 0 )
	{
		// the tooltip has drifted off the top of the screen, move it down:
		y_pos = y + tooltip.txt_title._height + tooltip.txt._height;
	}
	
	var cstroke = {width:2, color:0x808080, alpha:100};
	var ccolor = {color:0xf0f0f0, alpha:100};

	ChartUtil.rrectangle(
		tooltip,
		max_width+10,
		tooltip.txt_title._height + tooltip.txt._height + 5,
		6,
		((x+max_width+16) > Stage.width ) ? (Stage.width-max_width-16) : x,
		y_pos,
		cstroke,
		ccolor);

	// NetVicious, June, 2007
	// create shadow filter
	var dropShadow = new flash.filters.DropShadowFilter();
	dropShadow.blurX = 4;
	dropShadow.blurY = 4;
	dropShadow.distance = 4;
	dropShadow.angle = 45;
	dropShadow.quality = 2;
	dropShadow.alpha = 0.5;
	// apply shadow filter
	tooltip.filters = [dropShadow];

}

function is_over( link:String )
{
	_root._inv.use_hand( link );
}

function is_out()
{
	_root._inv.use_arrow();
}


function mouse_over( ok:Boolean )
{
	var x:Number = _root._xmouse;
	var y:Number = _root._ymouse;
	
	if( !ok )
	{
		// tell everyone that the mouse is NOT over them
		x = -1;
		y = -1;
	}

	_root.chartValues.mouse_move( x, y );
}

// get the closest point from
// each data set.
function get_closest()
{
	var tmp:Array = [];
	var style:Style = null;
	
	for( var i:Number=0; i < _root.chartValues.styles.length; i++)
	{
		var style:Style = _root.chartValues.styles[i];
		
		// push the {ExPoint,distance} object
		tmp.push( style.closest( _root._xmouse, _root._ymouse ) );
	}
	
	//for( var i:Number=0; i < tmp.length; i++ )
	//	trace( i +'  '+tmp[i].distance_x );
	return tmp;
}

//_root.onMouseMove = function()
function mouse_move()
{
	if( _root.chartValues == undefined )
		return;
	
	// is the mouse over the invisible layer?
	if( !_root._inv.hitTest(_root._xmouse, _root._ymouse) )
		return;
	
	//
	mouse_over( true );
	
	// get closest points from each data set
	var closest:Array = _root.get_closest();

	// find closest point along X axis
	var min:Number = Number.MAX_VALUE;
	for( var i:Number=0; i < closest.length; i++ )
		min = Math.min( min, closest[i].distance_x );
	
	//for( var i:Number=0; i < closest.length; i++ )
	//	trace( i +'  '+closest[i].distance_x +'  '+ closest[i].distance_y );
	
	// now select all points that are the same distance
	// along the X axis
	var xx:Object = {point:null, distance_x:Number.MAX_VALUE, distance_y:Number.MAX_VALUE };
	for( var i:Number=0; i < closest.length; i++ )
	{
		if( closest[i].distance_x == min )
		{
			// these share the same X position, so choose
			// the closest to the mouse in the Y
			if( closest[i].distance_y < xx.distance_y )
				xx = closest[i];
		}
	}

	_root.tooltip_x.draw( xx.point );
	xx.point.is_tip = true;
	
	// make the line dot nice and big, does
	// nothing for bars
	for( var i:Number=0; i < _root.chartValues.styles.length; i++)
		_root.chartValues.styles[i].highlight_value();
}



function hide_oops()
{
	removeMovieClip("oops");
}

function oops( text:String )
{
	if( _root.oops != undefined )
	{
		hide_oops();
	}
	
	var mc:MovieClip = _root.createEmptyMovieClip( "oops", this.getNextHighestDepth() );
	mc.createTextField("txt", this.getNextHighestDepth(), 5, 5, 100, 100 );
	mc.txt.text = text;
	
	var fmt:TextFormat = new TextFormat();
	fmt.color = 0x000000;
	fmt.font = "Verdana";
	fmt.size = 12;
	fmt.align = "center";
	mc.txt.setTextFormat(fmt);
	mc.txt.autoSize="left";
	
	mc.txt.setTextFormat(fmt);
	
	var cstroke = {width:2, color:0x808080, alpha:100};
	var ccolor = {color:0xf0f0f0, alpha:100};
	
	ChartUtil.rrectangle(
		mc,
		mc.txt._width+10,
		mc.txt._height+10,
		6,
		(Stage.width/2)-((mc.txt._width+10)/2),
		(Stage.height/2)-((mc.txt._height+10)/2),
		cstroke,
		ccolor);
	
	var dropShadow = new flash.filters.DropShadowFilter();
	dropShadow.blurX = 4;
	dropShadow.blurY = 4;
	dropShadow.distance = 4;
	dropShadow.angle = 45;
	dropShadow.quality = 2;
	dropShadow.alpha = 0.5;
	// apply shadow filter
	//mc.filters = [dropShadow];
}

function make_pie()
{
	_root._pie = new PieStyle( this, 'pie' );
	_root._title = new Title( this );
}


function make_chart()
{
	//
	// the order that these are built determines their Z order:
	//
	_root._inner_background = new InnerBackground( this );
	
	_root._min_max = new MinMax( this );
	
	// should the X Axis fit bar charts
	// see Box.as for details
	_root._x_offset = true;
	if( this.x_offset != undefined )
		_root._x_offset = (this.x_offset!='false');

	// we build the graph from top to bottom 
	_root._title = new Title( this );
	_root._x_legend = new XLegend( this );
	_root._y_legend = new YLegend( this , 1);
	
	if( this.show_y2 ) 
		_root._y2_legend = new YLegend( this , 2);
	
	var xTicks = 5;
	if( this.x_ticks != undefined )
		xTicks = Number( this.x_ticks );

	// size, colour
	var x_label_style:XLabelStyle = new XLabelStyle( this );
	var y_label_style:YLabelStyle = new YLabelStyle( this, 1 );
	
	if( this.show_y2 ) 
		var y_label_style2:YLabelStyle = new YLabelStyle( this, 2 );
		
	
	// create X labels and measure the height:	
	_root._x_axis_labels = new XAxisLabels( this, x_label_style, _root._min_max );

	var xSteps = 1;
	if( this.x_axis_steps != undefined )
		xStep = Number( this.x_axis_steps );

	_root._x_axis = new XAxis(
		xTicks,									// <-- tick size
		this,
		xStep
		);

	_root._y_ticks = new YTicks( this );
	
	// format the Y Axis numbers
	_root._y_format = null;
	if( this.y_format != undefined )
		_root._y_format = this.y_format;
		
	_root._y_axis_labels = new YAxisLabels(
		y_label_style,
		_root._min_max.y_min,
		_root._min_max.y_max,
		_root._y_ticks.steps,
		1,
		this
		);

	if( this.show_y2 )
	{
		_root._y_axis_labels2 = new YAxisLabels(
			y_label_style2,
			_root._min_max.y2_min,
			_root._min_max.y2_max,
			_root._y_ticks.steps,
			2,
			this
			);
	}
	

	_root._y_axis = new YAxis(
		_root._y_ticks,
		this,
		_root._min_max.y_min,
		_root._min_max.y_max,
		_root._y_ticks.steps,
		1
		);
	
	if( this.show_y2 )
	{
		_root._y_axis2 = new YAxis(
			_root._y_ticks,
			this,
			_root._min_max.y_min,
			_root._min_max.y_max,
			_root._y_ticks.steps,
			2
			);
	}
			
	// The chart values are defined last and are on TOP of every thing else
	_root.chartValues = new Values( this, _root._x_axis_labels.labels );
	
	// tell the x axis where the grid lines are:
	if( _root._min_max.has_x_range )
	{
		// the user has specified the X axis min and max
		// this is used in scatter charts
		_root._x_axis.set_grid_count(
			_root._min_max.x_max-_root._min_max.x_min+1
			);
	}
	else
	{
		// the user has not told us how long the X axis
		// is, so we figure it out:
		_root._x_axis.set_grid_count(
			Math.max( _root._x_axis_labels.count(), _root.chartValues.length() )
			);
	}

	_root._keys = new Keys(
		(_root._y_legend.width()+_root._y_axis_labels.width()+_root._y_axis.width()),		// <-- from left
		_root._title.height(),											// <-- from top
		_root.chartValues.styles );

	// this is last and floats over everything!
	_root.tooltip_x = new Tooltip();
	
	//
	// HACK!!
	// This is an invisible MovieClip that is on top of all
	// MovieClips, all it does is detect if the mouse has left
	// the flash movie (.swf) and remove the tooltip
	//
	_root._inv = new Invisible();
	
}

function LoadVarsOnLoad( success )
{
	if( !success )
	{
		_root.loading.done();
		//_root.oops('Open Flash Chart: Error opening data file URL\n'+_root.data);
		_root.oops(_root.data);
		return;
	}

	// remove loading data... message
	if( _root.oops != undefined )
		removeMovieClip("oops");	
	
	//added by Maik Fox
	//complete cleanup, no known side effects yet  ;) 
	for(i in _root)
	{
		//if i is a movie clip, remove it
		if(typeof(_root[i])=='movieclip')
		{
			removeMovieClip(_root[i]);
		}
		//delete it so that the garbage collector can collect it
		delete i;       
	}
	
	_root.css = new Css('margin-top: 30;margin-right: 40;');

	NumberFormat.getInstance(this);
	NumberFormat.getInstanceY2(this);
	
	//
	// Now we build the objects, the order in which we
	// build them determins their Z position.
	//
	_root._background = new Background( this );
	
	
	//
	// if we have a pie chart, we don't build the
	// axis, grid and stuff..
	//
	if( this.pie != undefined )
		this.make_pie();
	else
		this.make_chart();

	
	if( this.tool_tip != undefined )
	{
		_root.tool_tip_wrapper = this.tool_tip.replace('#comma#',',');
	}
	
	_root.loading.done();
	_root.move();
}

function move()
{
	if( _root._pie != undefined )
	{
		_root._background.move();
		_root._title.move();
		_root._pie.draw( _root._title.height() );
		return;
	}
	
	//
	// move items that may resize themselves:
	//
	_root._keys.move();

	//
	// measure the box:
	//
	var top:Number = _root._title.height()+_root._keys.height();
	var left:Number = _root._y_legend.width()+_root._y_axis_labels.width()+_root._y_axis.width();
	var right:Number = Stage.width;
	// do we jiggle the box smaller because the last X Axis label
	// is hanging off the end of the screen?
	var jiggle:Boolean = true;
	
	if( _root._y_axis2 != undefined )
	{
		right -= _root._y2_legend.width()+_root._y_axis_labels2.width()+_root._y_axis2.width();
		// no need to shrink the box:
		jiggle = false;
	}
	
	var bottom:Number = Stage.height-(_root._x_axis_labels.height()+_root._x_legend.height()+_root._x_axis.height())
 	
	var b:Box = new Box(
		top, left, right, bottom,
		_root._min_max,					// <-- scale everything between min/max
		_root._x_axis_labels.first_label_width(),
		_root._x_axis_labels.last_label_width(),
		_root._x_axis.get_grid_count(),
		jiggle,
		_root._x_axis.three_d,
		_root._x_offset
		);
		

	//
	// tell everything else to move, the order in
	// which we .move() things doesn't matter because
	// they allready have their z-order assigned.
	//
	_root._background.move();
	_root._inner_background.move( b );
	_root._title.move();
	_root._x_legend.move();
	_root._y_legend.move(1);

	
	if( _root._y_axis2 != undefined )
		_root._y2_legend.move(2);
	
	_root._y_axis_labels.move( _root._y_legend.width(), b );

	// position of second y axel labels..
	if( _root._y_axis2 != undefined )
		_root._y_axis_labels2.move( Stage.width-(_root._y2_legend.width()+_root._y_axis_labels2.width()), b );

	_root._x_axis.move( b );
	
	// move x labels
	_root._x_axis_labels.move(
		Stage.height-(_root._x_legend.height()+_root._x_axis_labels.height()),	// <-- up from the bottom
		b );
	
	_root._y_axis.move( b , 1);
	
	if( _root._y_axis2 != undefined )	
		_root._y_axis2.move( b );
	
	_root.chartValues.move(
		b,
		_root._min_max.y_min,
		_root._min_max.y_max,					// <-- scale everything between min/max
		_root._min_max.y2_min,
		_root._min_max.y2_max
		);
	
	_root._inv.move( b );
	
}

//
// test JS to flash coms
//
import flash.external.*;
ExternalInterface.addCallback("set_title", null, setTitle);
function setTitle(str:String):Void
{
	if( _root._title != undefined )
	{
		_root._title.build( str );
		_root.move();
	}
	// for debuggig:
	//_root.oops(str);
}

ExternalInterface.addCallback("push_value", null, pushValue);
function pushValue( set:Number, val:String, label:String ):Void
{
	if( set<_root.chartValues.length() )
	{
		_root.chartValues.styles[set].add( Number( val ), label );
		_root._x_axis_labels.add(label);
		// tell the x axis where the grid lines are:
		_root._x_axis.set_grid_count( _root.chartValues.length() );
		_root.move();
	}
}

ExternalInterface.addCallback("delete_value", null, deleteValue);
function deleteValue( set:Number ):Void
{
	if( set<_root.chartValues.length() )
	{
		_root.chartValues.styles[0].del();
		_root._x_axis_labels.del();
		// tell the x axis where the grid lines are:
		_root._x_axis.set_grid_count( _root.chartValues.length() );
		_root.move();
	}
}

ExternalInterface.addCallback("show_message", null, show_message);
function show_message( msg:String ):Void
{
	_root.oops(msg);
}

ExternalInterface.addCallback("hide_message", null, hide_message);
function hide_message():Void
{
	hide_oops();
}

ExternalInterface.addCallback("reload", null, reload);
function reload( u:String, show_loading:Boolean ):Void
{
	if( show_loading == undefined )
		show_loading = true;
		
	if( show_loading )
	{
		// inform the user we are reloading data:
		_root.loading = new Loading('Loading data...');
	}

	var url:String = '';
	
	if( _root.data != undefined )
		url = _root.data;
	
	if( u != undefined )
	{
		if( u.length > 0 )
		{
			url = u;
		}
	}
	//setTitle( u );
	
	//
	// this bit of code has had a patchy history,
	// we don't need a new LoadVars object, but
	// if we don't delete it, the object holds
	// onto the old variabels. So say we load 2
	// data sets, then load 1 data set, we end up
	// with one new set and one old set. So we
	// *do* need to delete it, just to get rid of
	// the old data :-)
	//
	_root.lv = undefined;
	_root.lv = new LoadVars();
	_root.lv.onLoad = LoadVarsOnLoad;
	_root.lv.make_chart = make_chart;
	_root.lv.make_pie = make_pie;
	_root.lv.load(url);
	//
	// ----- end -----
	//
}


//
//
//
// ********************************************************************************

_root.loading = new Loading('Loading data...');	

// so we can rotate text:
this.embedFonts = true;

_root.chartValues = new Array();

// -------------------------------------------------------------+
//
// tell flash to align top left, and not to scale
// anything (we do that in the code)
//
Stage.align = "LT";
//
// ----- RESIZE ----
//
// noScale: now we can pick up resize events
Stage.scaleMode = "noScale";
//
var stageListener:Object = new Object();
stageListener.onResize = function()
{
	//trace("w:"+Stage.width+", h:"+Stage.height);
	_root.move();
};
Stage.addListener(stageListener);
//
// ------ END RESIZE ----
//
//

// NetVicious, June 2007
// Right click menu:
setContextualMenu();

//
// LOAD THE DATA
//

var lv:LoadVars = new LoadVars();

//lv.onLoad = function( success )
lv.onLoad = LoadVarsOnLoad;
lv.make_chart = make_chart;
lv.make_pie = make_pie;

// from URL
if( _root.data == undefined )
{
	if( _root.variables == undefined )
	{
		//
		// We are in the IDE
		//
		_root.data="C:\\Users\\John\\Documents\\flash\\svn\\data-files\\data-47.txt";
		//_root.data="http://www.stelteronline.de/index.php?option=com_joomleague&func=showStats_GetChartData&p=1";
		lv.load(_root.data);
	}
	else
	{
		//
		// Load from inline HTML variables
		//
		_root.LoadVarsOnLoad = LoadVarsOnLoad;
		_root.LoadVarsOnLoad( true );
	}
}
else
{
	//
	// An external file
	//
	lv.load(_root.data);
}

stop();
