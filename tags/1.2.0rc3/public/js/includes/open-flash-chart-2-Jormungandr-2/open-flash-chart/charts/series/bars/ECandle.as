package charts.series.bars {
	
	import flash.display.Sprite;
	import flash.geom.Point;
	import charts.series.bars.Base;
	
	public class ECandle extends Base {
		protected var high:Number;
		protected var low:Number;

		
		public function ECandle( index:Number, props:Properties, group:Number ) {
			
			super(index, props, group);
			//super(index, {'top':props.get('top')}, props.get_colour('colour'), props.get('tip'), props.get('alpha'), group);
			//super(index, style, style.colour, style.tip, style.alpha, group);
		}
		
		//
		// a candle chart has many values used to display each point
		//
		protected override function parse_value( props:Properties ):void {
			
			// set top (open) and bottom (close)
			super.parse_value( props );
			this.high = props.get('high');
			this.low = props.get('low');
		}
		
		protected override function replace_magic_values( t:String ): String {
			
			t = super.replace_magic_values( t );
			t = t.replace('#high#', NumberUtils.formatNumber( this.high ));
			t = t.replace('#open#', NumberUtils.formatNumber( this.top ));
			t = t.replace('#close#', NumberUtils.formatNumber( this.bottom ));
			t = t.replace('#low#', NumberUtils.formatNumber( this.low ));
			
			return t;
		}
		
		public override function resize( sc:ScreenCoordsBase ):void {
			
			var s:ScreenCoords = sc as ScreenCoords;
			var tmp:Object = s.get_bar_coords( this.index, this.group );

			// 
			var bar_high:Number		= sc.get_y_from_val(this.high, this.right_axis);
			var bar_top:Number		= sc.get_y_from_val(this.top, this.right_axis);
			var bar_bottom:Number	= sc.get_y_from_val(this.bottom, this.right_axis);
			var bar_low:Number		= sc.get_y_from_val(this.low, this.right_axis);
			
			var top:Number;
			var height:Number;
			var upside_down:Boolean = false;
			
			if( bar_bottom < bar_top ) {
				upside_down = true;
			}
			
			height = Math.abs( bar_bottom - bar_top );
			
			//
			// move the Sprite to the correct screen location:
			//
			this.y = bar_high;
			this.x = tmp.x;
			
			//
			// tell the tooltip where to show its self
			//
			this.tip_pos = new flash.geom.Point( this.x + (tmp.width / 2), this.y );
			
			var mid:Number = tmp.width / 2;
			this.graphics.clear();
			// top line
			this.graphics.beginFill( this.colour, 1.0 );
			this.graphics.moveTo( mid-1, 0 );
			this.graphics.lineTo( mid+1, 0 );
			this.graphics.lineTo( mid+1, bar_top-bar_high );
			this.graphics.lineTo( mid-1, bar_top-bar_high );
			this.graphics.endFill();
			
			// box
			this.graphics.beginFill( this.colour, 1.0 );
			this.graphics.moveTo( 0, bar_top-bar_high );
			this.graphics.lineTo( tmp.width, bar_top-bar_high );
			this.graphics.lineTo( tmp.width, bar_bottom-bar_high );
			this.graphics.lineTo( 0, bar_bottom-bar_high );
			this.graphics.endFill();
			
			// top line
			this.graphics.beginFill( this.colour, 1.0 );
			this.graphics.moveTo( mid-1, bar_bottom-bar_high );
			this.graphics.lineTo( mid+1, bar_bottom-bar_high );
			this.graphics.lineTo( mid+1, bar_low-bar_high );
			this.graphics.lineTo( mid-1, bar_low-bar_high );
			this.graphics.endFill();
			
		}
			
	}
}