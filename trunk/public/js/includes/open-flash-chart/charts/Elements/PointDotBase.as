package charts.Elements {
	import flash.display.Sprite;
	import charts.Elements.Element;
	import flash.display.BlendMode;
	import flash.events.Event;
	import flash.events.MouseEvent;
	import flash.geom.Point;
	
	public class PointDotBase extends Element {
		
		protected var radius:Number;
		protected var colour:Number;
		
		public function PointDotBase( index:Number, style:Object ) {
			
			this.is_tip = false;
			this.visible = false;
			this.index = this._x = index;
			this._y = Number( style.value );
			
			this.radius = style['dot-size'];
			this.tooltip = this.replace_magic_values( style.tip );
			
			if ( style['on-click'] )
				this.set_on_click( style['on-click'] );
		}
		
		//
		// all dot share the same resize code:
		//
		public override function resize( sc:ScreenCoordsBase, axis:Number ):void {
			
			//
			// Haha! This is the worst code in the world,
			// but it is kinda kooky and cool at the same time :-)
			//
			var p:flash.geom.Point = sc.get_get_x_from_pos_and_y_from_val( this.index, this._y, (axis == 2) );
			this.x = this.line_mask.x = p.x;
			this.y = this.line_mask.y = p.y;
			
			// tr.ace(this.x );
		}
		
		public override function set_tip( b:Boolean ):void {
			//this.visible = b;
			if( b ) {
				this.scaleY = this.scaleX = 1.3;
				this.line_mask.scaleY = this.line_mask.scaleX = 1.3;
			}
			else {
				this.scaleY = this.scaleX = 1;
				this.line_mask.scaleY = this.line_mask.scaleX = 1;
			}
		}
		
		//
		// Dirty hack. Takes tooltip text, and replaces the #val# with the
		// tool_tip text, so noew you can do: "My Val = $#val#%", which turns into:
		// "My Val = $12.00%"
		//
		private function replace_magic_values( t:String ): String {
			
			t = t.replace('#val#', NumberUtils.formatNumber( this._y ));
			return t;
		}
		
		//
		// is the mouse above, inside or below this point?
		//
		public override function inside( x:Number ):Boolean {
			return (x > (this.x-(this.radius/2))) && (x < (this.x+(this.radius/2)));
		}
	}
}

