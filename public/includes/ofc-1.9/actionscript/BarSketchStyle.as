class BarSketchStyle extends FilledBarStyle
{
	private var offset:Number;
	
	public function BarSketchStyle( lv:Object, name:String )
	{
		this.name = 'bar_sketch'+name;
		this.parse( lv[this.name] );
		this.set_values( lv['values'+name], lv['links'+name], lv['tool_tips_set'+name] );
	}
	
	public function parse( val:String )
	{
		var vals:Array = val.split(",");
		
		this.alpha = Number( vals[0] );
		this.offset = Number( vals[1] );
		this.colour = _root.get_colour( vals[2] );
		this.outline_colour = _root.get_colour( vals[3] );
		
		if( vals.length > 4 )
			this.key = vals[4];
			
		if( vals.length > 5 )
			this.font_size = Number( vals[5] );
		
	}
	
	public function draw_bar( val:PointBar, i:Number )
	{
		var top:Number;
		var height:Number;
		
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
		
		var mc:MovieClip = this.bar_mcs[i];
		mc.clear();
		
		// how sketchy the bar is:
		var offset:Number = this.offset;
		var o2:Number = offset/2;
		
		// fill the bar
		// number of pen strokes:
		var strokes:Number = 6;
		// how wide each pen will need to be:
		var l_width:Number = val.width/strokes;
		
		mc.lineStyle(l_width+1, this.colour, 85, true, "none", "round", "miter", 0.8);
		for( var i:Number=0; i<strokes; i++)
		{
			mc.moveTo( ((l_width*i)+(l_width/2))+(Math.random()*offset-o2), 2+(Math.random()*offset-o2) );
    		mc.lineTo( ((l_width*i)+(l_width/2))+(Math.random()*offset-o2), height-2+ (Math.random()*offset-o2) );
		}
		
		// outlines:
		mc.lineStyle( 2, this.outline_colour, 100 );
		// left upright
    	mc.moveTo( Math.random()*offset-o2, Math.random()*offset-o2 );
    	mc.lineTo( Math.random()*offset-o2, height+Math.random()*offset-o2 );
		
		// top
		mc.moveTo( Math.random()*offset-o2, Math.random()*offset-o2 );
    	mc.lineTo( val.width+ (Math.random()*offset-o2), Math.random()*offset-o2 );
		
		// right upright
    	mc.moveTo( val.width+ (Math.random()*offset-o2), Math.random()*offset-o2 );
    	mc.lineTo( val.width+ (Math.random()*offset-o2), height+ (Math.random()*offset-o2) );
		
		// bottom
		mc.moveTo( Math.random()*offset-o2, height+ (Math.random()*offset-o2) );
    	mc.lineTo( val.width+ (Math.random()*offset-o2), height+ (Math.random()*offset-o2) );
		
		
		mc._x = val.x;
		mc._y = top;
		
		mc._alpha = this.alpha;
		mc._alpha_original = this.alpha;	// <-- remember our original alpha while tweening
	}
}