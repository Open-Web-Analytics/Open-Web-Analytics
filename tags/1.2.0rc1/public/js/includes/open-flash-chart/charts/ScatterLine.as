package charts {
	import flash.events.Event;
	import flash.events.MouseEvent;
	import charts.Elements.Element;
	import charts.Elements.PointScatter;
	import string.Utils;
	import flash.geom.Point;
	import flash.display.Sprite;
	
	public class ScatterLine extends ScatterBase
	{
		
		public function ScatterLine( json:Object )
		{
			this.style = {
				values:			[],
				width:			2,
				colour:			'#3030d0',
				text:			'',		// <-- default not display a key
				'dot-size':		5,
				'font-size':	12,
				tip:			'[#x#,#y#] #size#'
			};
			
			object_helper.merge_2( json, style );
			
			this.style.colour = string.Utils.get_colour( style.colour );
			
			this.line_width = style.width;
			this.colour		= this.style.colour;
			this.key		= style.text;
			this.font_size	= style['font-size'];
			this.circle_size = style['dot-size'];
			
			for each( var val:Object in style.values )
			{
				if( val['dot-size'] == null )
					val['dot-size'] = style['dot-size'];
			}
			
			this.values = style.values;

			this.add_values();
		}
		

		
		// Draw points...
		public override function resize( sc:ScreenCoordsBase ): void {
			
			// move the dots:
			super.resize( sc );
			
			this.graphics.clear();
			this.graphics.lineStyle( this.style.width, this.style.colour );
			
			//if( this.style['line-style'].style != 'solid' )
			//	this.dash_line(sc);
			//else
			this.solid_line(sc);
				
		}
		
		//
		// This is cut and paste from LineBase
		//
		public function solid_line( sc:ScreenCoordsBase ): void {
			
			var first:Boolean = true;
			
			for ( var i:Number = 0; i < this.numChildren; i++ ) {
				
				var tmp:Sprite = this.getChildAt(i) as Sprite;
				
				//
				// filter out the line masks
				//
				if( tmp is Element )
				{
					var e:Element = tmp as Element;
					
					// tr.ace(e.screen_x);
					
					// tell the point where it is on the screen
					// we will use this info to place the tooltip
					e.resize( sc, 0 );
					if( first )
					{
						this.graphics.moveTo(e.x,e.y);
						first = false;
					}
					else
						this.graphics.lineTo(e.x, e.y);
				}
			}
		}
		
	}
}