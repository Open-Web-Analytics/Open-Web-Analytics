class CandleStyle extends BarStyle
{
	public var is_bar:Boolean = true;
	
	// MovieClip that holds each bar:
	private var bar_mcs:Array;
	public var name:String;
	private var line_width = 3;
	
	var links:Array;
	
	public function CandleStyle( lv:Object, name:String )
	{
		this.name = 'candle'+name;
		// this calls parent obj Style.Style first
		this.parse_bar( lv[this.name] );
		//this.set_values( lv['values'+name], lv['links'+name], lv['tool_tips_set'+name] );
		
		this.links = new Array();
	}
	
	public function parse_bar( val:String )
	{
		var vals:Array = val.split(",");
	
		this.alpha = Number( vals[0] );
		this.line_width = Number( vals[1] );
		this.colour = _root.get_colour(vals[2]);
		
		if( vals.length > 3 )
			this.key = vals[3];
			
		if( vals.length > 4 )
			this.font_size = Number( vals[4] );
		
	}
	
	// a group looks like "[1,2,3,4]"
	private function parse_group( g:String )
	{
		var group:Array = g.split(',');
		this.values.push(
			{
				high: Number( group[0] ),
				open: Number( group[1] ),
				close: Number( group[2] ),
				low: Number( group[3] )
			}
			);
	}
	
	function groups( vals:String )
	{
		var groups:Array=new Array();
		var tmp:String = '';
		var start:Boolean = false;

		for( var i=0; i<vals.length; i++ )
		{
			switch( vals.charAt(i) )
			{
				case '[':
					start=true;
					break;
				case ']':
					start = false;
					groups.push( tmp );
					tmp = '';
					break;
				default:
					if( start )
						tmp += vals.charAt(i);
					break;
			}
		}
		return groups;
	}

	// override Style:set_values
	function set_values( v:String, labels:Array, links:String )
	{
		//super.set_values( v );
		this.values = new Array();
		
		if( links != undefined )
			this.links = links.split(',');
		
		var groups:Array = this.groups( v );
		for( var i=0; i<groups.length; i++ )
			this.parse_group( groups[i] );
		
		// make an empty array to hold each bar MovieClip:
		this.bar_mcs = new Array( this.values.length );
		
		for( var i:Number=0; i < this.values.length; i++ )
		{
			var mc:MovieClip = _root.createEmptyMovieClip( this.name+'_'+i, _root.getNextHighestDepth() );
		
			mc.onRollOver = _root.FadeIn2;
			mc.onRollOut = _root.FadeOut;
			
			if( this.links.length>i )
			{
				mc._ofc_link = this.links[i];
				mc.onRelease = function ():Void { trace(this._ofc_link); getURL(this._ofc_link); };
				mc.useHandCursor = true;
			}
			else
				mc.useHandCursor = false;
			
			//mc.onRollOver = ChartUtil.glowIn;
			
			// this is used in FadeIn and FadeOut
			//mc.tool_tip_title = labels[i];
			var tooltip:Object = {x_label:labels[i], value:this.values[i], key:this.key};
			mc.tooltip = tooltip;
		
			// add the MovieClip to our array:
			this.bar_mcs[i] = mc;
		}
	}
	
	public function valPos( b:Box, right_axis:Boolean, min:Number, bar_count:Number, bar:Number )
	{
		this.ExPoints=Array();
		
		var item_width:Number = b.width_() / this.values.length;
		
		// the bar(s) have gaps between them:
		var bar_set_width:Number = item_width*_root._bars_width;
		// get the margin between sets of bars:
		var bar_left:Number = b.left_()+((item_width-bar_set_width)/2);
		// 1 bar == 100% wide, 2 bars = 50% wide each
		var bar_width:Number = bar_set_width/bar_count;
		
		for( var i:Number=0; i < this.values.length; i++)
		{
			var tmp:PointCandle = b.make_point_candle(
					i,
					this.values[i].high,
					this.values[i].open,
					this.values[i].close,
					this.values[i].low,
					right_axis,
					bar,
					bar_count
					);
			
			tmp.make_tooltip(
				_root.get_tooltip_string(),
				this.key,
				this.values[i],
				_root.get_x_legend(),
				_root.get_x_axis_label(i)
				);
			
			//var tooltip:Object = {x_label:labels[i], value:this.values[i], key:this.key};
			//mc.tooltip = tooltip;
			
			this.ExPoints.push( tmp );
		}
	}
	
	public function draw_bar( val:Object, i:Number )
	{
		var top:Number;
		var height:Number;
		var line_width:Number = this.line_width;
		
		var hollow:Boolean = false;
		
		if( val.open > val.close )
		{
			hollow = true;
			var tmp:Number = val.open;
			val.open = val.close;
			val.close = tmp;
		}
/*
		if(val.bar_bottom<val.y)
		{
			top = val.bar_bottom;
			height = val.y-val.bar_bottom;
		}
		else
		{
			top = val.y
			height = val.bar_bottom-val.y;
		}
*/
		height = val.close-val.open;
		var center:Number = val.width/2;
		
		var mc:MovieClip = this.bar_mcs[i];
		mc.clear();
		mc._alpha = 100;

		// top line
		mc.rect2( center-(line_width/2), 0, line_width, val.open-val.high, this.colour, 100 );
		
		// middle box
		if( !hollow )
			mc.rect2( 0, val.open-val.high, val.width, height, this.colour, 100 );
		else
		{
			mc.rect2( 0, val.open-val.high, val.width, line_width, this.colour, 100 );
			mc.rect2( 0, val.open-val.high+line_width, line_width, height-(2*line_width), this.colour, 100 );
			mc.rect2( val.width-line_width, val.open-val.high+line_width, line_width, height-(2*line_width), this.colour, 100 );
			mc.rect2( 0, val.open-val.high+height-line_width, val.width, line_width, this.colour, 100 );
		}
		
		// make an invisible rectangle for the tooltip:
		mc.rect2( 0, 0, val.width, val.low-val.high, 0xff0000, 0 );
		
		// bottom line
		mc.rect2( center-(line_width/2), val.open-val.high+height, line_width, val.low-val.close, this.colour, 100 );
		
		mc._x = val.x;
		mc._y = val.high;
	
		mc._alpha = this.alpha;
		mc._alpha_original = this.alpha;	// <-- remember our original alpha while tweening
		
		// this is used in _root.FadeIn and _root.FadeOut
		//mc.val = val;
		
		// we return this MovieClip to FilledBarStyle
		return mc;
	}
}