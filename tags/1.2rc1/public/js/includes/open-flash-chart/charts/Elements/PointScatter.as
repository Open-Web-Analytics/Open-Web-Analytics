package charts.Elements {
	import flash.display.Sprite;
	import charts.Elements.Element;
	import caurina.transitions.Tweener;
	import caurina.transitions.Equations;
	
	public class PointScatter extends Element {
		public var radius:Number;
		
		public function PointScatter( style:Object ) {

			this._x = style.x;
			this._y = style.y;
			this.radius = style['dot-size'];
			this.is_tip = false;

			this.tooltip = this.replace_magic_values( style.tip );
			
			this.graphics.beginFill( style.colour, 1 );
			this.graphics.drawCircle( 0, 0, this.radius );
			this.graphics.drawCircle( 0, 0, this.radius - 1 );
			this.graphics.endFill();

		}
		
		private function replace_magic_values( t:String ): String {
			
			t = t.replace('#x#', NumberUtils.formatNumber(this._x));
			t = t.replace('#y#', NumberUtils.formatNumber(this._y));
			t = t.replace('#size#', NumberUtils.formatNumber(this.radius));
			return t;
		}
		
		public override function set_tip( b:Boolean ):void {
			if ( b )
			{
				if ( !this.is_tip )
				{
					Tweener.addTween(this, {scaleX:1.3, time:0.4, transition:"easeoutbounce"} );
					Tweener.addTween(this, {scaleY:1.3, time:0.4, transition:"easeoutbounce"} );
				}
				this.is_tip = true;
			}
			else
			{
				Tweener.removeTweens(this);
				this.scaleX = 1;
				this.scaleY = 1;
				this.is_tip = false;
			}
		}
		
		public override function resize( sc:ScreenCoordsBase, axis:Number ): void {
			//
			// Look: we have a real X value, so get its screen location:
			//
			this.x = sc.get_x_from_val( this._x );
			this.y = sc.get_y_from_val( this._y, (axis==2) );
		}
		
		//
		// is the mouse above, inside or below this point?
		//
		public override function inside( x:Number ):Boolean {
			return (x > (this.x-(this.radius/2))) && (x < (this.x+(this.radius/2)));
		}
	}
}