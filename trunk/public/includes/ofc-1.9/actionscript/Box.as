class Box
{
	public var top:Number=0;
	public var left:Number=0;
	public var right:Number=0;
	public var bottom:Number=0;
	public var width:Number=0;
	public var height:Number=0;
	
	// position of the zero line
	//public var zero:Number=0;
	//public var steps:Number=0;
	
	// set by 3D axis
	public var tick_offset:Number=0;
	
	public var count:Number = 0;
	
	private var minmax:MinMax;
	
	private var x_offset:Boolean;
	
	public function Box( top:Number, left:Number, right:Number, bottom:Number,
						minmax:MinMax,
						x_left_label_width:Number, x_right_label_width:Number,
						count:Number, jiggle:Boolean, three_d:Boolean,
						x_offset:Boolean )
	{
		
		var tmp_left:Number = left;
		
		if( jiggle )
		{
			right = this.jiggle( left, right, x_right_label_width, count );
			tmp_left = this.shrink_left( left, right, x_left_label_width, count );
		}
		
		this.top = top;
		this.left = Math.max(left,tmp_left);
		
		// round this down to the nearest int:
		this.right = Math.floor( right );
		this.bottom = bottom;
		this.width = this.right-this.left;
		this.height = bottom-top;
		
		//this.steps = this.height/(minmax.y_max-minmax.y_min);
		//this.zero = bottom-(steps*(minmax.y_min*-1));
		
		this.count = count;
		this.minmax = minmax;
		
		if( three_d )
		{
			// tell the box object that the 
			// X axis labels need to be offset
			this.tick_offset = 12;
		}
		
		//
		//  x_offset:
		//
		//   False            True
		//
		//  |               |
		//  |               |
		//  |               |
		//  +--+--+--+      |-+--+--+--+-+
		//  0  1  2  3        0  1  2  3 
		//
		this.x_offset = x_offset;
		
		if( !this.x_offset )
			this.count--;
	}
	
	//
	// if the last X label is wider than the chart area, the last few letters will
	// be outside the drawing area. So we make the chart width smaller so the label
	// will fit into the screen.
	//
	function jiggle( left:Number, right:Number, x_label_width:Number, count:Number )
	{
		var r:Number = 0;
		
		if( x_label_width != 0 )
		{
			var item_width:Number = (right-left) / count;
			var r:Number = right-(item_width/2);
			var new_right:Number = right;
			
			// while the right most X label is off the edge of the
			// Stage, move the box.right - 1
			while( r+(x_label_width/2) > right )
			{
				new_right -= 1;
				// changing the right also changes the item_width:
				item_width = (new_right-left) / count;
				r = new_right-(item_width/2);
			}
			right = new_right;
		}
		
		return right;
		
	}
	
	// if the left label is truncated, shrink the box until
	// it fits onto the screen
	function shrink_left( left:Number, right:Number, x_label_width:Number, count:Number )
	{
		var pos:Number = 0;

		if( x_label_width != 0 )
		{
			var item_width:Number = (right-left) / count;
			var pos:Number = left+(item_width/2);
			var new_left:Number = left;
			
			// while the left most label is hanging off the Stage
			// move the box.left in one pixel:
			while( pos-(x_label_width/2) < 0 )
			{
				new_left += 1;
				// changing the left also changes the item_width:
				item_width = (right-new_left) / count;
				pos = new_left+(item_width/2);
			}
			left = new_left;
		}
		
		return left;
		
	}
	
	//
	// the bottom point of a bar:
	//   min=-100 and max=100, use b.zero
	//   min = 10 and max = 20, use b.bottom
	//
	function getYbottom( right_axis:Boolean )
	{
		var min:Number = this.minmax.min( right_axis );
		return this.getY( Math.max(0,min), right_axis );
	}
	
	// takes a value and returns the screen Y location
	function getY_old( i:Number, right_axis:Boolean )
	{
		var steps:Number = this.height/(this.minmax.range( right_axis ));
		
		// find Y pos for value=zero
		var y:Number = this.bottom-(steps*(this.minmax.min( right_axis )*-1));
		
		// move up (-Y) to our point (don't forget that y_min will shift it down)
		y -= i*steps;
		return y;
	}
	
	// takes a value and returns the screen Y location
	function getY( i:Number, right_axis:Boolean )
	{
		var steps:Number = this.height/(this.minmax.range( right_axis ));
		var y:Number = this.bottom;
		
		// move up (-Y) to our point (don't forget that y_min will shift it down)
		return this.bottom-(this.minmax.min( right_axis )-i)*steps*-1;
	}
	
	function width_():Number
	{
		return this.right-this.left_();
	}
	
	function left_():Number
	{
		var padding_left:Number = this.tick_offset;
		return this.left+padding_left;
	}
	
	//
	// get the x position by value (e.g. what is the x position for -5 ?)
	//
	function get_x_pos_of_val( i:Number )
	{
		var item_width:Number = this.width_() / this.count;
		
		var pos:Number = i-this.minmax.x_min;
		
		var tmp = 0;
		if( this.x_offset )
			tmp = (item_width/2);
			
		return this.left_()+tmp+(pos*item_width);
	}
	
	//
	// get the x position of the n'th item
	//
	function get_x_pos( i:Number )
	{
		var item_width:Number = this.width_() / this.count;
		
		var tmp = 0;
		if( this.x_offset )
			tmp = (item_width/2);
			
		return this.left_()+tmp+(i*item_width);
	}
	
	//
	// get the position of the n'th X axis tick
	//
	function get_x_tick_pos( i:Number )
	{
		return this.get_x_pos(i) - this.tick_offset;
	}
	
	//
	// make a point object using the X position and absolute Y pos
	// e.g. x=wednesday, y=20
	//
	function make_point( x:Number, y:Number, right_axis:Boolean )
	{
		return new Point(
			this.get_x_pos( x ),
			this.getY( y, right_axis ),
			y
			);
	}
	
	//
	// make a point object, using the absolute values (e.g. -5,-5 )
	//
	function make_point_2( x:Number, y:Number, right_axis:Boolean )
	{
		return new Point(
			this.get_x_pos_of_val( x ),
			this.getY( y, right_axis ),
			y
			);
	}
	
	
	
	function make_point_bar( x:Number, y:Number, right_axis:Boolean, group:Number, group_count:Number )
	{
		
		var item_width:Number = this.width_() / this.count;
		
		// the bar(s) have gaps between them:
		var bar_set_width:Number = item_width*0.8;
		
		// get the margin between sets of bars:
		var tmp = 0;
		if( this.x_offset )
			tmp = item_width;
			
		var bar_left:Number = this.left_()+(tmp-bar_set_width)/2;
		// 1 bar == 100% wide, 2 bars = 50% wide each
		var bar_width:Number = bar_set_width/group_count;
		
		var left:Number = bar_left+(x*item_width);
		left += bar_width*group;
		
		return new PointBar(
			left,
			this.getY( y, right_axis ),
			bar_width-0.001,	// <-- hack so bars don't quite touch
			this.getYbottom( right_axis )
			);
	}
	
	function make_point_candle( x:Number, high:Number, open:Number, close:Number, low:Number, right_axis:Boolean, group:Number, group_count:Number )
	{
		
		var item_width:Number = this.width_() / this.count;
		
		// the bar(s) have gaps between them:
		var bar_set_width:Number = item_width*0.8;
		
		// get the margin between sets of bars:
		var bar_left:Number = this.left_()+((item_width-bar_set_width)/2);
		// 1 bar == 100% wide, 2 bars = 50% wide each
		var bar_width:Number = bar_set_width/group_count;
		
		var left:Number = bar_left+(x*item_width);
		left += bar_width*group;
		
		return new PointCandle(
			left,
			this.getY( high,  right_axis ),
			this.getY( open,  right_axis ),
			this.getY( close, right_axis ),
			this.getY( low,   right_axis ),
			high,
			bar_width,
			open
			);
		
	}
	
	function makePointHLC( x:Number, high:Number, close:Number, low:Number, right_axis:Boolean, group:Number, group_count:Number ) 
	{
 
		var item_width:Number = this.width_() / this.count;
		// the bar(s) have gaps between them:
		var bar_set_width:Number = item_width*1;

		// get the margin between sets of bars:
		var bar_left:Number = this.left_()+((item_width-bar_set_width)/2);
		// 1 bar == 100% wide, 2 bars = 50% wide each
		var bar_width:Number = bar_set_width/group_count;

		var left:Number = bar_left+(x*item_width);
		left += bar_width*group;

		return new PointHLC(
			left,
			this.getY( high, right_axis ),
			this.getY( close, right_axis ),
			this.getY( low, right_axis ),
			high,
			bar_width,
			close
			);
 
	}
}