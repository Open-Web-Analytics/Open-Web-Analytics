package charts.Elements {

	import flash.events.Event;
	import flash.events.MouseEvent;
	import flash.display.Sprite;
	import caurina.transitions.Tweener;
	import caurina.transitions.Equations;
	import flash.geom.Point;
	import global.Global;
	
	public class PointBarBase extends Element
	{
		protected var tip_pos:flash.geom.Point;
		protected var colour:Number;
		protected var group:Number;
		protected var top:Number;
		protected var bottom:Number;
		
		public function PointBarBase( index:Number, value:Object, colour:Number, tooltip:String, group:Number )
		{
			super();
			this.index = index;
			this.parse_value(value);
			this.colour = colour;
			this.tooltip = this.replace_magic_values( tooltip );
			
			this.group = group;
			this.visible = true;
			
			this.alpha = 0.5;
			
			this.addEventListener(MouseEvent.MOUSE_OVER, this.mouseOver);
			this.addEventListener(MouseEvent.MOUSE_OUT, this.mouseOut);
		}
		
		//
		// most line and bar charts have a single value which is the
		// Y position, some like candle and scatter have many values
		// and will override this method to parse their value
		//
		protected function parse_value( value:Object ):void {
			
			if( value is Number )
			{
				this.top = value as Number;
				this.bottom = Number.MIN_VALUE;		// <-- align to Y min OR zero
			}
			else
			{
				this.top = value.top;
				
				if( value.bottom == null )
					this.bottom = Number.MIN_VALUE;	// <-- align to Y min OR zero
				else
					this.bottom = value.bottom;
			}
		}
		
		protected function replace_magic_values( t:String ): String {
			
			t = t.replace('#top#', NumberUtils.formatNumber( this.top ));
			t = t.replace('#bottom#', NumberUtils.formatNumber( this.bottom ));
			t = t.replace('#val#', NumberUtils.formatNumber( this.top - this.bottom ));
			
			return t;
		}
		
		
		//
		// for tooltip closest - return the middle point
		//
		public override function get_mid_point():flash.geom.Point {
			
			//
			// bars mid point
			//
			return new flash.geom.Point( this.x + (this.width/2), this.y );
		}
		
		public override function mouseOver(event:Event):void {
			this.is_tip = true;
			Tweener.addTween(this, { alpha:1, time:0.6, transition:Equations.easeOutCirc } );
		}

		public override function mouseOut(event:Event):void {
			this.is_tip = false;
			Tweener.addTween(this, { alpha:0.5, time:0.8, transition:Equations.easeOutElastic } );
		}
		
		// override this:
		public override function resize( sc:ScreenCoordsBase, axis:Number ):void {}
		
		//
		// tooltip.left for bars center over the bar
		//
		public override function get_tip_pos(): Object {
			return {x:this.tip_pos.x, y:this.tip_pos.y };
		}
		
		//
		// is the mouse above, inside or below this bar?
		//
		public override function inside( x:Number ):Boolean {
			return (x > this.x) && (x < this.x + this.width);
		}
		
		//
		// Called by most of the bar charts.
		// Moves the Sprite into the correct position, then
		// returns the bounds so the bar can draw its self.
		//
		protected function resize_helper( sc:ScreenCoords, axis:Number ):Object {
			var tmp:Object = sc.get_bar_coords( this.index, this.group );

			var bar_top:Number = sc.get_y_from_val(this.top,axis==2);
			var bar_bottom:Number;
			
			if( this.bottom == Number.MIN_VALUE )
				bar_bottom = sc.get_y_bottom(axis == 2);
			else
				bar_bottom = sc.get_y_from_val(this.bottom, axis == 2);
			
			var top:Number;
			var height:Number;
			var upside_down:Boolean = false;
			
			if( bar_bottom < bar_top ) {
				top = bar_bottom;
				upside_down = true;
			}
			else
			{
				top = bar_top;
			}
			
			height = Math.abs( bar_bottom - bar_top );
			
			//
			// move the Sprite to the correct screen location:
			//
			this.y = top;
			this.x = tmp.x;
			
			//
			// tell the tooltip where to show its self
			//
			this.tip_pos = new flash.geom.Point( this.x + (tmp.width / 2), this.y );
			
			//
			// return the bounds to draw the item:
			//
			return { width:tmp.width, top:top, height:height, upside_down:upside_down };
		}
	}
}