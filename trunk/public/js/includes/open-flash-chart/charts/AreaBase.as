package charts {
	import charts.Elements.Element;
	import charts.Elements.PointDotBase;
	import string.Utils;
	import flash.display.BlendMode;
	import flash.geom.Point;
	import flash.display.Sprite;
	
	
	public class AreaBase extends LineBase {
		
		public function AreaBase( json:Object ) {
			
			this.style = {
				values:			[],
				width:			2,
				colour:			'#3030d0',
				fill:			'#3030d0',
				text:			'',		// <-- default not display a key
				'dot-size':		5,
				'halo-size':	2,
				'font-size':	10,
				'fill-alpha':	0.6,
				tip:			'#val#',
				'line-style':	new LineStyle( json['line-style'] ),
				loop:			false		// <-- for radar charts
			};
			
			object_helper.merge_2( json, this.style );

			if( this.style.fill == '' )
				this.style.fill = this.style.colour;
				
			this.style.colour = string.Utils.get_colour( this.style.colour );
			this.style.fill = string.Utils.get_colour( this.style.fill );
			
			this.key = style.text;
			this.font_size = style['font-size'];
			this.values = style['values'];
			this.add_values();
			
			//
			// so the mask child can punch a hole through the line
			//
			this.blendMode = BlendMode.LAYER;
		}
		
		//
		// called from the base object
		//
		protected override function get_element( index:Number, value:Object ): Element {
			
			var s:Object = this.merge_us_with_value_object( value );
			return new charts.Elements.Point( index, s );
		}
		
		public override function resize(sc:ScreenCoordsBase):void {
			
			this.graphics.clear();
			// now draw the line + hollow dots
			super.resize(sc);
			
			var last:Element;
			var first:Boolean = true;
			var tmp:Sprite;
			
			for ( var i:Number = 0; i < this.numChildren; i++ ) {
				
				tmp = this.getChildAt(i) as Sprite;
				
				// filter out the masks
				if( tmp is Element ) {
					
					var e:Element = tmp as Element;
					tr.ace(e.index);
					tr.ace(e.x + ', ' + e.y);
					if( first )
					{
						
						first = false;
						
						if (this.style.loop)
						{
							// assume we are in a radar chart
							this.graphics.moveTo( e.x, e.y );
						}
						else
						{
							// draw line from Y=0 up to Y pos
							this.graphics.moveTo( e.x, sc.get_y_bottom(false) );
						}
						
						//
						// TO FIX BUG: you must do a graphics.moveTo before
						//             starting a fill:
						//
						this.graphics.lineStyle(0,0,0);
						this.graphics.beginFill( this.style.fill, this.style['fill-alpha'] );
						
						if (!this.style.loop)
							this.graphics.lineTo( e.x, e.y );
						
					}
					else
					{
						this.graphics.lineTo( e.x, e.y );
						last = e;
					}
				}
			}
			
			if ( last != null ) {
				if ( !this.style.loop) {
					this.graphics.lineTo( last.x, sc.get_y_bottom(false) );
				}
			}
			

			this.graphics.endFill();
		}
	}
}