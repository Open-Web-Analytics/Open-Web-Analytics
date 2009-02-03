package {
	import flash.geom.Point;

	public class ScreenCoordsRadar extends ScreenCoordsBase
	{
		private var TO_RADIANS:Number = Math.PI / 180;
		private var max:Number;
		private var angles:Number;
		private var angle:Number;
		private var radius:Number;
		
		public function ScreenCoordsRadar( top:Number, left:Number, right:Number, bottom:Number ) {
			
			super(top, left, right, bottom);
			
			//
			// if the radar chart has labels this is going to
			// get updated so they fit onto the screen
			//
			this.radius = ( Math.min( this.width, this.height ) / 2.0 );
		}
		
		public function set_max( max:Number ): void {
			this.max = max;
		}
		
		public function get_max():Number {
			return this.max;
		}
		
		public function set_angles( a:Number ):void {
			this.angles = a;
			this.angle = 360 / a;
		}
		
		public function get_angles():Number {
			return this.angles;
		}
		
		public function get_radius():Number {
			
			return this.radius;
		}
		
		public function reduce_radius():void {
			this.radius--;
		}
		
		public function get_pos( angle:Number, radius:Number ): flash.geom.Point {
			
			// flash assumes 0 degrees is horizontal to the right
			var a:Number = (angle - 90) * TO_RADIANS;
			var r:Number = this.get_radius() * radius;
			
			var p:flash.geom.Point = new flash.geom.Point(
				r * Math.cos(a),
				r * Math.sin(a) );
				
			return p;
		}
		
		public override function get_get_x_from_pos_and_y_from_val( index:Number, y:Number, right_axis:Boolean = false ):flash.geom.Point {
			
			// rotate
			var p:flash.geom.Point = this.get_pos( this.angle*index, y / this.max );
			
			// translate
			p.x += this.get_center_x();
			p.y += this.get_center_y();
			
			return p;
		}
		
		public override function get_y_from_val( y:Number, right_axis:Boolean = false ):Number {
			
			// rotate
			var p:flash.geom.Point = this.get_pos( 0, y / this.max );
			
			// translate
			p.y += this.get_center_y();
			
			return p.y;
		}
	}
}