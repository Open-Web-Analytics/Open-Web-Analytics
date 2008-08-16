class BarArrow extends BarStyle
{
	public function BarArrow( lv:Object, name:String )
	{
		this.name = 'bar_arrow'+name;
		// this calls parent obj Style.Style first
		this.parse_bar( lv[this.name] );
		this.set_values( lv['values'+name], lv['links'+name], lv['tool_tips_set'+name] );
	}
	
	public function draw_bar( val:PointBar, i:Number )
	{
		var mc:MovieClip = super.draw_bar( val, i );
		
		//var mc:MovieClip = this.bar_mcs[i];
		
		mc.lineStyle( 2, 0x000000, 100);
		
		mc.moveTo( val.x+(val.width/2), val.bar_bottom );
		
		var steps:Number = Math.floor( 4+Math.random()*4);
		var height:Number = (val.bar_bottom-val.y)/steps;
		var x:Number;
		var y:Number;
		
		for( var i:Number=1; i<steps; i++ )
		{
			x = Math.random()*(val.width/2);
			y = val.bar_bottom-(height*i)
			
			// zig-zag the line:
			if( i%2==0 )
				x += val.x;
			else
				x = val.x+val.width-x;
				
			mc.lineTo( x, y );
		}
		mc.lineTo( val.x+(val.width/2), val.y );
		
		//
		//
		//
		
		mc.moveTo( val.x+(val.width/2), val.bar_bottom );
		
		var steps:Number = 8;
		var height:Number = (val.bar_bottom-val.y)/steps;
		var x:Number;
		var y:Number;
		
		var prev_x:Number = val.x+(val.width/2);
		var prev_y:Number = val.bar_bottom;
		
		for( var i:Number=1; i<steps; i++ )
		{
//			x = Math.random()*(val.bar_width/2);
			y = val.bar_bottom-(height*i)
			
			// zig-zag the line:
			if( i%2==0 )
				x = val.x;
			else
				x = val.x+val.width;
				
			mc.curveTo( x, y, val.x+(val.width/2), val.bar_bottom-(height*(i+1)) );
		}
		mc.lineTo( val.x+(val.width/2), val.y );
		
		x = val.x+(val.width/2)-x;
		y = val.y-y;
		
		var angle:Number = Math.atan(y/x)/(Math.PI/180);
    	if( x<0 )
		{
        	angle += 180;
    	}
    	if( x>=0 && y<0 )
		{
        	angle += 360;
    	}
		angle += 180;
		
		var r:Number=20;
		
		var radian = (angle+20) * Math.PI/180;
		var cos:Number = Math.cos(radian);
		var sin:Number = Math.sin(radian);

		var x_1 = Math.cos(radian)*r;
		var y_1 = Math.sin(radian)*r;
		
		var radian = (angle-20) * Math.PI/180;
		var cos:Number = Math.cos(radian);
		var sin:Number = Math.sin(radian);

		var x_2 = Math.cos(radian)*r;
		var y_2 = Math.sin(radian)*r;

		mc.lineStyle( 0, 0x0000E0, 100);
		//mc.beginFill( 0x0000E0, 100 );
		mc.moveTo( val.x+(val.width/2), val.y );
		mc.lineTo( val.x+(val.width/2)+x_1, val.y+y_1 );
		mc.lineTo( val.x+(val.width/2)+x_2, val.y+y_2 );
		mc.lineTo( val.x+(val.width/2), val.y );
		//mc.endFill();
		
		return mc;
	}
}
