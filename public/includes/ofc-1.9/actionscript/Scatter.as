class Scatter extends Style
{
	public var bgColour:Number=0;
	private var mc:MovieClip;
	private var mc2:MovieClip;
	public var name:String;
	
	public function Scatter( lv:Object, name:String )
	{
		this.bgColour =  _root.get_background_colour();
		
		this.name = 'scatter'+name;
		var vals:Array = lv[this.name].split(",");
		
		this.line_width = Number( vals[0] );
		this.colour = _root.get_colour( vals[1] );
		
		if( vals.length > 2 )
			this.key = vals[2];
			
		if( vals.length > 3 )
			this.font_size = Number( vals[3] );
		
		if( length( vals ) > 4 )
			this.circle_size = Number( vals[4] );
			
		this.mc = _root.createEmptyMovieClip(name, _root.getNextHighestDepth());
		this.mc2 = _root.createEmptyMovieClip(name, _root.getNextHighestDepth());
		this.mc2.fillCircle( 0, 0, 7, 15, 0xFFFFFF );
		this.mc2.fillCircle( 0, 0, 5, 15, this.colour );
		this.mc2._visible = false;
		
		this.set_values( lv['values'+name] );
	}
	
	// a group looks like "[x,y]"
	private function parse_group( g:String )
	{
		var group:Array = g.split(',');
		this.values.push(
			{
				x: Number( group[0] ),
				y: Number( group[1] ),
				size: Number( group[2] )
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
	function set_values( v:String )
	{
		this.values = new Array();
		
		var groups:Array = this.groups( v );
		for( var i=0; i<groups.length; i++ )
			this.parse_group( groups[i] );
	}
	
	public function valPos( b:Box, right_axis:Boolean, min:Number )
	{
		this.ExPoints=Array();
		
		var x_legend:String = '';
		if( _root._x_legend != undefined )
			
					
		for( var i:Number=0; i < this.values.length; i++)
		{
			
			//
			// NOTE: scatter charts do not have null data points
			//
			var p:Object = this.values[i];
			
			var tmp:PointScatter = b.make_point_2( p.x, p.y, right_axis );
			
			tmp.size = p.size;
			
			tmp.make_tooltip(
				_root.get_tooltip_string(),
				this.key,
				Number(p.y),
				_root.get_x_legend(),
				//
				// Note: because this is a scatter chart,
				// not all points will have an X axis label,
				// so we pass in the x value instead.
				//
				//_root.get_x_axis_label(i)
				p.x
				);
				
			this.ExPoints.push( tmp );
		}
	}
	
	// Draw lines...
	public function draw()
	{
		this.mc.clear();
		this.mc.lineStyle( this.line_width, this.colour, 100); // <-- alpha 0 to 100
		
		for( var i:Number=0; i < this.ExPoints.length; i++ )
		{
			var val:PointScatter = this.ExPoints[i];
			this.mc.lineStyle( 0, 0, 0);
			this.mc.fillCircle( val.x, val.y, val.size, 15, this.colour );
			this.mc.fillCircle( val.x, val.y, val.size-this.line_width, 15, this.bgColour );
		}
	}
	
	public function highlight_value()
	{
		var found:Boolean = false;
		
		for( var i:Number=0; i < this.ExPoints.length; i++ )
		{
			if( this.ExPoints[i].is_tip )
			{
				this.mc2._x = this.ExPoints[i].x;
				this.mc2._y = this.ExPoints[i].y;
				this.mc2._visible = true;
				found = true;
				break;
			}
		}
		if( !found )
			this.mc2._visible = false;
	}
	
	private function rollOver()
	{}
	
	public function closest( x:Number, y:Number )
	{
		//
		// because this is a scatter chart, we may have
		// many items for the same X axis value, so we 
		// keep them all, then find the closest to the
		// Y position (see data-32.txt for a test)
		//
		var shortest:Number = Number.MAX_VALUE;
		
		// find the closest point(s) in X
		for( var i:Number=0; i < this.ExPoints.length; i++)
		{
			this.ExPoints[i].is_tip = false;
			
			var dx:Number = Math.abs( x - this.ExPoints[i].x );
		
			if( dx < shortest )
				shortest = dx;
		}
		
		var points:Array = Array();
		// get all the points at this X distance
		for( var i:Number=0; i < this.ExPoints.length; i++)
		{
			var dx:Number = Math.abs( x - this.ExPoints[i].x );
		
			if( dx == shortest )
				points.push( this.ExPoints[i] );
		}
		var dist_x = shortest;
		
		//
		// find the closest in the Y
		//
		shortest = Number.MAX_VALUE;
		var point:Point = null;
		
		for( var i:Number=0; i < points.length; i++)
		{
			var dy:Number = Math.abs( y - points[i].y );
			
			if( dy < shortest ) 
			{
				shortest = dy;
				point = points[i];
			}
		}
		
		var dy:Number = Math.abs( y - point.y );
		return { point:point, distance_x:dist_x, distance_y:dy };
	}
	
	public function move_dot( val:Point, mc:MovieClip )
	{
		//trace(val.center);
		// Move and fix the dots...
		mc._x = val.x;
		mc._y = val.y;
	}
	
	public function is_over( x:Number, y:Number )
	{
		if( x<0 )
			this.mc2._visible = false;
	}
	
}