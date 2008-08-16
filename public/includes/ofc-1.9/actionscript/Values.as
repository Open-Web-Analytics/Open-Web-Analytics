class Values
{
	public var styles:Array;
	
	private var attach_right:Array;

	public function Values( lv:LoadVars, x_axis_labels:Array )
	{
		this.styles = [];
		var name:String = '';
		var c:Number=1;
		
		do
		{
			if( c>1 ) name = '_'+c;
			
			if( lv['values'+name ] != undefined )
			{
				this.styles[c-1] = this.make_style( lv, name, c );
			
				//
				// BUG: These need to be fixed at some point:
				//
				if( lv['candle'+name] != undefined )
					this.styles[c-1].set_values( lv['values'+name], x_axis_labels, lv['links'+name] );
				else if( lv['hlc'+name] != undefined )
					this.styles[c-1].set_values( lv['values'+name], x_axis_labels, lv['links'+name] );

			}
			else
				break;		// <-- stop loading data
				
			c++;
		}
		while( true );
	
	
		var y2:Boolean = false;
		var y2lines:Array;
		
		//
		// some data sets are attached to the right
		// Y axis (and min max)
		//
		this.attach_right = Array();
			
		if( lv.show_y2 != undefined )
			if( lv.show_y2 != 'false' )
				if( lv.y2_lines != undefined )
				{
					this.attach_right = lv.y2_lines.split(",");
				}
	}
	
	
	
	private function make_style( lv:LoadVars, name:String, c:Number )
	{
		if( lv['line'+name] != undefined )
			return new LineStyle(lv,name);
		else if( lv['line_dot'+name] != undefined )
			return new LineDot(lv,name);
		else if( lv['line_hollow'+name] != undefined )
			return new LineHollow(lv,name);
		else if( lv['area_hollow'+name] != undefined )
			return new AreaHollow(lv,name);
		else if( lv['bar'+name] != undefined )
			return new BarStyle(lv,name);
		else if( lv['filled_bar'+name] != undefined )
			return new FilledBarStyle(lv,name);
		else if( lv['bar_glass'+name] != undefined )
			return new BarGlassStyle(lv,name);
		else if( lv['bar_fade'+name] != undefined )
			return new BarFade(lv,name);
		else if( lv['bar_zebra'+name] != undefined )
			return new BarZebra(lv['bar_zebra'+name],'bar_'+c);
		else if( lv['bar_arrow'+name] != undefined )
			return new BarArrow(lv,name);
		else if( lv['bar_3d'+name] != undefined )
			return new Bar3D(lv,name);
		else if( lv['pie'+name] != undefined )
			return new PieStyle(lv,name);
		else if( lv['candle'+name] != undefined )
			return new CandleStyle(lv,name);
		else if( lv['scatter'+name] != undefined )
			return new Scatter(lv,name);
		else if( lv['hlc'+name] != undefined )
			return new HLCStyle(lv,name);
		else if( lv['bar_sketch'+name] != undefined )
			return new BarSketchStyle(lv,name);
			
	}
	
	private function parseVal( val:String ):Array
	{
		var tmp:Array = Array();
		
		var vals:Array = val.split(",");
		for( var i:Number=0; i < vals.length; i++ )
		{
			tmp.push( vals[i] );
		}
		return tmp;
	}
	
	public function length()
	{
		var max:Number = -1;

		for(var i:Number=0; i<this.styles.length; i++ )
			max = Math.max( max, this.styles[i].values.length );

		return max;
	}
	
	function _count_bars()
	{
		// count how many sets of bars we have
		var bar_count:Number = 0;
		for( var i=0; i<this.styles.length; i++ )
			if( this.styles[i].is_bar )
				bar_count++;

		return bar_count;
	}
	
	// If the current line is to be drawn on y2 (defined in data values, y2_lines)
	private function is_right( y2lines:Array, line:Number )
	{
		var right:Boolean = false;
		for( var i:Number=0; i<y2lines.length; i++ )
		{
			if(y2lines[i] == line)
				right = true; 
		}
		return right;
	}
	
	function _do_it()
	{
		
	}
	
	// get x, y co-ords of vals
	function move( b:Box, min:Number, max:Number, min2:Number, max2:Number )
	{
		
		var bar_count:Number = this._count_bars();
		var bar:Number = 0;
		
			
		for( var c:Number=0; c<this.styles.length; c++ )
		{
			var right_axis:Boolean = false;
				
			// move values...
			if( this.is_right( this.attach_right, c+1 ) )
				right_axis = true;

			this.styles[c].valPos( b, right_axis, min, bar_count, bar );
			if( this.styles[c].is_bar )
				bar++;
		}
			
		// draw the bars and dots ontop of the line
		for( var c:Number=0; c < this.styles.length; c++ )
		{
			this.styles[c].draw();
		}
	}
	
	//
	// tell all out lines and bars that the mouse has moved and
	// some will need to Fade In and some Fade Out
	//
	public function mouse_move( x:Number, y:Number )
	{
		for( var i:Number=0; i < this.styles.length; i++)
		{
			if( this.styles[i].is_over( x, y ) )
				this.styles[i].fade_in();
		}
	}
}